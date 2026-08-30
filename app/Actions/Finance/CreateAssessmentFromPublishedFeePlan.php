<?php

namespace App\Actions\Finance;

use App\Actions\Enrollment\RegistrationNotificationLedger;
use App\Actions\Enrollment\RegistrationPlacementValidator;
use App\Models\Assessment;
use App\Models\AssessmentLine;
use App\Models\AssessmentObligation;
use App\Models\Enrollment;
use App\Models\FeePlan;
use App\Models\TermAccount;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateAssessmentFromPublishedFeePlan
{
    public function __construct(
        private readonly RegistrationPlacementValidator $placementValidator,
        private readonly DecimalMoney $money,
        private readonly RegistrationNotificationLedger $notifications,
        private readonly EnrollmentPaymentRequirementProjection $paymentRequirement,
    ) {}

    public function execute(Enrollment $enrollment, User $actor): Assessment
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only Accounting staff may create an enrollment assessment.');
        }

        return DB::transaction(function () use ($enrollment, $actor): Assessment {
            $locked = Enrollment::query()->with(['admissionApplication', 'studentProfile', 'currentProposalVersion'])->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $programId = $locked->admissionApplication()->value('program_id')
                ?? $locked->studentProfile()->value('program_id');

            if ($programId === null || $locked->currentProposalVersion === null) {
                throw ValidationException::withMessages(['assessment' => 'Authoritative Program and current confirmed proposal are required.']);
            }

            $this->placementValidator->assertCurrent($locked, $locked->currentProposalVersion, lockForUpdate: true);

            $feePlan = FeePlan::query()
                ->with(['charges', 'obligations'])
                ->where('program_id', $programId)
                ->where('term_id', $locked->term_id)
                ->where('state', FeePlan::StatePublished)
                ->latest('version')
                ->lockForUpdate()
                ->first();

            if (! $feePlan instanceof FeePlan) {
                throw ValidationException::withMessages(['assessment' => 'Unavailable: publish the exact Program-and-Term Fee Plan.']);
            }

            if ($feePlan->authority_date === null || $feePlan->obligations->contains(fn ($obligation): bool => $obligation->sequence === null || blank($obligation->purpose) || $obligation->due_at === null)) {
                throw ValidationException::withMessages(['assessment' => 'Unavailable: the published Fee Plan lacks complete dated obligation authority; publish a successor.']);
            }

            return $this->create(
                $locked, $actor, 'PublishedFeePlan', null, $feePlan->authority_reference,
                CarbonImmutable::parse($feePlan->authority_date), $feePlan,
                $feePlan->charges->map->only(['id', 'code', 'label', 'category', 'amount'])->all(),
                $feePlan->obligations->map->only(['sequence', 'code', 'label', 'purpose', 'amount', 'due_at', 'required_for_enrollment'])->all(),
            );
        }, attempts: 3);
    }

    /**
     * @param  list<array{id?:int,code:string,label:string,category?:string,amount:string}>  $charges
     * @param  list<array{sequence?:int,code:string,label:string,purpose:string,amount:string,due_at:mixed,required_for_enrollment:bool}>  $obligations
     */
    public function create(Enrollment $enrollment, User $actor, string $basis, ?string $reasonCategory, string $authorityReference, CarbonImmutable $authorityDate, ?FeePlan $feePlan, array $charges, array $obligations): Assessment
    {
        $account = TermAccount::query()->firstOrCreate(
            ['enrollment_id' => $enrollment->id],
            ['credential_user_id' => $enrollment->credential_user_id, 'term_id' => $enrollment->term_id, 'state' => TermAccount::StateOpen],
        );
        $total = '0.00';
        foreach ($charges as $charge) {
            $total = $this->money->add($total, $charge['amount']);
        }
        $source = ['basis' => $basis, 'reason_category' => $reasonCategory, 'authority_reference' => $authorityReference, 'authority_date' => $authorityDate->toDateString(), 'fee_plan_id' => $feePlan?->id, 'proposal_id' => $enrollment->current_proposal_version_id, 'charges' => $charges, 'obligations' => $obligations];
        $contentHash = hash('sha256', json_encode($source, JSON_THROW_ON_ERROR));
        $existing = Assessment::query()
            ->where('term_account_id', $account->id)
            ->where('state', Assessment::StateActive)
            ->lockForUpdate()
            ->first();

        if ($existing instanceof Assessment && hash_equals((string) $existing->content_hash, $contentHash)) {
            $existing = $existing->load(['lines', 'obligations', 'termAccount']);
            $this->notifyIfActionRequired($existing);

            return $existing;
        }

        $version = ((int) Assessment::query()->where('enrollment_id', $enrollment->id)->lockForUpdate()->max('version')) + 1;
        $assessment = Assessment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_account_id' => $account->id,
            'fee_plan_id' => $feePlan?->id,
            'source_proposal_version_id' => $enrollment->current_proposal_version_id,
            'assessment_basis' => $basis,
            'reason_category' => $reasonCategory,
            'authority_reference' => $authorityReference,
            'authority_date' => $authorityDate->toDateString(),
            'source_snapshot' => $source,
            'content_hash' => $contentHash,
            'version' => $version,
            'state' => Assessment::StateActive,
            'currency' => 'PHP',
            'subtotal' => $total,
            'discount_total' => '0.00',
            'total' => $total,
            'required_downpayment' => '0.00',
            'activated_by' => $actor->id,
            'activated_at' => now(),
        ]);

        foreach ($charges as $index => $charge) {
            AssessmentLine::query()->create([
                'assessment_id' => $assessment->id,
                'fee_rule_id' => null,
                'fee_plan_charge_id' => $charge['id'] ?? null,
                'course_enrollment_id' => null,
                'source_line_key' => $charge['code'],
                'obligation_code' => $charge['code'],
                'description_snapshot' => $charge['label'],
                'quantity' => '1.0000',
                'rate' => $charge['amount'],
                'amount' => $charge['amount'],
                'line_type' => 'fixed',
            ]);
        }
        foreach ($obligations as $index => $obligation) {
            AssessmentObligation::query()->create(['assessment_id' => $assessment->id, 'sequence' => $obligation['sequence'] ?? $index + 1, ...$obligation]);
        }

        if ($existing instanceof Assessment) {
            $existing->update([
                'state' => Assessment::StateSuperseded,
                'superseded_by_assessment_id' => $assessment->id,
            ]);
        }
        $account->update(['state' => TermAccount::StateOpen]);

        $assessment = $assessment->load(['lines', 'obligations', 'termAccount']);
        $this->notifyIfActionRequired($assessment);

        return $assessment;
    }

    private function notifyIfActionRequired(Assessment $assessment): void
    {
        $assessment->loadMissing('enrollment');
        if ($this->paymentRequirement->forEnrollment($assessment->enrollment)['state'] === 'ActionNeeded') {
            $this->notifications->recordPaymentActionRequired($assessment);
        }
    }
}
