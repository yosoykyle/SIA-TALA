<?php

namespace App\Policies;

use App\Models\CalendarEvent;
use App\Models\CandidateScheduleRow;
use App\Models\PublishedTimetableMeeting;
use App\Models\Room;
use App\Models\SchedulingCommitment;
use App\Models\SchedulingDemand;
use App\Models\SectionMeeting;
use App\Models\User;

class RoomPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Room $room): bool
    {
        return $this->canView($user);
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
    public function update(User $user, Room $room): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Room $room): bool
    {
        if (! $this->canManage($user)) {
            return false;
        }

        return ! SectionMeeting::query()->where('room_id', $room->id)->exists()
            && ! CandidateScheduleRow::query()->where('room_id', $room->id)->exists()
            && ! PublishedTimetableMeeting::query()->where('room_id', $room->id)->exists()
            && ! CalendarEvent::query()->where('room_id', $room->id)->exists()
            && ! SchedulingCommitment::query()->where('room_id', $room->id)->exists()
            && ! SchedulingDemand::query()->where('fixed_room_id', $room->id)->exists();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Room $room): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Room $room): bool
    {
        return false;
    }

    private function canManage(User $user): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar);
    }

    private function canView(User $user): bool
    {
        return $user->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
        ]);
    }
}
