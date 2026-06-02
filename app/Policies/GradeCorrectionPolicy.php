<?php

namespace App\Policies;

use App\Enums\GradeCorrectionStatus;
use App\Models\GradeCorrection;
use App\Models\User;

class GradeCorrectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, [
            'manage-grade-corrections',
            'view-grade-submission-progress',
            'view-global-records',
            'view-class-list',
        ]);
    }

    public function view(User $user, GradeCorrection $gradeCorrection): bool
    {
        if ($this->canAny($user, [
            'manage-grade-corrections',
            'view-grade-submission-progress',
            'view-global-records',
        ])) {
            return true;
        }

        return $user->hasRole('faculty')
            && $user->can('view-class-list')
            && $gradeCorrection->isVisibleToFaculty($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, GradeCorrection $gradeCorrection): bool
    {
        return false;
    }

    public function startReview(User $user, GradeCorrection $gradeCorrection): bool
    {
        return $this->canRegistrarManage($user)
            && $this->status($gradeCorrection) === GradeCorrectionStatus::Submitted;
    }

    public function reject(User $user, GradeCorrection $gradeCorrection): bool
    {
        return $this->canRegistrarManage($user)
            && in_array($this->status($gradeCorrection), [
                GradeCorrectionStatus::Submitted,
                GradeCorrectionStatus::UnderReview,
            ], true);
    }

    public function resolveWithoutGradeChange(User $user, GradeCorrection $gradeCorrection): bool
    {
        return $this->canRegistrarManage($user)
            && $this->status($gradeCorrection) === GradeCorrectionStatus::UnderReview;
    }

    public function resolveWithGradeChange(User $user, GradeCorrection $gradeCorrection): bool
    {
        return $this->canRegistrarManage($user)
            && $gradeCorrection->grade_id !== null
            && $this->status($gradeCorrection) === GradeCorrectionStatus::UnderReview;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, GradeCorrection $gradeCorrection): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, GradeCorrection $gradeCorrection): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, GradeCorrection $gradeCorrection): bool
    {
        return false;
    }

    /**
     * @param  list<string>  $permissions
     */
    private function canAny(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    private function canRegistrarManage(User $user): bool
    {
        return $user->hasRole('registrar') && $user->can('manage-grade-corrections');
    }

    private function status(GradeCorrection $gradeCorrection): ?GradeCorrectionStatus
    {
        if ($gradeCorrection->status instanceof GradeCorrectionStatus) {
            return $gradeCorrection->status;
        }

        return GradeCorrectionStatus::tryFrom((string) $gradeCorrection->status);
    }
}
