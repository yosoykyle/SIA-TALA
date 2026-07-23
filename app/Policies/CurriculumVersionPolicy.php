<?php

namespace App\Policies;

use App\Models\CurriculumVersion;
use App\Models\User;

class CurriculumVersionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, CurriculumVersion $curriculumVersion): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, CurriculumVersion $curriculumVersion): bool
    {
        return $this->canManage($user)
            && $curriculumVersion->state === CurriculumVersion::StateDraft;
    }

    public function recordApproval(User $user, CurriculumVersion $curriculumVersion): bool
    {
        return $this->canManage($user)
            && $curriculumVersion->state === CurriculumVersion::StateDraft;
    }

    public function activate(User $user, CurriculumVersion $curriculumVersion): bool
    {
        return $this->canManage($user)
            && $curriculumVersion->state === CurriculumVersion::StateRecordedApproved;
    }

    public function delete(User $user, CurriculumVersion $curriculumVersion): bool
    {
        return false;
    }

    public function restore(User $user, CurriculumVersion $curriculumVersion): bool
    {
        return false;
    }

    public function forceDelete(User $user, CurriculumVersion $curriculumVersion): bool
    {
        return false;
    }

    private function canManage(User $user): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar);
    }

    private function canView(User $user): bool
    {
        return $user->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
        ]);
    }
}
