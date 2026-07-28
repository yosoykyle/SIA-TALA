<?php

namespace App\Actions\Scheduling;

/** Builds private, sanitized timetable evidence from solver assignments. */
final class SchedulingOperatingEnvelopeTimetableBuilder
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<array<string, mixed>>  $assignments
     * @param  array{sections?:array<int|string,string>,faculty?:array<int|string,string>}  $labels
     * @return array{
     *     assignment_count:int,
     *     assignments:list<array<string, int|string|null>>,
     *     section_timetables:array<string,list<array<string, int|string|null>>>,
     *     faculty_timetables:array<string,list<array<string, int|string|null>>>,
     *     room_timetables:array<string,list<array<string, int|string|null>>>
     * }
     */
    public function build(array $snapshot, array $assignments, array $labels = []): array
    {
        $demands = collect($snapshot['scheduling_demands'] ?? [])
            ->filter(fn (mixed $demand): bool => is_array($demand))
            ->keyBy(fn (array $demand): int => (int) ($demand['scheduling_demand_id'] ?? 0));
        $rooms = collect($snapshot['rooms'] ?? [])
            ->filter(fn (mixed $room): bool => is_array($room))
            ->mapWithKeys(fn (array $room): array => [
                (int) ($room['room_id'] ?? 0) => (string) ($room['code'] ?? 'Unassigned'),
            ]);
        $sectionLabels = $labels['sections'] ?? [];
        $facultyLabels = $labels['faculty'] ?? [];
        $rows = [];

        foreach ($assignments as $assignment) {
            $demandId = (int) ($assignment['scheduling_demand_id'] ?? $assignment['demand_id'] ?? 0);
            $demand = $demands->get($demandId, []);
            $sectionId = (int) ($demand['section_id'] ?? 0);
            $facultyId = (int) ($assignment['faculty_user_id'] ?? $assignment['faculty_id'] ?? 0);
            $roomId = (int) ($assignment['room_id'] ?? 0);
            $day = (int) ($assignment['day_of_week'] ?? $assignment['day'] ?? 0);
            $startsAt = (string) ($assignment['starts_at'] ?? $assignment['start_time'] ?? '');
            $endsAt = (string) ($assignment['ends_at'] ?? $assignment['end_time'] ?? '');

            $rows[] = [
                'scheduling_demand_id' => $demandId,
                'section' => $sectionLabels[$sectionId] ?? "Section {$sectionId}",
                'course' => (string) ($demand['course_code'] ?? 'Unknown course'),
                'faculty' => $facultyLabels[$facultyId] ?? "Faculty {$facultyId}",
                'room' => $roomId === 0 ? 'Online / no room' : $rooms->get($roomId, "Room {$roomId}"),
                'modality' => (string) ($demand['modality'] ?? 'UNKNOWN'),
                'day_number' => $day,
                'day' => $this->dayLabel($day),
                'time' => $this->timeLabel($startsAt, $endsAt),
                'assignment_status' => (string) ($assignment['assignment_status'] ?? 'unknown'),
            ];
        }

        usort($rows, fn (array $left, array $right): int => [
            $left['day_number'],
            $left['time'],
            $left['course'],
        ] <=> [
            $right['day_number'],
            $right['time'],
            $right['course'],
        ]);

        return [
            'assignment_count' => count($rows),
            'assignments' => $rows,
            'section_timetables' => $this->grouped($rows, 'section'),
            'faculty_timetables' => $this->grouped($rows, 'faculty'),
            'room_timetables' => $this->grouped($rows, 'room'),
        ];
    }

    /**
     * @param  list<array<string, int|string|null>>  $rows
     * @return array<string, list<array<string, int|string|null>>>
     */
    private function grouped(array $rows, string $key): array
    {
        return collect($rows)
            ->groupBy(fn (array $row): string => (string) $row[$key])
            ->sortKeys()
            ->map(fn ($group): array => $group->values()->all())
            ->all();
    }

    private function dayLabel(int $day): string
    {
        return [
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            7 => 'Sunday',
        ][$day] ?? 'Unknown day';
    }

    private function timeLabel(string $startsAt, string $endsAt): string
    {
        return substr($startsAt, 0, 5).'-'.substr($endsAt, 0, 5);
    }
}
