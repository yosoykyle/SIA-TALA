<?php

namespace App\Policies;

use App\Models\GradeRoster;
use App\Models\User;

class GradeRosterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAcademicHead, User::StaffRoleFaculty]);
    }

    public function view(User $user, GradeRoster $gradeRoster): bool
    {
        return $user->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAcademicHead])
            || ((int) $gradeRoster->faculty_user_id === (int) $user->id && $user->hasRole(User::StaffRoleFaculty));
    }

    public function create(User $user): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar);
    }

    public function update(User $user, GradeRoster $gradeRoster): bool
    {
        return $this->view($user, $gradeRoster);
    }

    public function delete(User $user, GradeRoster $gradeRoster): bool
    {
        return false;
    }
}
