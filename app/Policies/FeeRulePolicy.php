<?php

namespace App\Policies;

use App\Models\FeeRule;
use App\Models\User;

class FeeRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAccounting($user);
    }

    public function view(User $user, FeeRule $feeRule): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->isAccounting($user);
    }

    public function update(User $user, FeeRule $feeRule): bool
    {
        return $this->isAccounting($user);
    }

    public function delete(User $user, FeeRule $feeRule): bool
    {
        return false;
    }

    public function restore(User $user, FeeRule $feeRule): bool
    {
        return false;
    }

    public function forceDelete(User $user, FeeRule $feeRule): bool
    {
        return false;
    }

    private function isAccounting(User $user): bool
    {
        return $user->hasRole(User::StaffRoleAccounting);
    }
}
