<?php

namespace App\Actions\Finance;

use App\Actions\Enrollment\RegistrationPlacementValidator;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordAuthorizedIndividualAssessment
{
    public function __construct(
        private readonly CreateAssessmentFromPublishedFeePlan $creator,
        private readonly RegistrationPlacementValidator $placementValidator,
    ) {}

    /**
     * @param  list<array{code:string,label:string,amount:string}>  $charges
     * @param  list<array{code:string,label:string,purpose:string,amount:string,due_at:string,required_for_enrollment?:bool}>  $obligations
     */
    public function execute(Enrollment $enrollment, User $actor, string $category, string $authorityReference, CarbonImmutable $authorityDate, array $charges, array $obligations): Assessment
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only Accounting staff may record an authorized individual assessment.');
        }
        if (! in_array($category, Assessment::IndividualCategories, true) || blank($authorityReference) || $charges === [] || $obligations === []) {
            throw ValidationException::withMessages(['assessment' => 'Exact authority and fixed charges are required.']);
        }

        $normalizedCharges = collect($charges)->map(function (array $charge): array {
            if (blank($charge['code']) || blank($charge['label']) || (float) $charge['amount'] < 0) {
                throw ValidationException::withMessages(['charges' => 'Each authorized line requires a code, label, and non-negative fixed amount.']);
            }

            return ['code' => $charge['code'], 'label' => $charge['label'], 'amount' => number_format((float) $charge['amount'], 2, '.', '')];
        })->values()->all();
        $normalizedObligations = collect($obligations)->map(function (array $obligation, int $index): array {
            if (blank($obligation['code']) || blank($obligation['label']) || blank($obligation['purpose']) || blank($obligation['due_at']) || (float) $obligation['amount'] < 0) {
                throw ValidationException::withMessages(['obligations' => 'Each obligation requires an exact purpose, due time, and amount.']);
            }

            return ['sequence' => $index + 1, 'code' => $obligation['code'], 'label' => $obligation['label'], 'purpose' => $obligation['purpose'], 'amount' => number_format((float) $obligation['amount'], 2, '.', ''), 'due_at' => $obligation['due_at'], 'required_for_enrollment' => $obligation['required_for_enrollment'] ?? false];
        })->values()->all();
        if (number_format((float) collect($normalizedCharges)->sum('amount'), 2, '.', '') !== number_format((float) collect($normalizedObligations)->sum('amount'), 2, '.', '')) {
            throw ValidationException::withMessages(['assessment' => 'Individual charges and obligations must reconcile exactly.']);
        }

        return DB::transaction(function () use ($enrollment, $actor, $category, $authorityReference, $authorityDate, $normalizedCharges, $normalizedObligations): Assessment {
            $locked = Enrollment::query()
                ->with(['term', 'currentProposalVersion.items.reservation'])
                ->whereKey($enrollment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->canonical_outcome !== Enrollment::OutcomeOfficiallyEnrolled
                && $locked->selection_basis !== Enrollment::SelectionIndividuallyAdvised
                && $locked->term?->type !== Term::TypeSummer) {
                throw ValidationException::withMessages([
                    'assessment' => 'An individual assessment requires an exact Individually Advised or Summer/Special Term exception.',
                ]);
            }

            if ($locked->currentProposalVersion === null) {
                throw ValidationException::withMessages(['assessment' => 'A current confirmed and placed proposal is required.']);
            }

            if ($locked->canonical_outcome !== Enrollment::OutcomeOfficiallyEnrolled) {
                $this->placementValidator->assertCurrent($locked, $locked->currentProposalVersion, lockForUpdate: true);
            }

            return $this->creator->create(
                $locked,
                $actor,
                'AuthorizedIndividualAssessment',
                $category,
                $authorityReference,
                $authorityDate,
                null,
                $normalizedCharges,
                $normalizedObligations,
            );
        }, attempts: 3);
    }
}
