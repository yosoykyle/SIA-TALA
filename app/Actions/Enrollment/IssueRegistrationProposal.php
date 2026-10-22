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
        $this->notifications->recordProposalReady($issued);

        return $issued;
    }
}
