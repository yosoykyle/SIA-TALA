<?php

namespace App\Policies;

use App\Models\DocumentRequirementItem;
use App\Models\User;

class DocumentRequirementItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user) || $user->can('view-global-records');
    }

    public function view(User $user, DocumentRequirementItem $documentRequirementItem): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, DocumentRequirementItem $documentRequirementItem): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, DocumentRequirementItem $documentRequirementItem): bool
    {
        return false;
    }

    public function restore(User $user, DocumentRequirementItem $documentRequirementItem): bool
    {
        return false;
    }

    public function forceDelete(User $user, DocumentRequirementItem $documentRequirementItem): bool
    {
        return false;
    }

    private function canManage(User $user): bool
    {
        return $user->can('manage-admission-setup');
    }
}
