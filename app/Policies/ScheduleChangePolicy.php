<?php

namespace App\Policies;

use App\Models\ScheduleChange;
use App\Models\User;

class ScheduleChangePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, [
            'manage-schedules',
            'authorize-overrides',
            'view-global-records',
        ]);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ScheduleChange $scheduleChange): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('manage-schedules');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ScheduleChange $scheduleChange): bool
    {
        return $user->can('manage-schedules') && $scheduleChange->status === 'proposed';
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ScheduleChange $scheduleChange): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ScheduleChange $scheduleChange): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ScheduleChange $scheduleChange): bool
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
