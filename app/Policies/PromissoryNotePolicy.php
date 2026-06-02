<?php

namespace App\Policies;

use App\Models\PromissoryNote;
use App\Models\User;

class PromissoryNotePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, [
            'approve-promissory-notes',
            'process-payments',
            'view-global-records',
        ]);
    }

    public function view(User $user, PromissoryNote $promissoryNote): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('approve-promissory-notes');
    }

    public function update(User $user, PromissoryNote $promissoryNote): bool
    {
        return $user->can('approve-promissory-notes');
    }

    public function delete(User $user, PromissoryNote $promissoryNote): bool
    {
        return false;
    }

    public function restore(User $user, PromissoryNote $promissoryNote): bool
    {
        return false;
    }

    public function forceDelete(User $user, PromissoryNote $promissoryNote): bool
    {
        return false;
    }

    /**
     * @param  list<string>  $permissions
     */
    private function canAny(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }
}
