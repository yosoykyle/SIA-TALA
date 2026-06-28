<?php

namespace App\Policies;

use App\Models\DuplicateProfileResolution;
use App\Models\User;

class DuplicateProfileResolutionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar)
            || $user->can('resolve-duplicate-profiles')
            || $user->can('approve-documents');
    }

    public function view(User $user, DuplicateProfileResolution $resolution): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar)
            || $user->can('resolve-duplicate-profiles')
            || $user->can('approve-documents');
    }

    public function create(User $user): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar)
            || $user->can('resolve-duplicate-profiles')
            || $user->can('approve-documents');
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
