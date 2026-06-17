<?php

namespace App\Policies;

use App\Models\Term;
use App\Models\User;

class TermPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user) || $user->can('view-global-records');
    }

    public function view(User $user, Term $term): bool
    {
        return $this->canManage($user) || $user->can('view-global-records');
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Term $term): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, Term $term): bool
    {
        return false;
    }

    public function restore(User $user, Term $term): bool
    {
        return false;
    }

    public function forceDelete(User $user, Term $term): bool
    {
        return false;
    }

    private function canManage(User $user): bool
    {
        return $user->can('manage-terms');
    }
}
