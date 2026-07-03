<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageUsers($user);
    }

    public function view(User $user, User $model): bool
    {
        return $this->canManageUsers($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageUsers($user);
    }

    public function update(User $user, User $model): bool
    {
        return $this->canManageUsers($user)
            && $user->getKey() !== $model->getKey()
            && $model->status !== User::StatusArchived;
    }

    public function archiveStaffAccount(User $user, User $model): bool
    {
        return $this->canManageUsers($user)
            && $user->getKey() !== $model->getKey();
    }

    public function restoreStaffAccount(User $user, User $model): bool
    {
        return $this->canManageUsers($user)
            && $user->getKey() !== $model->getKey();
    }

    public function delete(User $user, User $model): bool
    {
        return false;
    }

    public function restore(User $user, User $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }

    private function canManageUsers(User $user): bool
    {
        return $user->hasRole(User::StaffRoleSystemSuperAdmin);
    }
}
