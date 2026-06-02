<?php

namespace App\Policies;

use App\Models\SectionMeeting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SectionMeetingPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, [
            'manage-schedules',
            'view-schedule',
            'view-global-records',
        ]);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, SectionMeeting $sectionMeeting): bool
    {
        if ($this->canAny($user, [
            'manage-schedules',
            'view-global-records',
        ])) {
            return true;
        }

        if (! $user->hasRole('faculty') || ! $user->can('view-schedule')) {
            return false;
        }

        return (int) $sectionMeeting->faculty_id === $user->id
            || DB::table('section_teacher')
                ->where('section_id', $sectionMeeting->section_id)
                ->where('subject_id', $sectionMeeting->subject_id)
                ->where('user_id', $user->id)
                ->exists();
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
    public function update(User $user, SectionMeeting $sectionMeeting): bool
    {
        return $user->can('manage-schedules');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, SectionMeeting $sectionMeeting): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, SectionMeeting $sectionMeeting): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, SectionMeeting $sectionMeeting): bool
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
