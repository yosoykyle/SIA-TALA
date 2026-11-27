<?php

namespace App\Actions\Enrollment;

use App\Models\Enrollment;
use App\Models\RegistrationProposalVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IssueRegistrationProposal
{
    public function __construct(
        private readonly StudentUnitLoadService $unitLoad,
        private readonly RegistrationNotificationLedger $notifications,
        private readonly RegistrationReadinessQuery $readiness,
        private readonly ConfirmRegistrationProposal $confirm,
    ) {}

    public function execute(RegistrationProposalVersion $proposal, User $actor): RegistrationProposalVersion
    {
        if (! $actor->canAuthenticate()
            || ! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only authorized Registrar staff may issue a Registration Proposal.');
        }

        return $this->issue($proposal, $actor);
    }

    public function issuePrepared(RegistrationProposalVersion $proposal): RegistrationProposalVersion
    {
        if ($proposal->purpose !== RegistrationProposalVersion::PurposeInitial) {
            throw ValidationException::withMessages(['proposal' => 'An adjustment proposal must be issued by Registrar.']);
        }

        return $this->issue($proposal, null);
    }

    private function issue(RegistrationProposalVersion $proposal, ?User $actor): RegistrationProposalVersion
    {
        $issued = DB::transaction(function () use ($proposal, $actor): RegistrationProposalVersion {
            $enrollment = Enrollment::query()->whereKey($proposal->enrollment_id)->lockForUpdate()->firstOrFail();
            $locked = RegistrationProposalVersion::query()->with('items')->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            // Sending again after an automatic confirmation is a no-op, like re-sending an issued proposal (#110).
            if ($locked->state === RegistrationProposalVersion::StateConfirmed
                && (int) $enrollment->current_proposal_version_id === (int) $locked->id) {
                return $locked;
            }

            $expectedOutcome = $locked->purpose === RegistrationProposalVersion::PurposeAdjustment
                ? Enrollment::OutcomeOfficiallyEnrolled
                : Enrollment::OutcomeInProgress;
            if ($enrollment->canonical_outcome !== $expectedOutcome
                || (int) $enrollment->current_proposal_version_id !== (int) $locked->id
                || ! in_array($locked->state, [RegistrationProposalVersion::StateDraft, RegistrationProposalVersion::StateIssued], true)
                || $locked->items->isEmpty()) {
                throw ValidationException::withMessages(['proposal' => 'Only a complete current Draft proposal may be issued.']);
            }

            $this->unitLoad->assertProposalPermitted($enrollment, $locked, lockForUpdate: true);

            if ($locked->purpose === RegistrationProposalVersion::PurposeInitial) {
                $this->readiness->assertPrepared($enrollment, $locked);
            }

            if ($locked->state === RegistrationProposalVersion::StateIssued) {
                return $locked;
            }

            $locked->update(['state' => RegistrationProposalVersion::StateIssued, 'issued_by' => $actor?->id, 'issued_at' => now()]);

            return $locked->refresh();
        }, attempts: 3);

        if ($this->confirmForRegistrarStartedCase($issued, $actor)
            || $this->confirmStandardCurriculumCase($issued)) {
            return $issued->refresh();
        }

        $this->notifications->recordProposalReady($issued);

        return $issued;
    }

    /**
     * A regular (standard-curriculum) learner's subjects come straight from the block schedule,
     * so TALA records the confirmation itself and payment alone completes enrollment (#110).
     * Individually Advised cases are confirmed by the Registrar's review instead, and fall
     * through to ordinary learner confirmation if the automatic record cannot be made.
     */
    private function confirmStandardCurriculumCase(RegistrationProposalVersion $proposal): bool
    {
        if ($proposal->purpose !== RegistrationProposalVersion::PurposeInitial) {
            return false;
        }

        $enrollment = Enrollment::query()->with('credentialUser')->find($proposal->enrollment_id);

        if (! $enrollment instanceof Enrollment
            || $enrollment->selection_basis !== Enrollment::SelectionStandardCurriculum
            || $enrollment->credentialUser === null) {
            return false;
        }

        try {
            $this->confirm->execute($proposal, $enrollment->credentialUser, automatic: true);
        } catch (AuthorizationException|ValidationException) {
            return false;
        }

        return true;
    }

    /**
     * When a Registrar started the case at the office, record the learner's confirmation as
     * Registrar-assisted as soon as the subjects are sent, so payment alone completes enrollment.
     * Falls back to ordinary learner confirmation when no active Registrar can be attributed.
     */
    private function confirmForRegistrarStartedCase(RegistrationProposalVersion $proposal, ?User $actor): bool
    {
        if ($proposal->purpose !== RegistrationProposalVersion::PurposeInitial) {
            return false;
        }

        $enrollment = Enrollment::query()->with(['credentialUser', 'starter'])->find($proposal->enrollment_id);

        if (! $enrollment instanceof Enrollment || ! $enrollment->wasStartedByRegistrar() || $enrollment->credentialUser === null) {
            return false;
        }

        $registrar = collect([$actor, $enrollment->starter])->first(
            fn (?User $candidate): bool => $candidate instanceof User
                && $candidate->canAuthenticate()
                && $candidate->hasRole(User::StaffRoleRegistrar),
        );

        if (! $registrar instanceof User) {
            return false;
        }

        try {
            $this->confirm->execute($proposal, $enrollment->credentialUser, $registrar);
        } catch (AuthorizationException|ValidationException) {
            return false;
        }

        return true;
    }
}
