<?php

namespace App\Policies;

use App\Models\CurriculumSubject;
use App\Models\User;

class CurriculumSubjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user) || $user->can('view-global-records');
    }

    public function view(User $user, CurriculumSubject $curriculumSubject): bool
    {
        return $this->canManage($user) || $user->can('view-global-records');
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, CurriculumSubject $curriculumSubject): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, CurriculumSubject $curriculumSubject): bool
    {
        return $this->canManage($user);
    }

    public function restore(User $user, CurriculumSubject $curriculumSubject): bool
    {
        return false;
    }

    public function forceDelete(User $user, CurriculumSubject $curriculumSubject): bool
    {
        return false;
    }

    private function canManage(User $user): bool
    {
        return $user->can('manage-curricula');
    }
}
