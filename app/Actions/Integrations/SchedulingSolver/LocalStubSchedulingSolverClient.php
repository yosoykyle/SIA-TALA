<?php

namespace App\Actions\Integrations\SchedulingSolver;

use Carbon\CarbonImmutable;

class LocalStubSchedulingSolverClient implements SchedulingSolverClient
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function solve(array $snapshot): array
    {
        $assignments = [];
        $used = [];

        foreach ($this->demands($snapshot) as $demand) {
            $assignment = $this->assignmentFor($snapshot, $demand, $used);
            $assignments[] = $assignment;

            if (($assignment['assignment_status'] ?? null) === 'ok') {
                $used[] = $assignment;
            }
        }

        $conflictCount = collect($assignments)
            ->filter(fn (array $assignment): bool => ($assignment['assignment_status'] ?? null) === 'conflict')
            ->count();
        $hardViolations = collect($assignments)
            ->flatMap(fn (array $assignment): array => $assignment['violations'] ?? [])
            ->values()
            ->all();
        $objectiveScore = $conflictCount === 0 ? count($assignments) : null;

        return [
            'solver_run_id' => $snapshot['run_metadata']['solver_run_id'] ?? null,
            'solver_status' => $conflictCount > 0 ? 'infeasible' : 'optimal',
            'candidate_schedule_id' => 'local-stub-'.($snapshot['run_metadata']['solver_run_id'] ?? 'unknown'),
            'assignments' => $assignments,
            'hard_constraint_violations' => $hardViolations,
            'hard_violation_count' => $conflictCount,
            'soft_constraint_scores' => [
                'assigned_count' => count($assignments) - $conflictCount,
                'conflict_count' => $conflictCount,
                'stub_score' => $objectiveScore,
            ],
            'infeasible_reasons' => $hardViolations,
            'warnings' => [],
            'runtime_seconds' => 0.0,
            'objective_score' => $objectiveScore,
            'objective_details' => $this->objectiveDetails($snapshot, $objectiveScore),
            'solver_statistics' => [
                'ortools_version' => 'local-stub',
                'input_demand_count' => count($this->demands($snapshot)),
                'input_faculty_count' => count($snapshot['faculty'] ?? []),
                'input_room_count' => count($snapshot['rooms'] ?? []),
                'input_time_slot_count' => count($snapshot['time_slots'] ?? []),
                'candidate_count' => count($assignments),
                'model_variable_count' => 0,
                'model_constraint_count' => 0,
                'no_overlap_constraint_count' => 0,
                'best_objective_bound' => $objectiveScore,
                'relative_optimality_gap' => $objectiveScore === null ? null : 0.0,
                'boolean_variable_count' => null,
                'branch_count' => null,
                'conflict_count' => null,
                'deterministic_time_seconds' => null,
                'wall_time_seconds' => 0.0,
                'worker_count' => 1,
                'random_seed' => 20260718,
            ],
            'solver_version' => 'local-stub-tal94-demand-v2',
            'model_version' => 'tal94-demand-v2',
            'generated_at' => CarbonImmutable::now(config('app.timezone'))->toIso8601String(),
            'assigned_count' => count($assignments) - $conflictCount,
            'unassigned_count' => $conflictCount,
            'warning_count' => 0,
            'timeout' => false,
        ];
    }

    /**
     * @return array{status:int, body:string}
     */
    public function probe(): array
    {
        return [
            'status' => 200,
            'body' => 'local_stub',
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    private function demands(array $snapshot): array
    {
        return collect($snapshot['scheduling_demands'] ?? [])
            ->filter(fn (mixed $demand): bool => is_array($demand))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $demand
     * @param  list<array<string, mixed>>  $used
     * @return array<string, mixed>
     */
    private function assignmentFor(array $snapshot, array $demand, array $used): array
    {
        $violations = [];
        $facultyId = $this->facultyId($demand);
        $roomId = $this->roomId($snapshot, $demand);
        $time = $this->time($snapshot, $demand, $facultyId, $roomId, $used);

        if ($facultyId === null) {
            $violations[] = $this->violation('missing_faculty', 'No eligible faculty was available in the Scheduling Demand snapshot.');
        }

        if (($demand['room_required'] ?? false) === true && $roomId === null) {
            $violations[] = $this->violation('missing_room', 'No active room matched the Scheduling Demand room requirement.');
        }

        if ($time === null) {
            $violations[] = $this->violation('missing_time_slot', 'No non-overlapping time slot was available for the Scheduling Demand.');
        }

        $startsAt = $time['starts_at'] ?? null;
        $endsAt = $time['ends_at'] ?? null;

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
            'room_id' => $roomId,
            'day' => $time['day_of_week'] ?? null,
            'day_of_week' => $time['day_of_week'] ?? null,
            'start_time' => $startsAt,
            'end_time' => $endsAt,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'time_slot_id' => $time['time_slot_id'] ?? null,
            'time_block_reference' => $time['time_block_key'] ?? null,
            'time_block_key' => $time['time_block_key'] ?? null,
            'meeting_sequence' => 1,
            'meeting_pattern' => 'single_block',
            'assignment_status' => $violations === [] ? 'ok' : 'conflict',
            'violations' => $violations,
            'warnings' => [],
            'scores' => [
                'stub_priority' => $time['time_slot_id'] ?? null,
            ],
            'soft_constraint_scores' => [
                'stub_priority' => $time['time_slot_id'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function objectiveDetails(array $snapshot, ?int $objectiveScore): array
    {
        $profile = is_array($snapshot['constraint_profile'] ?? null)
            ? $snapshot['constraint_profile']
            : [];
        $weights = is_array($profile['soft_weights'] ?? null)
            ? $profile['soft_weights']
            : [];

        if ($objectiveScore === null) {
            return [
                'profile_key' => $profile['key'] ?? null,
                'profile_version' => $profile['version'] ?? null,
                'terms' => collect($weights)
                    ->map(fn (mixed $weight): array => [
                        'raw' => null,
                        'weight' => (int) $weight,
                    ])
                    ->all(),
            ];
        }

        $terms = [];

        foreach ($weights as $name => $weight) {
            $raw = $name === 'prefer_earlier_time_blocks' ? $objectiveScore : 0;
            $terms[$name] = [
                'raw' => $raw,
                'weight' => (int) $weight,
                'weighted' => $raw * (int) $weight,
            ];
        }

        return [
            'profile_key' => $profile['key'] ?? null,
            'profile_version' => $profile['version'] ?? null,
            'terms' => $terms,
            'total' => collect($terms)->sum('weighted'),
        ];
    }

    /**
     * @param  array<string, mixed>  $demand
     */
    private function facultyId(array $demand): ?int
    {
        if (($demand['fixed_faculty_user_id'] ?? null) !== null) {
            return (int) $demand['fixed_faculty_user_id'];
        }

        $eligible = $demand['eligible_faculty_user_ids'] ?? [];

        return is_array($eligible) && $eligible !== [] ? (int) $eligible[0] : null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $demand
     */
    private function roomId(array $snapshot, array $demand): ?int
    {
        if (($demand['room_required'] ?? false) !== true) {
            return null;
        }

        $fixedRoomId = ($demand['fixed_room_id'] ?? null) !== null ? (int) $demand['fixed_room_id'] : null;
        $roomType = $demand['room_type_requirement'] ?? null;
        $expectedCount = (int) ($demand['expected_count'] ?? 0);
        $requiredFeatures = collect($demand['required_room_feature_keys'] ?? [])
            ->map(fn (mixed $key): string => strtoupper((string) $key));

        foreach (($snapshot['rooms'] ?? []) as $room) {
            if (! is_array($room)) {
                continue;
            }

            if ($fixedRoomId !== null && (int) $room['room_id'] !== $fixedRoomId) {
                continue;
            }

            if ($roomType !== null && ($room['room_type'] ?? null) !== $roomType) {
                continue;
            }

            if ((int) ($room['capacity'] ?? 0) < $expectedCount) {
                continue;
            }

            $roomFeatures = collect($room['feature_keys'] ?? [])
                ->map(fn (mixed $key): string => strtoupper((string) $key));

            if ($requiredFeatures->diff($roomFeatures)->isNotEmpty()) {
                continue;
            }

            return (int) $room['room_id'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $demand
     * @param  list<array<string, mixed>>  $used
     * @return array<string, mixed>|null
     */
    private function time(array $snapshot, array $demand, ?int $facultyId, ?int $roomId, array $used): ?array
    {
        $dayEndsAt = (string) ($snapshot['term']['scheduling_day_ends_at'] ?? '21:00:00');

        if (($demand['fixed_day_of_week'] ?? null) !== null && ($demand['fixed_start_time'] ?? null) !== null) {
            $startsAt = (string) $demand['fixed_start_time'];
            $endsAt = $this->addMinutes($startsAt, (int) $demand['required_duration_minutes']);

            return [
                'time_slot_id' => null,
                'time_block_key' => 'fixed-'.$demand['scheduling_demand_id'],
                'day_of_week' => (int) $demand['fixed_day_of_week'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ];
        }

        foreach (($snapshot['time_slots'] ?? []) as $slot) {
            if (! is_array($slot)) {
                continue;
            }

            $startsAt = (string) $slot['starts_at'];
            $endsAt = $this->addMinutes($startsAt, (int) $demand['required_duration_minutes']);

            if ($endsAt > $dayEndsAt) {
                continue;
            }

            $candidate = [
                'section_delivery_group_id' => (int) $demand['section_delivery_group_id'],
                'cohort_or_student_group_id' => (int) ($demand['cohort_or_student_group_id'] ?? $demand['section_delivery_group_id']),
                'faculty_id' => $facultyId,
                'room_id' => $roomId,
                'day_of_week' => (int) $slot['day_of_week'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ];

            if ($this->overlapsUsed($candidate, $used)) {
                continue;
            }

            return [
                'time_slot_id' => (int) $slot['time_slot_id'],
                'time_block_key' => (string) $slot['time_block_key'],
                'day_of_week' => (int) $slot['day_of_week'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  list<array<string, mixed>>  $used
     */
    private function overlapsUsed(array $candidate, array $used): bool
    {
        foreach ($used as $assignment) {
            if ((int) ($assignment['day_of_week'] ?? 0) !== (int) $candidate['day_of_week']) {
                continue;
            }

            if (($candidate['starts_at'] ?? '') >= ($assignment['ends_at'] ?? '')
                || ($candidate['ends_at'] ?? '') <= ($assignment['starts_at'] ?? '')) {
                continue;
            }

            if ((int) ($assignment['cohort_or_student_group_id'] ?? 0) === (int) $candidate['cohort_or_student_group_id']) {
                return true;
            }

            if ($candidate['faculty_id'] !== null && (int) ($assignment['faculty_id'] ?? 0) === (int) $candidate['faculty_id']) {
                return true;
            }

            if ($candidate['room_id'] !== null && (int) ($assignment['room_id'] ?? 0) === (int) $candidate['room_id']) {
                return true;
            }
        }

        return false;
    }

    private function addMinutes(string $time, int $minutes): string
    {
        [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));
        $total = ($hour * 60) + $minute + $minutes;

        return sprintf('%02d:%02d:00', intdiv($total, 60), $total % 60);
    }

    /**
     * @return array{type:string,message:string}
     */
    private function violation(string $type, string $message): array
    {
        return [
            'type' => $type,
            'message' => $message,
        ];
    }
}
