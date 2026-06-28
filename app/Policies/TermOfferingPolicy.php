<?php

namespace App\Policies;

use App\Models\TermOffering;
use App\Models\User;

class TermOfferingPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, TermOffering $termOffering): bool
    {
        return $this->canManage($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, TermOffering $termOffering): bool
    {
        return $this->canManage($user) && $termOffering->state === TermOffering::StatePendingScheduling;
    }

    public function delete(User $user, TermOffering $termOffering): bool
    {
        return false;
    }

    public function restore(User $user, TermOffering $termOffering): bool
    {
        return false;
    }

    public function forceDelete(User $user, TermOffering $termOffering): bool
    {
        return false;
    }

    private function canManage(User $user): bool
    {
        return $user->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleSystemSuperAdmin]);
    }
}
