<?php

namespace App\Policies;

use App\Models\InstallmentPolicy;
use App\Models\User;

class InstallmentPolicyPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageInstallments($user) || $user->can('view-global-records');
    }

    public function view(User $user, InstallmentPolicy $installmentPolicy): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('create-assessments');
    }

    public function update(User $user, InstallmentPolicy $installmentPolicy): bool
    {
        return $user->can('create-assessments');
    }

    public function delete(User $user, InstallmentPolicy $installmentPolicy): bool
    {
        return false;
    }

    public function restore(User $user, InstallmentPolicy $installmentPolicy): bool
    {
        return false;
    }

    public function forceDelete(User $user, InstallmentPolicy $installmentPolicy): bool
    {
        return false;
    }

    private function canManageInstallments(User $user): bool
    {
        return $this->canAny($user, [
            'create-assessments',
            'process-payments',
            'approve-promissory-notes',
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
