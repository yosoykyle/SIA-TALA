<?php

namespace App\Policies;

use App\Models\AdmissionCapacityPlan;
use App\Models\User;

class AdmissionCapacityPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user) || $user->can('view-global-records');
    }

    public function view(User $user, AdmissionCapacityPlan $admissionCapacityPlan): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, AdmissionCapacityPlan $admissionCapacityPlan): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, AdmissionCapacityPlan $admissionCapacityPlan): bool
    {
        return false;
    }

    public function restore(User $user, AdmissionCapacityPlan $admissionCapacityPlan): bool
    {
        return false;
    }

    public function forceDelete(User $user, AdmissionCapacityPlan $admissionCapacityPlan): bool
    {
        return false;
    }

    private function canManage(User $user): bool
    {
        return $user->can('manage-admission-setup');
    }
}
