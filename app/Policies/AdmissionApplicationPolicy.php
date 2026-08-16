<?php

namespace App\Policies;

use App\Models\AdmissionApplication;
use App\Models\User;

class AdmissionApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isRegistrar($user) && $user->canAny([
            'approve-documents',
            'evaluate-transferees',
        ]);
    }

    public function view(User $user, AdmissionApplication $application): bool
    {
        return $application->user_id === $user->id || $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('applicant') && $user->canAuthenticate();
    }

    public function update(User $user, AdmissionApplication $application): bool
    {
        return $application->user_id === $user->id
            && in_array($application->application_state, [
                AdmissionApplication::StateDraft,
                AdmissionApplication::StateActionNeeded,
            ], true);
    }

    public function review(User $user, AdmissionApplication $application): bool
    {
        return $this->viewAny($user)
            && $user->can('approve-documents')
            && $application->application_state !== AdmissionApplication::StateDraft;
    }

    public function decide(User $user, AdmissionApplication $application): bool
    {
        return $this->review($user, $application);
    }

    public function resolveIdentity(User $user, AdmissionApplication $application): bool
    {
        return $this->review($user, $application)
            && $user->can('evaluate-transferees');
    }

    public function downloadEvidence(User $user, AdmissionApplication $application): bool
    {
        return $this->view($user, $application);
    }

    public function withdraw(User $user, AdmissionApplication $application): bool
    {
        return $application->user_id === $user->id || $this->review($user, $application);
    }

    public function delete(User $user, AdmissionApplication $application): bool
    {
        return $application->user_id === $user->id
            && $application->application_state === AdmissionApplication::StateDraft
            && $application->current_submission_version_id === null;
    }

    public function restore(User $user, AdmissionApplication $application): bool
    {
        return false;
    }

    public function forceDelete(User $user, AdmissionApplication $application): bool
    {
        return false;
    }

    private function isRegistrar(User $user): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar) && $user->canAuthenticate();
    }
}
