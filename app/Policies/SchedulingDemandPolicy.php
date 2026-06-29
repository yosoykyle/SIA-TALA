<?php

namespace App\Policies;

use App\Models\SchedulingDemand;
use App\Models\User;

class SchedulingDemandPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
            User::StaffRoleSystemSuperAdmin,
        ]);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, SchedulingDemand $schedulingDemand): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleSystemSuperAdmin,
        ]);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, SchedulingDemand $schedulingDemand): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, SchedulingDemand $schedulingDemand): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, SchedulingDemand $schedulingDemand): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, SchedulingDemand $schedulingDemand): bool
    {
        return false;
    }
}
