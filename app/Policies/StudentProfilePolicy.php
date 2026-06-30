<?php

namespace App\Policies;

use App\Models\StudentProfile;
use App\Models\User;

class StudentProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
            User::StaffRoleAccounting,
            User::StaffRoleSystemSuperAdmin,
        ])
            || $user->can('manage-student-profiles')
            || $user->can('approve-documents');
    }

    public function view(User $user, StudentProfile $studentProfile): bool
    {
        return $this->viewAny($user)
            || $user->can('manage-student-profiles')
            || $user->can('approve-documents')
            || ($user->studentProfile && $user->studentProfile->id === $studentProfile->id);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleSystemSuperAdmin])
            || $user->can('manage-student-profiles');
    }

    public function update(User $user, StudentProfile $studentProfile): bool
    {
        return $user->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleSystemSuperAdmin])
            || $user->can('manage-student-profiles');
    }

    public function delete(User $user, StudentProfile $studentProfile): bool
    {
        return false;
    }
}
