<?php

namespace App\Policies;

use App\Models\FeePlan;
use App\Models\User;

class FeePlanPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->canAuthenticate() && $user->hasRole(User::StaffRoleAccounting);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, FeePlan $feePlan): bool
    {
        return $user->canAuthenticate() && $user->hasRole(User::StaffRoleAccounting);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->canAuthenticate() && $user->hasRole(User::StaffRoleAccounting);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, FeePlan $feePlan): bool
    {
        return $user->canAuthenticate()
            && $user->hasRole(User::StaffRoleAccounting)
            && $feePlan->state === FeePlan::StateDraft;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, FeePlan $feePlan): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, FeePlan $feePlan): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, FeePlan $feePlan): bool
    {
        return false;
    }
}
