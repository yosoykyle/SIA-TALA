<?php

namespace App\Policies;

use App\Models\Grade;
use App\Models\User;

class GradePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, [
            'view-grade-submission-progress',
            'view-global-records',
        ]);
    }

    public function view(User $user, Grade $grade): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Grade $grade): bool
    {
        return false;
    }

    public function forceFinalize(User $user, Grade $grade): bool
    {
        return $this->canAuthorizeOverride($user) && ! $grade->is_finalized;
    }

    public function reopen(User $user, Grade $grade): bool
    {
        return $this->canAuthorizeOverride($user) && $grade->is_finalized;
    }

    public function delete(User $user, Grade $grade): bool
    {
        return false;
    }

    public function restore(User $user, Grade $grade): bool
    {
        return false;
    }

    public function forceDelete(User $user, Grade $grade): bool
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

    private function canAuthorizeOverride(User $user): bool
    {
        return $user->hasRole('academic-head') && $user->can('authorize-overrides');
    }
}
