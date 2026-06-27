<?php

namespace App\Policies;

use App\Models\AdmissionRequirementPolicy;
use App\Models\User;

class AdmissionRequirementPolicyPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, AdmissionRequirementPolicy $admissionRequirementPolicy): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, AdmissionRequirementPolicy $admissionRequirementPolicy): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, AdmissionRequirementPolicy $admissionRequirementPolicy): bool
    {
        return false;
    }

    public function restore(User $user, AdmissionRequirementPolicy $admissionRequirementPolicy): bool
    {
        return false;
    }

    public function forceDelete(User $user, AdmissionRequirementPolicy $admissionRequirementPolicy): bool
    {
        return false;
    }

    private function canManage(User $user): bool
    {
        return $user->can('manage-admission-setup');
    }
}
