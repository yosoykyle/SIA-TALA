<?php

namespace App\Policies;

use App\Models\CalendarEvent;
use App\Models\User;

class CalendarEventPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([
            User::StaffRoleFaculty,
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
        ]);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, CalendarEvent $calendarEvent): bool
    {
        if (! $this->isRecurringSchedulingBlock($calendarEvent)) {
            return false;
        }

        if ($user->hasRole(User::StaffRoleRegistrar)) {
            return true;
        }

        if ($user->hasRole(User::StaffRoleAcademicHead)) {
            return $calendarEvent->scope_type === CalendarEvent::ScopeFaculty;
        }

        return $user->hasRole(User::StaffRoleFaculty) && $calendarEvent->isFacultyOwnedBy($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, CalendarEvent $calendarEvent): bool
    {
        return $this->view($user, $calendarEvent);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, CalendarEvent $calendarEvent): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, CalendarEvent $calendarEvent): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, CalendarEvent $calendarEvent): bool
    {
        return false;
    }

    private function isRecurringSchedulingBlock(CalendarEvent $calendarEvent): bool
    {
        return $calendarEvent->process_key === CalendarEvent::ProcessMasterSchedule
            && $calendarEvent->blocks_scheduling
            && $calendarEvent->day_of_week !== null
            && $calendarEvent->starts_at !== null
            && $calendarEvent->ends_at !== null;
    }
}
