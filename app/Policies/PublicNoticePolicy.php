<?php

namespace App\Policies;

use App\Models\PublicNotice;
use App\Models\User;

class PublicNoticePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAuthenticate() && $user->hasRole(User::StaffRoleSystemSuperAdmin)
            && $user->can('manage-public-notices');
    }

    public function view(User $user, PublicNotice $notice): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, PublicNotice $notice): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, PublicNotice $notice): bool
    {
        return $this->viewAny($user) && ! $notice->wasPublished()
            && ! PublicNotice::query()->where('previous_version_id', $notice->id)->exists();
    }
}
