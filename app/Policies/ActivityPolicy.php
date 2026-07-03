<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(User::StaffRoleSystemSuperAdmin);
    }

    public function view(User $user, Activity $model): bool
    {
        return $user->hasRole(User::StaffRoleSystemSuperAdmin);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Activity $model): bool
    {
        return false;
    }

    public function delete(User $user, Activity $model): bool
    {
        return false;
    }

    public function restore(User $user, Activity $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, Activity $model): bool
    {
        return false;
    }
}
