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
    public function __construct(private readonly StudentUnitLoadService $unitLoad) {}

    public function execute(RegistrationProposalVersion $proposal, User $actor): RegistrationProposalVersion
    {
        if (! $actor->canAuthenticate()
            || ! $actor->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleSystemSuperAdmin])) {
            throw new AuthorizationException('Only authorized Registrar staff may issue a Registration Proposal.');
        }

        return DB::transaction(function () use ($proposal, $actor): RegistrationProposalVersion {
            $locked = RegistrationProposalVersion::query()->with('items')->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            $enrollment = Enrollment::query()->whereKey($locked->enrollment_id)->lockForUpdate()->firstOrFail();

            if ($locked->state === RegistrationProposalVersion::StateIssued) {
                return $locked;
            }
            $expectedOutcome = $locked->purpose === RegistrationProposalVersion::PurposeAdjustment
                ? Enrollment::OutcomeOfficiallyEnrolled
                : Enrollment::OutcomeInProgress;
            if ($enrollment->canonical_outcome !== $expectedOutcome
                || (int) $enrollment->current_proposal_version_id !== (int) $locked->id
                || $locked->state !== RegistrationProposalVersion::StateDraft
                || $locked->items->isEmpty()) {
                throw ValidationException::withMessages(['proposal' => 'Only a complete current Draft proposal may be issued.']);
            }

            $this->unitLoad->assertProposalPermitted($enrollment, $locked, lockForUpdate: true);

            $locked->update(['state' => RegistrationProposalVersion::StateIssued, 'issued_by' => $actor->id, 'issued_at' => now()]);

            return $locked->refresh();
        }, attempts: 3);
    }
}
