<?php

namespace App\Policies;

use App\Models\FaqEntry;
use App\Models\User;

class FaqEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAuthenticate() && $user->hasRole(User::StaffRoleSystemSuperAdmin) && $user->can('manage-faqs');
    }

    public function view(User $user, FaqEntry $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, FaqEntry $model): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, FaqEntry $model): bool
    {
        return $this->viewAny($user) && ! $model->wasPublished()
            && ! FaqEntry::query()->where('previous_version_id', $model->id)->exists();
    }

    public function restore(User $user, FaqEntry $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, FaqEntry $model): bool
    {
        return false;
    }
}
