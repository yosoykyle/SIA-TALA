<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage-users');
    }

    public function view(User $user, Role $model): bool
    {
        return $user->can('manage-users');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Role $model): bool
    {
        return false;
    }

    public function delete(User $user, Role $model): bool
    {
        return false;
    }

    public function restore(User $user, Role $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, Role $model): bool
    {
        return false;
    }
}
