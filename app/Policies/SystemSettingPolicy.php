<?php

namespace App\Policies;

use App\Models\SystemSetting;
use App\Models\User;

class SystemSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(User::StaffRoleSystemSuperAdmin);
    }

    public function view(User $user, SystemSetting $model): bool
    {
        return $user->hasRole(User::StaffRoleSystemSuperAdmin);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SystemSetting $model): bool
    {
        return false;
    }

    public function delete(User $user, SystemSetting $model): bool
    {
        return false;
    }

    public function restore(User $user, SystemSetting $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, SystemSetting $model): bool
    {
        return false;
    }
}
