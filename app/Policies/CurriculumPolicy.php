<?php

namespace App\Policies;

use App\Models\Curriculum;
use App\Models\User;

class CurriculumPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user) || $user->can('view-global-records');
    }

    public function view(User $user, Curriculum $curriculum): bool
    {
        return $this->canManage($user) || $user->can('view-global-records');
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Curriculum $curriculum): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, Curriculum $curriculum): bool
    {
        return false;
    }

    public function restore(User $user, Curriculum $curriculum): bool
    {
        return false;
    }

    public function forceDelete(User $user, Curriculum $curriculum): bool
    {
        return false;
    }

    private function canManage(User $user): bool
    {
        return $user->can('manage-curricula');
    }
}
