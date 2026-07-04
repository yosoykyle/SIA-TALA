<?php

namespace App\Policies;

use App\Models\DuplicateProfileResolution;
use App\Models\User;

class DuplicateProfileResolutionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar);
    }

    public function view(User $user, DuplicateProfileResolution $resolution): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar);
    }

    public function update(User $user, DuplicateProfileResolution $resolution): bool
    {
        return false;
    }

    public function delete(User $user, DuplicateProfileResolution $resolution): bool
    {
        return false;
    }
}
