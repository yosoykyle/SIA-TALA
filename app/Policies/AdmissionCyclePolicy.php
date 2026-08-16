<?php

namespace App\Policies;

use App\Models\AdmissionCycle;
use App\Models\User;

class AdmissionCyclePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, AdmissionCycle $admissionCycle): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, AdmissionCycle $admissionCycle): bool
    {
        return $this->canManage($user) && $admissionCycle->state === AdmissionCycle::StateDraft;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, AdmissionCycle $admissionCycle): bool
    {
        return $this->canManage($user)
            && $admissionCycle->state === AdmissionCycle::StateDraft
            && ! $admissionCycle->events()->exists()
            && ! $admissionCycle->requirementSets()->exists();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, AdmissionCycle $admissionCycle): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, AdmissionCycle $admissionCycle): bool
    {
        return false;
    }

    private function canManage(User $user): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar)
            && $user->canAuthenticate()
            && $user->can('manage-admission-setup');
    }
}
