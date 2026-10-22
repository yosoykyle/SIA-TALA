<?php

namespace App\Actions\Enrollment;

use App\Actions\Finance\EnrollmentPaymentRequirementProjection;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\RegistrationProposalConfirmation;
use App\Models\RegistrationProposalVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmRegistrationProposal
{
    public function __construct(private readonly RegistrationReadinessQuery $readiness) {}

    public function execute(
        RegistrationProposalVersion $proposal,
        User $learner,
        ?User $assistedBy = null,
        ?string $assistedEvidenceReference = null,
    ): RegistrationProposalVersion {
        $proposal->loadMissing('enrollment');

        if (! $learner->canAuthenticate()) {
            throw new AuthorizationException('Only an active learner may confirm this proposal.');
        }

        if ((int) $proposal->enrollment->credential_user_id !== (int) $learner->id) {
            throw new AuthorizationException('Only the owning learner may confirm this proposal.');
        }

        if ($assistedBy !== null && (! $assistedBy->canAuthenticate()
            || ! $assistedBy->hasRole(User::StaffRoleRegistrar))) {
            throw new AuthorizationException('Assisted confirmation requires authorized Registrar staff.');
        }

        return DB::transaction(function () use ($proposal, $learner, $assistedBy, $assistedEvidenceReference): RegistrationProposalVersion {
            $enrollment = Enrollment::query()->whereKey($proposal->enrollment_id)->lockForUpdate()->firstOrFail();
            $locked = RegistrationProposalVersion::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            $expectedOutcome = $locked->purpose === RegistrationProposalVersion::PurposeAdjustment
                ? Enrollment::OutcomeOfficiallyEnrolled
                : Enrollment::OutcomeInProgress;
            if ($enrollment->canonical_outcome !== $expectedOutcome
                || (int) $enrollment->current_proposal_version_id !== (int) $locked->id
                || ! in_array($locked->state, [RegistrationProposalVersion::StateIssued, RegistrationProposalVersion::StateConfirmed], true)) {
                throw ValidationException::withMessages(['proposal' => 'Only an issued current proposal may be confirmed.']);
            }

            if ($locked->purpose === RegistrationProposalVersion::PurposeInitial) {
                $this->readiness->assertPrepared($enrollment, $locked);
            }

            if ($locked->confirmation()->exists()) {
                return $locked->load('confirmation');
            }

            RegistrationProposalConfirmation::query()->create([
                'registration_proposal_version_id' => $locked->id,
                'method' => $assistedBy === null ? RegistrationProposalConfirmation::MethodSelfService : RegistrationProposalConfirmation::MethodRegistrarAssisted,
                'learner_user_id' => $learner->id,
                'assisted_by' => $assistedBy?->id,
                'assisted_evidence_reference' => $assistedEvidenceReference,
                'confirmed_at' => now(),
            ]);
            $locked->update(['state' => RegistrationProposalVersion::StateConfirmed]);

            $assessment = $enrollment->termAccount?->assessments()->where('state', Assessment::StateActive)->latest('version')->first();
            if ($assessment !== null && app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($enrollment)['state'] === 'ActionNeeded') {
                app(RegistrationNotificationLedger::class)->recordPaymentActionRequired($assessment);
            }

            return $locked->refresh()->load('confirmation');
        }, attempts: 3);
    }
}
