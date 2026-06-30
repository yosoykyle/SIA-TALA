<?php

namespace App\Policies;

use App\Models\GraduationReviewBatch;
use App\Models\User;

class GraduationReviewBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
            User::StaffRoleSystemSuperAdmin,
        ]);
    }

    public function view(User $user, GraduationReviewBatch $graduationReviewBatch): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleSystemSuperAdmin]);
    }

    public function update(User $user, GraduationReviewBatch $graduationReviewBatch): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, GraduationReviewBatch $graduationReviewBatch): bool
    {
        return false;
    }
}
