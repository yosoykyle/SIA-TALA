<?php

namespace App\Policies;

use App\Models\GraduationSnapshot;
use App\Models\User;

class GraduationSnapshotPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
            User::StaffRoleSystemSuperAdmin,
        ]);
    }

    public function view(User $user, GraduationSnapshot $graduationSnapshot): bool
    {
        if ($this->viewAny($user)) {
            return true;
        }

        return $graduationSnapshot->made_visible_at !== null
            && (int) $graduationSnapshot->member?->studentProfile?->user_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleSystemSuperAdmin]);
    }

    public function update(User $user, GraduationSnapshot $graduationSnapshot): bool
    {
        return false;
    }

    public function delete(User $user, GraduationSnapshot $graduationSnapshot): bool
    {
        return false;
    }

    public function updateVisibility(User $user, GraduationSnapshot $graduationSnapshot): bool
    {
        return $user->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleSystemSuperAdmin]);
    }
}
