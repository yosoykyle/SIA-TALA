<?php

namespace App\Policies;

use App\Models\ApplicantIntake;
use App\Models\User;

class ApplicantIntakePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([
            'approve-documents',
            'evaluate-transferees',
        ]);
    }

    public function view(User $user, ApplicantIntake $applicantIntake): bool
    {
        return $this->viewAny($user) && $applicantIntake->status !== ApplicantIntake::StatusDraft;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ApplicantIntake $applicantIntake): bool
    {
        return false;
    }

    public function delete(User $user, ApplicantIntake $applicantIntake): bool
    {
        return false;
    }

    public function restore(User $user, ApplicantIntake $applicantIntake): bool
    {
        return false;
    }

    public function forceDelete(User $user, ApplicantIntake $applicantIntake): bool
    {
        return false;
    }
}
