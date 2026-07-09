<?php

namespace App\Actions\Scheduling;

use App\Models\CalendarEvent;
use App\Models\Room;
use Illuminate\Validation\ValidationException;

class SectionMeetingAssignmentService
{
    /**
     * Enforce that a proposed recurring meeting does not overlap an active recurring
     * scheduling block (institution-, faculty-, or room-scoped). Review notes cannot
     * override this fixed hard constraint (PRD §6.2.1 availability/calendar-block rules).
     *
     * @param  array<string, mixed>  $assignment
     *
     * @throws ValidationException
     */
    public function assertRecurringBlocksAllow(array $assignment): void
    {
        $termId = $this->integerValue($assignment['term_id'] ?? null);
        $facultyId = $this->integerValue($assignment['faculty_user_id'] ?? $assignment['faculty_id'] ?? null);
        $roomId = $this->integerValue($assignment['room_id'] ?? null);
        $dayOfWeek = $this->integerValue($assignment['day_of_week'] ?? null);
        $startsAt = $this->timeValue($assignment['starts_at'] ?? null);
        $endsAt = $this->timeValue($assignment['ends_at'] ?? null);

        if ($roomId === null && filled($assignment['room'] ?? null)) {
            $roomId = Room::query()
                ->where('code', (string) $assignment['room'])
                ->value('id');
        }

        if ($termId === null || $dayOfWeek === null || $startsAt === null || $endsAt === null) {
            throw ValidationException::withMessages([
                'calendar_blocks' => 'Term, day, start time, and end time are required for recurring scheduling-block validation.',
            ]);
        }

        $block = CalendarEvent::query()
            ->recurringSchedulingBlocks()
            ->where('term_id', $termId)
            ->where('state', CalendarEvent::StateActive)
            ->where('day_of_week', $dayOfWeek)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->where(function ($query) use ($facultyId, $roomId): void {
                $query->where('scope_type', CalendarEvent::ScopeInstitution);

                if ($facultyId !== null) {
                    $query->orWhere(function ($query) use ($facultyId): void {
                        $query->where('scope_type', CalendarEvent::ScopeFaculty)
                            ->where('faculty_user_id', $facultyId);
                    });
                }

                if ($roomId !== null) {
                    $query->orWhere(function ($query) use ($roomId): void {
                        $query->where('scope_type', CalendarEvent::ScopeRoom)
                            ->where('room_id', $roomId);
                    });
                }
            })
            ->orderBy('scope_type')
            ->orderBy('id')
            ->first();

        if (! $block instanceof CalendarEvent) {
            return;
        }

        $field = match ($block->scope_type) {
            CalendarEvent::ScopeFaculty => 'faculty_user_id',
            CalendarEvent::ScopeRoom => 'room_id',
            default => 'day_of_week',
        };

        throw ValidationException::withMessages([
            $field => 'The proposed meeting overlaps an active recurring scheduling block. Review notes do not override this hard constraint.',
        ]);
    }

    private function integerValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function timeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $time = (string) $value;

        return strlen($time) > 5 ? substr($time, 0, 5) : $time;
    }
}
