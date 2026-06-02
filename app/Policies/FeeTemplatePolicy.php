<?php

namespace App\Policies;

use App\Models\FeeTemplate;
use App\Models\User;

class FeeTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageAccounting($user) || $user->can('view-global-records');
    }

    public function view(User $user, FeeTemplate $feeTemplate): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('create-assessments');
    }

    public function update(User $user, FeeTemplate $feeTemplate): bool
    {
        return $user->can('create-assessments');
    }

    public function delete(User $user, FeeTemplate $feeTemplate): bool
    {
        return false;
    }

    public function restore(User $user, FeeTemplate $feeTemplate): bool
    {
        return false;
    }

    public function forceDelete(User $user, FeeTemplate $feeTemplate): bool
    {
        return false;
    }

    private function canManageAccounting(User $user): bool
    {
        return $this->canAny($user, [
            'create-assessments',
            'process-payments',
        ]);
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
