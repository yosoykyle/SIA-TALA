<?php

namespace App\Policies;

use App\Models\Assessment;
use App\Models\User;

class AssessmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isFinanceVisible($user);
    }

    public function view(User $user, Assessment $assessment): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->isAccounting($user);
    }

    public function update(User $user, Assessment $assessment): bool
    {
        return false;
    }

    public function delete(User $user, Assessment $assessment): bool
    {
        return false;
    }

    public function restore(User $user, Assessment $assessment): bool
    {
        return false;
    }

    public function forceDelete(User $user, Assessment $assessment): bool
    {
        return false;
    }

    public function activate(User $user, Assessment $assessment): bool
    {
        return $this->isAccounting($user) && in_array($assessment->state, [
            Assessment::StateDraft,
            Assessment::StateActive,
        ], true);
    }

    private function isAccounting(User $user): bool
    {
        return $user->hasRole(User::StaffRoleAccounting);
    }

    private function isFinanceVisible(User $user): bool
    {
        return $user->hasAnyRole([
            User::StaffRoleAccounting,
            User::StaffRoleRegistrar,
        ]);
    }
}
