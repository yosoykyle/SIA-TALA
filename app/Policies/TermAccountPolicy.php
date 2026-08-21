<?php

namespace App\Policies;

use App\Models\TermAccount;
use App\Models\User;

class TermAccountPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->canAuthenticate()
            && $user->hasAnyRole([User::StaffRoleAccounting, User::StaffRoleRegistrar]);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, TermAccount $termAccount): bool
    {
        return $user->canAuthenticate()
            && ($this->viewAny($user) || (int) $termAccount->credential_user_id === (int) $user->id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, TermAccount $termAccount): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, TermAccount $termAccount): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, TermAccount $termAccount): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, TermAccount $termAccount): bool
    {
        return false;
    }
}
