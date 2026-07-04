<?php

namespace App\Policies;

use App\Models\CourseSpecification;
use App\Models\User;

class CourseSpecificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, CourseSpecification $courseSpecification): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, CourseSpecification $courseSpecification): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, CourseSpecification $courseSpecification): bool
    {
        return false;
    }

    public function restore(User $user, CourseSpecification $courseSpecification): bool
    {
        return false;
    }

    public function forceDelete(User $user, CourseSpecification $courseSpecification): bool
    {
        return false;
    }

    private function canManage(User $user): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar);
    }

    private function canView(User $user): bool
    {
        return $user->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
        ]);
    }
}
