<?php

namespace App\Policies;

use App\Models\FacultySubjectEligibility;
use App\Models\User;

class FacultySubjectEligibilityPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->canManage($user)
            || $user->hasRole(User::StaffRoleFaculty);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, FacultySubjectEligibility $facultySubjectEligibility): bool
    {
        return $this->canManage($user)
            || ($user->hasRole(User::StaffRoleFaculty) && (int) $facultySubjectEligibility->faculty_id === $user->id);
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
    public function update(User $user, FacultySubjectEligibility $facultySubjectEligibility): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, FacultySubjectEligibility $facultySubjectEligibility): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, FacultySubjectEligibility $facultySubjectEligibility): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, FacultySubjectEligibility $facultySubjectEligibility): bool
    {
        return false;
    }

    private function canManage(User $user): bool
    {
        return $user->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
        ]) && $user->can('manage-faculty-subject-eligibilities');
    }
}
