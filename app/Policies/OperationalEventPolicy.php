<?php

namespace App\Policies;

use App\Models\OperationalEvent;
use App\Models\User;

class OperationalEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(User::StaffRoleSystemSuperAdmin);
    }

    public function view(User $user, OperationalEvent $model): bool
    {
        return $user->hasRole(User::StaffRoleSystemSuperAdmin);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, OperationalEvent $model): bool
    {
        return false;
    }

    public function delete(User $user, OperationalEvent $model): bool
    {
        return false;
    }

    public function restore(User $user, OperationalEvent $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, OperationalEvent $model): bool
    {
        return false;
    }
}
