<?php

namespace App\Policies;

use App\Models\InstallmentPolicyMilestone;
use App\Models\User;

class InstallmentPolicyMilestonePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, [
            'create-assessments',
            'process-payments',
            'approve-promissory-notes',
        ]);
    }

    public function view(User $user, InstallmentPolicyMilestone $installmentPolicyMilestone): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, InstallmentPolicyMilestone $installmentPolicyMilestone): bool
    {
        return false;
    }

    public function delete(User $user, InstallmentPolicyMilestone $installmentPolicyMilestone): bool
    {
        return false;
    }

    public function restore(User $user, InstallmentPolicyMilestone $installmentPolicyMilestone): bool
    {
        return false;
    }

    public function forceDelete(User $user, InstallmentPolicyMilestone $installmentPolicyMilestone): bool
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
