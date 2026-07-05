<?php

namespace App\Policies;

use App\Models\FacultyQualification;
use App\Models\User;

class FacultyQualificationPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
            User::StaffRoleFaculty,
        ]);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, FacultyQualification $facultyQualification): bool
    {
        if ($user->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAcademicHead])) {
            return true;
        }

        return $user->hasRole(User::StaffRoleFaculty)
            && (int) $facultyQualification->faculty_user_id === (int) $user->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, FacultyQualification $facultyQualification): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, FacultyQualification $facultyQualification): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, FacultyQualification $facultyQualification): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, FacultyQualification $facultyQualification): bool
    {
        return false;
    }

    private function canManage(User $user): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar);
    }
}
