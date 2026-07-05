<?php

namespace App\Policies;

use App\Models\AccountingAdjustment;
use App\Models\User;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class AccountingAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canPostAccountingAdjustment($user);
    }

    public function view(User $user, AccountingAdjustment $accountingAdjustment): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canPostAccountingAdjustment($user);
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

    private function canPostAccountingAdjustment(User $user): bool
    {
        if ($user->hasRole(User::StaffRoleAccounting)) {
            return true;
        }

        try {
            return $user->can('post-accounting-adjustments');
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
