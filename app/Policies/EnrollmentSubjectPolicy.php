<?php

namespace App\Policies;

use App\Models\EnrollmentSubject;
use App\Models\User;

class EnrollmentSubjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, [
            'view-class-list',
            'view-global-records',
        ]);
    }

    public function view(User $user, EnrollmentSubject $enrollmentSubject): bool
    {
        if ($user->hasRole('faculty')) {
            return $user->can('view-class-list') && $enrollmentSubject->isAssignedToFaculty($user);
        }

        return $user->can('view-global-records');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, EnrollmentSubject $enrollmentSubject): bool
    {
        return false;
    }

    public function delete(User $user, EnrollmentSubject $enrollmentSubject): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, EnrollmentSubject $enrollmentSubject): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, EnrollmentSubject $enrollmentSubject): bool
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
