<?php

namespace App\Policies;

use App\Models\AccountingAdjustment;
use App\Models\User;

class AccountingAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, [
            'post-accounting-adjustments',
            'process-payments',
            'create-assessments',
        ]);
    }

    public function view(User $user, AccountingAdjustment $accountingAdjustment): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('post-accounting-adjustments');
    }

    public function update(User $user, AccountingAdjustment $accountingAdjustment): bool
    {
        return false;
    }

    public function delete(User $user, AccountingAdjustment $accountingAdjustment): bool
    {
        return false;
    }

    public function restore(User $user, AccountingAdjustment $accountingAdjustment): bool
    {
        return false;
    }

    public function forceDelete(User $user, AccountingAdjustment $accountingAdjustment): bool
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
