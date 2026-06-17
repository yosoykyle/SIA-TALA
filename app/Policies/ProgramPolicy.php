<?php

namespace App\Policies;

use App\Models\Program;
use App\Models\User;

class ProgramPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user) || $user->can('view-global-records');
    }

    public function view(User $user, Program $program): bool
    {
        return $this->canManage($user) || $user->can('view-global-records');
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Program $program): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, Program $program): bool
    {
        return false;
    }

    public function restore(User $user, Program $program): bool
    {
        return false;
    }

    public function forceDelete(User $user, Program $program): bool
    {
        return false;
    }

    private function canManage(User $user): bool
    {
        return $user->can('manage-curricula');
    }
}
