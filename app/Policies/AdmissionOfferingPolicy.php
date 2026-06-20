<?php

namespace App\Policies;

use App\Models\AdmissionOffering;
use App\Models\User;

class AdmissionOfferingPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user) || $user->can('view-global-records');
    }

    public function view(User $user, AdmissionOffering $admissionOffering): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, AdmissionOffering $admissionOffering): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, AdmissionOffering $admissionOffering): bool
    {
        return false;
    }

    public function restore(User $user, AdmissionOffering $admissionOffering): bool
    {
        return false;
    }

    public function forceDelete(User $user, AdmissionOffering $admissionOffering): bool
    {
        return false;
    }

    private function canManage(User $user): bool
    {
        return $user->can('manage-admission-setup');
    }
}
