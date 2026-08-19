<?php

namespace App\Actions\Scheduling;

final class SchedulingParityEvidenceBuilder
{
    /**
     * @param  array<string, mixed>  $capture
     * @param  list<array<string, mixed>>  $assignments
     * @return array<string, mixed>
     */
    public function build(
        array $capture,
        array $assignments,
        ScheduleValidationResult $validation,
    ): array {
        $snapshot = $this->allowlistedSnapshot($capture['snapshot']);
        $assignments = array_map(
            fn (array $assignment): array => $this->allowlistedAssignment($assignment),
            $assignments,
        );
        $assignmentSha256 = $this->normalizedHash($assignments);
        $payloadSha256 = $this->normalizedHash([
            'snapshot' => $snapshot,
            'assignments' => $assignments,
        ]);

        return [
            'evidence_version' => 'tal96d5d-parity-v2',
            'dataset' => (string) data_get($capture, 'manifest.dataset'),
            'contract_version' => (string) data_get($capture, 'snapshot.contract_version'),
            'manifest' => $capture['manifest'],
            'composition' => $capture['composition'],
            'snapshot_sha256' => $capture['snapshot_sha256'],
            'assignment_sha256' => $assignmentSha256,
            'payload_sha256' => $payloadSha256,
            'laravel_validation' => [
                'passes' => $validation->passes(),
                'assignment_count' => count($assignments),
                'finding_codes' => collect($validation->findings())
                    ->pluck('code')
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values()
                    ->all(),
            ],
            'snapshot' => $snapshot,
            'assignments' => $assignments,
        ];
    }

    /** @param array<string, mixed> $artifact */
    public function hasValidPayloadHash(array $artifact): bool
    {
        return hash_equals(
            (string) ($artifact['payload_sha256'] ?? ''),
            $this->normalizedHash([
                'snapshot' => $artifact['snapshot'] ?? [],
                'assignments' => $artifact['assignments'] ?? [],
            ]),
        );
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function allowlistedSnapshot(array $snapshot): array
    {
        return [
            'contract_version' => $snapshot['contract_version'] ?? null,
            'constraint_profile' => $this->only(
                $this->arrayValue($snapshot['constraint_profile'] ?? null),
                ['key', 'version', 'hard_constraints', 'soft_weights'],
            ),
            'term' => $this->only($this->arrayValue($snapshot['term'] ?? null), [
                'term_id',
                'scheduling_slot_minutes',
                'scheduling_days',
                'scheduling_day_starts_at',
                'scheduling_day_ends_at',
                'default_max_units',
            ]),
            'student_cohort_groups' => $this->allowlistedRows($snapshot['student_cohort_groups'] ?? [], [
                'cohort_or_student_group_id',
                'section_delivery_group_id',
                'expected_count',
            ]),
            'scheduling_demands' => $this->allowlistedDemands($snapshot['scheduling_demands'] ?? []),
            'rooms' => $this->allowlistedRows($snapshot['rooms'] ?? [], [
                'room_id',
                'room_type',
                'capacity',
                'feature_keys',
            ]),
            'faculty' => $this->allowlistedRows($snapshot['faculty'] ?? [], [
                'faculty_id',
                'max_allowed_units',
            ]),
            'faculty_availability' => $this->allowlistedRows($snapshot['faculty_availability'] ?? [], [
                'faculty_id',
                'faculty_user_id',
                'day_of_week',
                'starts_at',
                'ends_at',
            ]),
            'existing_commitments' => $this->allowlistedRows($snapshot['existing_commitments'] ?? [], [
                'section_delivery_group_id',
                'faculty_id',
                'faculty_user_id',
                'room_id',
                'day_of_week',
                'starts_at',
                'ends_at',
            ]),
            'calendar_blocks' => $this->allowlistedRows($snapshot['calendar_blocks'] ?? [], [
                'calendar_event_id',
                'event_type',
                'scope_type',
                'faculty_user_id',
                'room_id',
                'day_of_week',
                'starts_at',
                'ends_at',
                'start_at',
                'end_at',
            ]),
            'time_slots' => $this->allowlistedRows($snapshot['time_slots'] ?? [], [
                'time_slot_id',
                'time_block_key',
                'day_of_week',
                'starts_at',
                'ends_at',
                'duration_minutes',
            ]),
        ];
    }

    /** @param array<string, mixed> $assignment
     * @return array<string, mixed>
     */
    private function allowlistedAssignment(array $assignment): array
    {
        return $this->only($assignment, [
            'scheduling_demand_id',
            'term_offering_id',
            'section_id',
            'section_delivery_group_id',
            'cohort_or_student_group_id',
            'subject_id',
            'course_component_id',
            'faculty_id',
            'faculty_user_id',
            'room_id',
            'day',
            'day_of_week',
            'start_time',
            'end_time',
            'starts_at',
            'ends_at',
            'time_slot_id',
            'time_block_reference',
            'time_block_key',
            'meeting_sequence',
            'assignment_status',
        ]);
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function only(array $source, array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $source)) {
                $result[$key] = $source[$key];
            }
        }

        return $result;
    }

    /**
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function allowlistedRows(mixed $rows, array $keys): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_map(
            fn (array $row): array => $this->only($row, $keys),
            array_filter($rows, 'is_array'),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allowlistedDemands(mixed $demands): array
    {
        $keys = [
            'scheduling_demand_id',
            'demand_key',
            'term_offering_id',
            'section_id',
            'section_delivery_group_id',
            'cohort_or_student_group_id',
            'course_id',
            'subject_id',
            'course_component_id',
            'required_duration_minutes',
            'meeting_count',
            'modality',
            'expected_count',
            'section_capacity',
            'room_type_requirement',
            'required_room_feature_keys',
            'load_units',
            'room_required',
            'same_faculty_required',
            'requires_consecutive_block',
            'eligible_faculty_user_ids',
            'fixed_faculty_user_id',
            'fixed_room_id',
            'fixed_day_of_week',
            'fixed_start_time',
        ];

        if (! is_array($demands)) {
            return [];
        }

        return array_values(array_map(function (array $demand) use ($keys): array {
            $result = $this->only($demand, $keys);
            $result['faculty_load_options'] = $this->allowlistedRows(
                $demand['faculty_load_options'] ?? [],
                [
                    'faculty_user_id',
                    'qualification_id',
                    'term_load_override_id',
                    'max_allowed_units',
                ],
            );

            return $result;
        }, array_filter($demands, 'is_array')));
    }

    /** @return array<string, mixed> */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function normalizedHash(mixed $value): string
    {
        return hash(
            'sha256',
            json_encode($this->normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
    }
}
