<?php

namespace App\Policies;

use App\Models\LateGradeAuthorization;
use App\Models\User;

class LateGradeAuthorizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAcademicHead]);
    }

    public function view(User $user, LateGradeAuthorization $lateGradeAuthorization): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAcademicHead]);
    }
}
