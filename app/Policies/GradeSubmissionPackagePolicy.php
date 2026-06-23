<?php

namespace App\Policies;

use App\Models\GradeSubmissionPackage;
use App\Models\User;

class GradeSubmissionPackagePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, [
            'verify-grade-submissions',
            'view-grade-submission-progress',
            'view-global-records',
        ]);
    }

    public function view(User $user, GradeSubmissionPackage $gradeSubmissionPackage): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, GradeSubmissionPackage $gradeSubmissionPackage): bool
    {
        return false;
    }

    public function returnForRevision(User $user, GradeSubmissionPackage $gradeSubmissionPackage): bool
    {
        return $this->canRegistrarVerify($user)
            && $gradeSubmissionPackage->state === GradeSubmissionPackage::StateSubmitted;
    }

    public function verifyAndFinalize(User $user, GradeSubmissionPackage $gradeSubmissionPackage): bool
    {
        return $this->canRegistrarVerify($user)
            && $gradeSubmissionPackage->state === GradeSubmissionPackage::StateSubmitted;
    }

    public function delete(User $user, GradeSubmissionPackage $gradeSubmissionPackage): bool
    {
        return false;
    }

    public function restore(User $user, GradeSubmissionPackage $gradeSubmissionPackage): bool
    {
        return false;
    }

    public function forceDelete(User $user, GradeSubmissionPackage $gradeSubmissionPackage): bool
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

    private function canRegistrarVerify(User $user): bool
    {
        return $user->hasRole('registrar') && $user->can('verify-grade-submissions');
    }
}
