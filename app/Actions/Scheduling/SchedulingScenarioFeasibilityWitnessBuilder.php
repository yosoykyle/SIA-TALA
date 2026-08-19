<?php

namespace App\Actions\Scheduling;

use RuntimeException;

/**
 * Constructs a deterministic hard-constraint witness for acceptance fixtures.
 *
 * This is not an optimizer and cannot prove optimality. Its only purpose is to
 * demonstrate that a disclosed fixture has at least one complete assignment
 * which the independent Laravel validator can check before paid solver runs.
 */
class SchedulingScenarioFeasibilityWitnessBuilder
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    public function build(array $snapshot): array
    {
        $demands = array_values(array_filter(
            $snapshot['scheduling_demands'] ?? [],
            fn (mixed $demand): bool => is_array($demand),
        ));
        $facultyByDemand = $this->assignFaculty($snapshot, $demands);
        $roomPressure = $this->roomPressure($snapshot, $demands);

        usort($demands, function (array $left, array $right) use ($roomPressure): int {
            $leftRoomType = (string) ($left['room_type_requirement'] ?? '');
            $rightRoomType = (string) ($right['room_type_requirement'] ?? '');

            return [
                ($left['room_required'] ?? false) ? 0 : 1,
                -($roomPressure[$leftRoomType] ?? 0),
                -(int) ($left['required_duration_minutes'] ?? 0),
                count($left['eligible_faculty_user_ids'] ?? []),
                (int) ($left['scheduling_demand_id'] ?? 0),
            ] <=> [
                ($right['room_required'] ?? false) ? 0 : 1,
                -($roomPressure[$rightRoomType] ?? 0),
                -(int) ($right['required_duration_minutes'] ?? 0),
                count($right['eligible_faculty_user_ids'] ?? []),
                (int) ($right['scheduling_demand_id'] ?? 0),
            ];
        });

        $assignments = [];
        $usedPlacements = [];

        foreach ($demands as $demand) {
            $demandId = (int) $demand['scheduling_demand_id'];
            $facultyId = $facultyByDemand[$demandId] ?? null;
            $meetingCount = max(1, (int) ($demand['meeting_count'] ?? 1));

            foreach (range(1, $meetingCount) as $meetingSequence) {
                $candidate = $this->bestTimeAndRoom($snapshot, $demand, $facultyId, $usedPlacements);

                if ($candidate === null) {
                    throw new RuntimeException(
                        "No deterministic timetable witness could place demand {$demandId} meeting {$meetingSequence}.",
                    );
                }

                $assignments[] = $this->assignment(
                    $demand,
                    $facultyId,
                    $candidate,
                    $meetingSequence,
                );
                $usedPlacements[] = $candidate;
            }
        }

        usort(
            $assignments,
            fn (array $left, array $right): int => [
                $left['scheduling_demand_id'],
                $left['meeting_sequence'],
            ] <=> [
                $right['scheduling_demand_id'],
                $right['meeting_sequence'],
            ],
        );

        return $assignments;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<array<string, mixed>>  $demands
     * @return array<int, int>
     */
    private function assignFaculty(array $snapshot, array $demands): array
    {
        $limits = [];

        foreach ($snapshot['faculty'] ?? [] as $faculty) {
            if (is_array($faculty)) {
                $limits[(int) $faculty['faculty_id']] = (float) $faculty['max_allowed_units'];
            }
        }

        ksort($limits);
        $loads = array_fill_keys(array_keys($limits), 0.0);
        $groups = [];

        foreach ($demands as $demand) {
            $key = $demand['term_offering_id'].':'.$demand['section_delivery_group_id'];
            $eligible = array_map('intval', $demand['eligible_faculty_user_ids'] ?? []);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'course_code' => (string) ($demand['course_code'] ?? ''),
                    'units' => (float) ($demand['load_units'] ?? 0),
                    'eligible' => $eligible,
                    'demand_ids' => [],
                ];
            } else {
                $groups[$key]['eligible'] = array_values(array_intersect($groups[$key]['eligible'], $eligible));
                $groups[$key]['units'] = max($groups[$key]['units'], (float) ($demand['load_units'] ?? 0));
            }

            $groups[$key]['demand_ids'][] = (int) $demand['scheduling_demand_id'];
        }

        $groups = array_values($groups);
        usort($groups, fn (array $left, array $right): int => [
            -$left['units'],
            $left['course_code'],
            $left['key'],
        ] <=> [
            -$right['units'],
            $right['course_code'],
            $right['key'],
        ]);

        $facultyByDemand = [];

        foreach ($groups as $group) {
            $eligible = array_values(array_filter(
                array_unique($group['eligible']),
                fn (int $facultyId): bool => isset($limits[$facultyId])
                    && $loads[$facultyId] + $group['units'] <= $limits[$facultyId] + 0.00001,
            ));
            usort(
                $eligible,
                fn (int $left, int $right): int => [$loads[$left], $left] <=> [$loads[$right], $right],
            );

            if ($eligible === []) {
                throw new RuntimeException("No deterministic faculty witness could cover workload {$group['key']}.");
            }

            $facultyId = $eligible[0];
            $loads[$facultyId] += $group['units'];

            foreach ($group['demand_ids'] as $demandId) {
                $facultyByDemand[$demandId] = $facultyId;
            }
        }

        return $facultyByDemand;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<array<string, mixed>>  $demands
     * @return array<string, float>
     */
    private function roomPressure(array $snapshot, array $demands): array
    {
        $counts = [];

        foreach ($snapshot['rooms'] ?? [] as $room) {
            if (is_array($room)) {
                $type = (string) ($room['room_type'] ?? '');
                $counts[$type] = ($counts[$type] ?? 0) + 1;
            }
        }

        $minutes = [];

        foreach ($demands as $demand) {
            if (($demand['room_required'] ?? false) === true) {
                $type = (string) ($demand['room_type_requirement'] ?? '');
                $minutes[$type] = ($minutes[$type] ?? 0)
                    + ((int) ($demand['required_duration_minutes'] ?? 0)
                        * max(1, (int) ($demand['meeting_count'] ?? 1)));
            }
        }

        return collect($minutes)->map(
            fn (int $required, string $type): float => $required / max(1, $counts[$type] ?? 0),
        )->all();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $demand
     * @param  list<array<string, mixed>>  $used
     * @return array<string, int|string|null>|null
     */
    private function bestTimeAndRoom(array $snapshot, array $demand, int $facultyId, array $used): ?array
    {
        $rooms = $this->suitableRooms($snapshot, $demand);
        $slots = $snapshot['time_slots'] ?? [];
        $dayEndsAt = (string) ($snapshot['term']['scheduling_day_ends_at'] ?? '21:00:00');
        $best = null;
        $bestScore = null;

        foreach ($rooms as $roomId) {
            foreach ($slots as $slot) {
                if (! is_array($slot)) {
                    continue;
                }

                $day = (int) $slot['day_of_week'];
                $startsAt = (string) $slot['starts_at'];

                if (($demand['fixed_day_of_week'] ?? null) !== null && $day !== (int) $demand['fixed_day_of_week']) {
                    continue;
                }

                if (($demand['fixed_start_time'] ?? null) !== null && $startsAt !== (string) $demand['fixed_start_time']) {
                    continue;
                }

                $endsAt = $this->addMinutes($startsAt, (int) $demand['required_duration_minutes']);

                if ($endsAt > $dayEndsAt) {
                    continue;
                }

                $candidate = [
                    'room_id' => $roomId,
                    'faculty_user_id' => $facultyId,
                    'cohort_or_student_group_id' => (int) ($demand['cohort_or_student_group_id'] ?? $demand['section_delivery_group_id']),
                    'section_delivery_group_id' => (int) $demand['section_delivery_group_id'],
                    'modality' => (string) ($demand['modality'] ?? ''),
                    'day_of_week' => $day,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'time_slot_id' => (int) $slot['time_slot_id'],
                    'time_block_key' => (string) $slot['time_block_key'],
                ];

                if ($this->overlaps($candidate, $used)) {
                    continue;
                }

                $score = $this->placementScore($candidate, $used);

                if ($bestScore === null || $score < $bestScore) {
                    $best = $candidate;
                    $bestScore = $score;
                }
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $demand
     * @return list<int|null>
     */
    private function suitableRooms(array $snapshot, array $demand): array
    {
        if (($demand['room_required'] ?? false) !== true) {
            return [null];
        }

        $requiredFeatures = array_map('strtoupper', $demand['required_room_feature_keys'] ?? []);
        $rooms = [];

        foreach ($snapshot['rooms'] ?? [] as $room) {
            if (! is_array($room)
                || ($demand['fixed_room_id'] ?? null) !== null && (int) $demand['fixed_room_id'] !== (int) $room['room_id']
                || ($demand['room_type_requirement'] ?? null) !== ($room['room_type'] ?? null)
                || (int) ($room['capacity'] ?? 0) < (int) ($demand['expected_count'] ?? 0)
                || array_diff($requiredFeatures, array_map('strtoupper', $room['feature_keys'] ?? [])) !== []) {
                continue;
            }

            $rooms[] = (int) $room['room_id'];
        }

        return $rooms;
    }

    /** @param array<string, mixed> $candidate
     * @param  list<array<string, mixed>>  $used
     */
    private function overlaps(array $candidate, array $used): bool
    {
        foreach ($used as $assignment) {
            if ((int) $assignment['day_of_week'] !== (int) $candidate['day_of_week']) {
                continue;
            }

            $sameCohort = (int) $assignment['cohort_or_student_group_id']
                === (int) $candidate['cohort_or_student_group_id'];
            $timesOverlap = $candidate['starts_at'] < $assignment['ends_at']
                && $candidate['ends_at'] > $assignment['starts_at'];

            if ($timesOverlap && (
                (int) $assignment['faculty_user_id'] === (int) $candidate['faculty_user_id']
                || $sameCohort
                || (int) $assignment['section_delivery_group_id'] === (int) $candidate['section_delivery_group_id']
                || $candidate['room_id'] !== null && $assignment['room_id'] === $candidate['room_id']
            )) {
                return true;
            }

            if ($sameCohort
                && ($assignment['modality'] ?? '') !== ($candidate['modality'] ?? '')
                && $this->transitionGapMinutes($candidate, $assignment) < 30) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $candidate
     * @param  list<array<string, mixed>>  $used
     */
    private function placementScore(array $candidate, array $used): int
    {
        $roomMinutes = 0;
        $facultyMinutes = 0;
        $cohortMinutes = 0;

        foreach ($used as $assignment) {
            if ((int) $assignment['day_of_week'] !== (int) $candidate['day_of_week']) {
                continue;
            }

            $minutes = $this->minutesBetween($assignment['starts_at'], $assignment['ends_at']);
            $roomMinutes += $candidate['room_id'] !== null && $assignment['room_id'] === $candidate['room_id'] ? $minutes : 0;
            $facultyMinutes += (int) $assignment['faculty_user_id'] === (int) $candidate['faculty_user_id'] ? $minutes : 0;
            $cohortMinutes += (int) $assignment['cohort_or_student_group_id'] === (int) $candidate['cohort_or_student_group_id'] ? $minutes : 0;
        }

        return ($roomMinutes * 100)
            + ($facultyMinutes * 10)
            + ($cohortMinutes * 10)
            + ((int) $candidate['day_of_week'] * 10)
            + intdiv($this->minutesFromMidnight($candidate['starts_at']), 30);
    }

    /**
     * @param  array<string, mixed>  $demand
     * @param  array<string, int|string|null>  $time
     * @return array<string, mixed>
     */
    private function assignment(
        array $demand,
        int $facultyId,
        array $time,
        int $meetingSequence,
    ): array {
        $meetingCount = max(1, (int) ($demand['meeting_count'] ?? 1));
        $meetingPattern = (string) ($demand['meeting_pattern']
            ?? "{$meetingCount}x{$demand['required_duration_minutes']}");

        return [
            'scheduling_demand_id' => (int) $demand['scheduling_demand_id'],
            'term_offering_id' => (int) $demand['term_offering_id'],
            'section_id' => (int) $demand['section_id'],
            'section_delivery_group_id' => (int) $demand['section_delivery_group_id'],
            'cohort_or_student_group_id' => (int) ($demand['cohort_or_student_group_id'] ?? $demand['section_delivery_group_id']),
            'subject_id' => $demand['course_id'] !== null ? (int) $demand['course_id'] : null,
            'course_component_id' => (int) $demand['course_component_id'],
            'faculty_id' => $facultyId,
            'faculty_user_id' => $facultyId,
            'room_id' => $time['room_id'],
            'day' => $time['day_of_week'],
            'day_of_week' => $time['day_of_week'],
            'start_time' => $time['starts_at'],
            'end_time' => $time['ends_at'],
            'starts_at' => $time['starts_at'],
            'ends_at' => $time['ends_at'],
            'time_slot_id' => $time['time_slot_id'],
            'time_block_reference' => $time['time_block_key'],
            'time_block_key' => $time['time_block_key'],
            'meeting_sequence' => $meetingSequence,
            'meeting_pattern' => $meetingPattern,
            'assignment_status' => 'ok',
            'violations' => [],
            'warnings' => [],
            'scores' => ['witness' => 1],
            'soft_constraint_scores' => ['witness' => 1],
        ];
    }

    private function addMinutes(string $time, int $minutes): string
    {
        $total = $this->minutesFromMidnight($time) + $minutes;

        return sprintf('%02d:%02d:00', intdiv($total, 60), $total % 60);
    }

    private function minutesBetween(string $startsAt, string $endsAt): int
    {
        return $this->minutesFromMidnight($endsAt) - $this->minutesFromMidnight($startsAt);
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function transitionGapMinutes(array $left, array $right): int
    {
        if ($left['starts_at'] >= $right['ends_at']) {
            return $this->minutesBetween($right['ends_at'], $left['starts_at']);
        }

        if ($right['starts_at'] >= $left['ends_at']) {
            return $this->minutesBetween($left['ends_at'], $right['starts_at']);
        }

        return 0;
    }

    private function minutesFromMidnight(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));

        return ($hour * 60) + $minute;
    }
}
