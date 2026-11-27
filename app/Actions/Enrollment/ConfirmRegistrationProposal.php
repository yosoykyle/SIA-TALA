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
        bool $automatic = false,
    ): RegistrationProposalVersion {
        $proposal->loadMissing('enrollment');

        if ($automatic && $assistedBy !== null) {
            throw new AuthorizationException('An automatic confirmation cannot also be Registrar-assisted.');
        }

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

        return DB::transaction(function () use ($proposal, $learner, $assistedBy, $assistedEvidenceReference, $automatic): RegistrationProposalVersion {
            $enrollment = Enrollment::query()->whereKey($proposal->enrollment_id)->lockForUpdate()->firstOrFail();
            $locked = RegistrationProposalVersion::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            // A proposal TALA already confirmed (and may have finalized) stays confirmed; asking again is a no-op (#110).
            if ($locked->state === RegistrationProposalVersion::StateConfirmed
                && (int) $enrollment->current_proposal_version_id === (int) $locked->id
                && $enrollment->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled
                && $locked->confirmation()->exists()) {
                return $locked->load('confirmation');
            }

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
                'method' => match (true) {
                    $automatic => RegistrationProposalConfirmation::MethodAutomatic,
                    $assistedBy !== null => RegistrationProposalConfirmation::MethodRegistrarAssisted,
                    default => RegistrationProposalConfirmation::MethodSelfService,
                },
                'learner_user_id' => $learner->id,
                'assisted_by' => $assistedBy?->id,
                'assisted_evidence_reference' => $assistedEvidenceReference,
                'confirmed_at' => now(),
            ]);
            $locked->update(['state' => RegistrationProposalVersion::StateConfirmed]);

            $assessment = $enrollment->termAccount?->assessments()->where('state', Assessment::StateActive)->latest('version')->first();
            $requirement = app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($enrollment);
            if ($assessment !== null && $requirement['state'] === 'ActionNeeded') {
                app(RegistrationNotificationLedger::class)->recordPaymentActionRequired($assessment);
            }

            if ($locked->purpose === RegistrationProposalVersion::PurposeInitial
                && $enrollment->canonical_outcome === Enrollment::OutcomeInProgress) {
                $enrollmentId = (int) $enrollment->id;
                $proposalId = (int) $locked->id;
                $actorId = $assistedBy?->id ?? $learner->id;

                DB::afterCommit(function () use ($enrollmentId, $proposalId, $actorId): void {
                    app(AttemptAutoOfficialEnrollment::class)->attempt(
                        $enrollmentId,
                        new AutoFinalizationTrigger(
                            triggerType: AutoFinalizationTrigger::TypeProposalConfirmed,
                            triggerReference: 'proposal:'.$proposalId,
                            clearedByUserId: $actorId,
                            paymentId: null,
                            paymentSummary: null,
                        ),
                    );
                });
            }

            return $locked->refresh()->load('confirmation');
        }, attempts: 3);
    }
}
