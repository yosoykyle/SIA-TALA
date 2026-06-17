<?php

namespace App\Policies;

use App\Models\AcademicYear;
use App\Models\User;

class AcademicYearPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user) || $user->can('view-global-records');
    }

    public function view(User $user, AcademicYear $academicYear): bool
    {
        return $this->canManage($user) || $user->can('view-global-records');
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, AcademicYear $academicYear): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, AcademicYear $academicYear): bool
    {
        return false;
    }

    public function restore(User $user, AcademicYear $academicYear): bool
    {
        return false;
    }

    public function forceDelete(User $user, AcademicYear $academicYear): bool
    {
        return false;
    }

    private function canManage(User $user): bool
    {
        return $user->can('manage-terms');
    }
}
