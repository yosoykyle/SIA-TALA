<?php

namespace App\Policies;

use App\Models\SectionMeeting;
use App\Models\User;

class SectionMeetingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
            User::StaffRoleSystemSuperAdmin,
        ]);
    }

    public function view(User $user, SectionMeeting $sectionMeeting): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SectionMeeting $sectionMeeting): bool
    {
        return false;
    }

    public function delete(User $user, SectionMeeting $sectionMeeting): bool
    {
        return false;
    }

    public function restore(User $user, SectionMeeting $sectionMeeting): bool
    {
        return false;
    }

    public function forceDelete(User $user, SectionMeeting $sectionMeeting): bool
    {
        return false;
    }
}
