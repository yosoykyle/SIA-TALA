<?php

namespace App\Policies;

use App\Models\Hold;
use App\Models\User;

class HoldPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([
            User::StaffRoleRegistrar, User::StaffRoleAccounting,
            User::StaffRoleAcademicHead, User::StaffRoleSystemSuperAdmin,
        ]);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Hold $hold): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Hold $hold): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Hold $hold): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Hold $hold): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Hold $hold): bool
    {
        return false;
    }

    public function resolve(User $user, Hold $hold): bool
    {
        return $user->hasRole(User::StaffRoleSystemSuperAdmin)
            || ($hold->hold_type === Hold::TypeFinancial && $user->hasRole(User::StaffRoleAccounting))
            || ($hold->hold_type !== Hold::TypeFinancial && $user->hasRole(User::StaffRoleRegistrar));
    }

    public function waive(User $user, Hold $hold): bool
    {
        return $user->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAccounting, User::StaffRoleSystemSuperAdmin]);
    }
}
