<?php

namespace App\Actions\Finance;

use App\Actions\Enrollment\RegistrationPlacementValidator;
use App\Models\Assessment;
use App\Models\CorVersion;
use App\Models\CourseDropRecord;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\RegistrationProposalVersion;
use App\Models\Term;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecordAuthorizedIndividualAssessment
{
    public function __construct(
        private readonly CreateAssessmentFromPublishedFeePlan $creator,
        private readonly RegistrationPlacementValidator $placementValidator,
        private readonly DecimalMoney $money,
    ) {}

    /**
     * @param  list<array{code:string,label:string,amount:string}>  $charges
     * @param  list<array{code:string,label:string,purpose:string,amount:string,due_at:string,required_for_enrollment?:bool}>  $obligations
     */
    public function execute(Enrollment $enrollment, User $actor, string $category, string $authorityReference, CarbonImmutable $authorityDate, array $charges, array $obligations, ?string $noPaymentRequiredAuthorityReference = null, ?CourseDropRecord $courseDrop = null, ?int $expectedProposalVersionId = null): Assessment
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only Accounting staff may record an authorized individual assessment.');
        }
        if (! in_array($category, Assessment::IndividualCategories, true) || blank($authorityReference) || $charges === [] || $obligations === []) {
            throw ValidationException::withMessages(['assessment' => 'Exact authority and fixed charges are required.']);
        }
        Validator::make(['charges' => $charges, 'obligations' => $obligations], [
            'charges' => ['required', 'array', 'min:1'], 'charges.*.code' => ['required', 'string', 'max:255', 'distinct'],
            'charges.*.label' => ['required', 'string', 'max:255'], 'charges.*.amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'obligations' => ['required', 'array', 'min:1'], 'obligations.*.code' => ['required', 'string', 'max:255', 'distinct'],
            'obligations.*.label' => ['required', 'string', 'max:255'], 'obligations.*.purpose' => ['required', 'string', 'max:255'],
            'obligations.*.amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'], 'obligations.*.due_at' => ['required', 'date'],
            'obligations.*.required_for_enrollment' => ['sometimes', 'boolean'],
        ])->validate();

        $normalizedCharges = collect($charges)->map(function (array $charge): array {
            if (blank($charge['code']) || blank($charge['label']) || (float) $charge['amount'] < 0) {
                throw ValidationException::withMessages(['charges' => 'Each authorized line requires a code, label, and non-negative fixed amount.']);
            }

            return ['code' => $charge['code'], 'label' => $charge['label'], 'amount' => $this->money->normalize($charge['amount'])];
        })->values()->all();
        $normalizedObligations = collect($obligations)->map(function (array $obligation, int $index): array {
            if (blank($obligation['code']) || blank($obligation['label']) || blank($obligation['purpose']) || blank($obligation['due_at']) || (float) $obligation['amount'] < 0) {
                throw ValidationException::withMessages(['obligations' => 'Each obligation requires an exact purpose, due time, and amount.']);
            }

            return ['sequence' => $index + 1, 'code' => $obligation['code'], 'label' => $obligation['label'], 'purpose' => $obligation['purpose'], 'amount' => $this->money->normalize($obligation['amount']), 'due_at' => $obligation['due_at'], 'required_for_enrollment' => (bool) ($obligation['required_for_enrollment'] ?? false)];
        })->values()->all();
        if (collect($normalizedCharges)->sum(fn (array $row): int => $this->money->toCents($row['amount'])) !== collect($normalizedObligations)->sum(fn (array $row): int => $this->money->toCents($row['amount']))) {
            throw ValidationException::withMessages(['assessment' => 'Individual charges and obligations must reconcile exactly.']);
        }
        $required = collect($normalizedObligations)->where('required_for_enrollment', true);
        if ($required->count() !== 1 || ! $normalizedObligations[0]['required_for_enrollment']
            || ($this->money->toCents($required->first()['amount']) === 0 && blank($noPaymentRequiredAuthorityReference))) {
            throw ValidationException::withMessages(['obligations' => 'Name the first enrollment obligation and record explicit institutional authority when no enrollment payment is required.']);
        }

        return DB::transaction(function () use ($enrollment, $actor, $category, $authorityReference, $authorityDate, $normalizedCharges, $normalizedObligations, $noPaymentRequiredAuthorityReference, $courseDrop, $expectedProposalVersionId): Assessment {
            $locked = Enrollment::query()
                ->with(['term', 'currentProposalVersion.items.reservation', 'currentProposalVersion.confirmation'])
                ->whereKey($enrollment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($expectedProposalVersionId !== null && (int) $locked->current_proposal_version_id !== $expectedProposalVersionId) {
                throw ValidationException::withMessages([
                    'assessment' => 'The proposal changed after this assessment was opened. Review the current subjects before recording its charges.',
                ]);
            }

            if ($locked->canonical_outcome !== Enrollment::OutcomeOfficiallyEnrolled
                && $locked->selection_basis !== Enrollment::SelectionIndividuallyAdvised
                && $locked->term?->type !== Term::TypeSpecialTerm) {
                throw ValidationException::withMessages([
                    'assessment' => 'An individual assessment requires an exact Individually Advised or Special Term exception.',
                ]);
            }

            if ($locked->currentProposalVersion === null) {
                throw ValidationException::withMessages(['assessment' => 'A current reserved proposal is required.']);
            }

            $dropSource = null;
            if ($courseDrop instanceof CourseDropRecord) {
                $dropSource = $this->lockCourseDropSource($locked, $courseDrop, $category);
            } elseif ($locked->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled) {
                $proposal = $locked->currentProposalVersion;
                $cor = CorVersion::query()->whereKey($locked->current_cor_version_id)->lockForUpdate()->first();
                if ($cor !== null && (int) $cor->enrollment_id === (int) $locked->id
                    && (int) $cor->registration_proposal_version_id === (int) $proposal->id
                    && data_get($cor->snapshot, 'change.type') === 'CourseDrop') {
                    throw ValidationException::withMessages([
                        'assessment' => 'This proposal has a recorded Course Drop. Assess its explicit drop source and remaining issued courses instead of the prior proposal subjects.',
                    ]);
                }
                if ($category !== Assessment::CategoryAuthorizedAdjustment
                    || (int) $proposal->enrollment_id !== (int) $locked->id
                    || $proposal->purpose !== RegistrationProposalVersion::PurposeAdjustment
                    || $proposal->state !== RegistrationProposalVersion::StateConfirmed
                    || $proposal->confirmation === null) {
                    throw ValidationException::withMessages([
                        'assessment' => 'A post-enrollment individual assessment requires the exact current confirmed adjustment proposal and Authorized Adjustment authority.',
                    ]);
                }
            } else {
                $categoryMatchesSource = match ($category) {
                    Assessment::CategorySpecialTerm => $locked->term?->type === Term::TypeSpecialTerm,
                    Assessment::CategoryIndividuallyAdvised, Assessment::CategoryReducedEnrollment => $locked->selection_basis === Enrollment::SelectionIndividuallyAdvised,
                    default => false,
                };
                if (! $categoryMatchesSource) {
                    throw ValidationException::withMessages(['assessment' => 'The individual assessment category must match this proposal’s Special Term or Individually Advised source.']);
                }
                $this->placementValidator->assertCurrent($locked, $locked->currentProposalVersion, lockForUpdate: true, requireConfirmation: false);
            }

            $assessment = $this->creator->create(
                $locked,
                $actor,
                'AuthorizedIndividualAssessment',
                $category,
                $authorityReference,
                $authorityDate,
                null,
                $normalizedCharges,
                $normalizedObligations,
                $noPaymentRequiredAuthorityReference,
                $dropSource,
            );
            $dropSource?->update(['finance_state' => 'AssessmentRecorded']);

            return $assessment;
        }, attempts: 3);
    }

    private function lockCourseDropSource(Enrollment $enrollment, CourseDropRecord $courseDrop, string $category): CourseDropRecord
    {
        $drop = CourseDropRecord::query()->whereKey($courseDrop->id)->lockForUpdate()->firstOrFail();
        $course = CourseEnrollment::query()->whereKey($drop->course_enrollment_id)->lockForUpdate()->first();
        $cor = CorVersion::query()->whereKey($enrollment->current_cor_version_id)->lockForUpdate()->first();

        if ($enrollment->canonical_outcome !== Enrollment::OutcomeOfficiallyEnrolled
            || $category !== Assessment::CategoryAuthorizedAdjustment
            || (int) $drop->enrollment_id !== (int) $enrollment->id
            || ! $course instanceof CourseEnrollment
            || (int) $course->enrollment_id !== (int) $enrollment->id
            || $course->is_current || $course->status !== CourseEnrollment::StatusDropped
            || $drop->recorded_at === null
            || ! $cor instanceof CorVersion || (int) $cor->enrollment_id !== (int) $enrollment->id
            || (int) $cor->registration_proposal_version_id !== (int) $enrollment->current_proposal_version_id
            || (int) $enrollment->currentProposalVersion->enrollment_id !== (int) $enrollment->id
            || data_get($cor->snapshot, 'change.type') !== 'CourseDrop'
            || (int) data_get($cor->snapshot, 'change.record_id') !== (int) $drop->id
            || blank(data_get($cor->snapshot, 'program_id'))
            || empty($cor->snapshot['courses'])
            || collect($cor->snapshot['courses'])->contains(fn (array $row): bool => (int) $row['course_enrollment_id'] === (int) $course->id)) {
            throw ValidationException::withMessages([
                'assessment' => 'A Course Drop assessment requires this case’s recorded drop, its current issued COR, and Authorized Adjustment authority without a newer proposal.',
            ]);
        }

        $enrollment->setRelation('currentCorVersion', $cor);

        return $drop;
    }
}
