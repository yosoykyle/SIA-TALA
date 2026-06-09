<?php

namespace App\Policies;

use App\Models\FacultyAvailabilitySubmission;
use App\Models\User;

class FacultyAvailabilitySubmissionPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, [
            'submit-faculty-availability',
            'review-lock-faculty-availability',
            'view-faculty-availability',
            'view-global-records',
        ]);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, FacultyAvailabilitySubmission $facultyAvailabilitySubmission): bool
    {
        return $this->canAny($user, [
            'review-lock-faculty-availability',
            'view-faculty-availability',
            'view-global-records',
        ]) || (
            $user->can('submit-faculty-availability')
            && (int) $facultyAvailabilitySubmission->faculty_id === $user->id
        );
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('submit-faculty-availability');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, FacultyAvailabilitySubmission $facultyAvailabilitySubmission): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, FacultyAvailabilitySubmission $facultyAvailabilitySubmission): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, FacultyAvailabilitySubmission $facultyAvailabilitySubmission): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, FacultyAvailabilitySubmission $facultyAvailabilitySubmission): bool
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
}
