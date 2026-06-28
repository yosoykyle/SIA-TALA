<?php

namespace App\Policies;

use App\Models\PersonalDataCorrectionRequest;
use App\Models\User;

class PersonalDataCorrectionRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar)
            || $user->can('manage-student-profiles')
            || $user->can('approve-documents');
    }

    public function view(User $user, PersonalDataCorrectionRequest $request): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar)
            || $user->can('manage-student-profiles')
            || $user->can('approve-documents')
            || ($user->studentProfile && $user->studentProfile->id === $request->student_profile_id);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar)
            || $user->can('manage-student-profiles');
    }

    public function update(User $user, PersonalDataCorrectionRequest $request): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar)
            || $user->can('manage-student-profiles');
    }

    public function delete(User $user, PersonalDataCorrectionRequest $request): bool
    {
        return false;
    }
}
