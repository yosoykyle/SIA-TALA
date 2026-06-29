<?php

namespace App\Policies;

use App\Models\AssessmentLine;
use App\Models\User;

class AssessmentLinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([
            User::StaffRoleAccounting,
            User::StaffRoleRegistrar,
        ]);
    }

    public function view(User $user, AssessmentLine $assessmentLine): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AssessmentLine $assessmentLine): bool
    {
        return false;
    }

    public function delete(User $user, AssessmentLine $assessmentLine): bool
    {
        return false;
    }

    public function restore(User $user, AssessmentLine $assessmentLine): bool
    {
        return false;
    }

    public function forceDelete(User $user, AssessmentLine $assessmentLine): bool
    {
        return false;
    }
}
