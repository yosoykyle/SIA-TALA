<?php

namespace App\Policies;

use App\Models\FacultyAvailabilityChangeRequest;
use App\Models\User;

class FacultyAvailabilityChangeRequestPolicy
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
    public function view(User $user, FacultyAvailabilityChangeRequest $facultyAvailabilityChangeRequest): bool
    {
        return $this->canAny($user, [
            'review-lock-faculty-availability',
            'view-faculty-availability',
            'view-global-records',
        ]) || (
            $user->can('submit-faculty-availability')
            && (int) $facultyAvailabilityChangeRequest->faculty_id === $user->id
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
    public function update(User $user, FacultyAvailabilityChangeRequest $facultyAvailabilityChangeRequest): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, FacultyAvailabilityChangeRequest $facultyAvailabilityChangeRequest): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, FacultyAvailabilityChangeRequest $facultyAvailabilityChangeRequest): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, FacultyAvailabilityChangeRequest $facultyAvailabilityChangeRequest): bool
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
