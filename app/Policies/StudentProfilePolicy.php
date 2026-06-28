<?php

namespace App\Policies;

use App\Models\StudentProfile;
use App\Models\User;

class StudentProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar)
            || $user->can('manage-student-profiles')
            || $user->can('approve-documents');
    }

    public function view(User $user, StudentProfile $studentProfile): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar)
            || $user->can('manage-student-profiles')
            || $user->can('approve-documents')
            || ($user->studentProfile && $user->studentProfile->id === $studentProfile->id);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar)
            || $user->can('manage-student-profiles');
    }

    public function update(User $user, StudentProfile $studentProfile): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar)
            || $user->can('manage-student-profiles');
    }

    public function delete(User $user, StudentProfile $studentProfile): bool
    {
        return false;
    }
}
