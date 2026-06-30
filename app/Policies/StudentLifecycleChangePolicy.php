<?php

namespace App\Policies;

use App\Models\StudentLifecycleChange;
use App\Models\User;

class StudentLifecycleChangePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAccounting,
            User::StaffRoleAcademicHead,
            User::StaffRoleSystemSuperAdmin,
        ]);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, StudentLifecycleChange $studentLifecycleChange): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleSystemSuperAdmin]);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, StudentLifecycleChange $studentLifecycleChange): bool
    {
        return $this->create($user) && $studentLifecycleChange->state === StudentLifecycleChange::StateRecordedApproved;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, StudentLifecycleChange $studentLifecycleChange): bool
    {
        return false;
    }

    public function apply(User $user, StudentLifecycleChange $studentLifecycleChange): bool
    {
        return $this->update($user, $studentLifecycleChange);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, StudentLifecycleChange $studentLifecycleChange): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, StudentLifecycleChange $studentLifecycleChange): bool
    {
        return false;
    }
}
