<?php

namespace App\Policies;

use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\User;

class GradeRosterRowPolicy
{
    public function view(User $user, GradeRosterRow $gradeRosterRow): bool
    {
        $gradeRosterRow->loadMissing('roster');

        return $user->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAcademicHead])
            || ((int) $gradeRosterRow->roster->faculty_user_id === (int) $user->id && $user->hasRole(User::StaffRoleFaculty));
    }

    public function update(User $user, GradeRosterRow $gradeRosterRow): bool
    {
        $gradeRosterRow->loadMissing('roster');

        return (int) $gradeRosterRow->roster->faculty_user_id === (int) $user->id
            && $user->hasRole(User::StaffRoleFaculty);
    }

    public function resolveInc(User $user, GradeRosterRow $gradeRosterRow): bool
    {
        $gradeRosterRow->loadMissing('roster');

        return $user->hasRole(User::StaffRoleRegistrar)
            && $gradeRosterRow->roster->state === GradeRoster::StateReleased
            && $gradeRosterRow->current_outcome_code === 'INC';
    }

    public function recordCorrection(User $user, GradeRosterRow $gradeRosterRow): bool
    {
        $gradeRosterRow->loadMissing('roster');

        return $user->hasRole(User::StaffRoleRegistrar)
            && $gradeRosterRow->roster->state === GradeRoster::StateReleased
            && $gradeRosterRow->released_at !== null;
    }
}
