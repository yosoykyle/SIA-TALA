<?php

namespace App\Policies;

use App\Models\GraduationReviewBatch;
use App\Models\GraduationReviewMember;
use App\Models\User;

class GraduationReviewMemberPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
            User::StaffRoleSystemSuperAdmin,
        ]);
    }

    public function view(User $user, GraduationReviewMember $graduationReviewMember): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar);
    }

    public function refreshAnySnapshot(User $user): bool
    {
        return $this->create($user);
    }

    public function update(User $user, GraduationReviewMember $graduationReviewMember): bool
    {
        return $this->canChangeOpenReview($user, $graduationReviewMember);
    }

    public function delete(User $user, GraduationReviewMember $graduationReviewMember): bool
    {
        return $this->canChangeOpenReview($user, $graduationReviewMember);
    }

    public function refreshSnapshot(User $user, GraduationReviewMember $graduationReviewMember): bool
    {
        return $this->canChangeOpenReview($user, $graduationReviewMember)
            && $graduationReviewMember->is_active;
    }

    private function canChangeOpenReview(User $user, GraduationReviewMember $graduationReviewMember): bool
    {
        return $this->create($user)
            && $graduationReviewMember->batch?->state === GraduationReviewBatch::StateOpen;
    }
}
