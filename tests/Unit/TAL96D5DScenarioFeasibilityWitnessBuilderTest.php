<?php

namespace Tests\Unit;

use App\Actions\Scheduling\ScheduleValidationResult;
use App\Actions\Scheduling\SchedulingParityEvidenceBuilder;
use App\Actions\Scheduling\SchedulingScenarioFeasibilityWitnessBuilder;
use PHPUnit\Framework\TestCase;

final class TAL96D5DScenarioFeasibilityWitnessBuilderTest extends TestCase
{
    public function test_it_builds_a_complete_non_overlapping_witness_within_faculty_load_limits(): void
    {
        $snapshot = [
            'term' => [
                'scheduling_days' => [1],
                'scheduling_day_starts_at' => '07:00:00',
                'scheduling_day_ends_at' => '12:00:00',
            ],
            'faculty' => [
                ['faculty_id' => 10, 'max_allowed_units' => '3.00'],
                ['faculty_id' => 11, 'max_allowed_units' => '3.00'],
            ],
            'faculty_availability' => [],
            'calendar_blocks' => [],
            'rooms' => [
                ['room_id' => 20, 'room_type' => 'LECTURE_ROOM', 'capacity' => 40, 'feature_keys' => []],
            ],
            'time_slots' => [
                ['time_slot_id' => 1, 'day_of_week' => 1, 'starts_at' => '07:00:00', 'time_block_key' => 'D1-0700'],
                ['time_slot_id' => 2, 'day_of_week' => 1, 'starts_at' => '08:00:00', 'time_block_key' => 'D1-0800'],
                ['time_slot_id' => 3, 'day_of_week' => 1, 'starts_at' => '09:00:00', 'time_block_key' => 'D1-0900'],
            ],
            'scheduling_demands' => [
                $this->demand(1, 1, 100, [10, 11]),
                $this->demand(2, 2, 100, [10, 11]),
            ],
        ];

        $assignments = (new SchedulingScenarioFeasibilityWitnessBuilder)->build($snapshot);

        $this->assertCount(2, $assignments);
        $this->assertSame([1, 2], array_column($assignments, 'scheduling_demand_id'));
        $this->assertSame([10, 11], array_column($assignments, 'faculty_user_id'));
        $this->assertSame(['ok', 'ok'], array_column($assignments, 'assignment_status'));
        $this->assertNotSame($assignments[0]['starts_at'], $assignments[1]['starts_at']);
    }

    public function test_private_parity_evidence_is_deterministic_allowlisted_and_tamper_evident(): void
    {
        $snapshot = [
            'contract_version' => 'tal94-demand-v2',
            'captured_by_email' => 'registrar@example.test',
            'constraint_profile' => [
                'key' => 'balanced_v1',
                'version' => 1,
                'hard_constraints' => [],
                'soft_weights' => [],
                'secret' => 'not-allowed',
            ],
            'term' => [
                'term_id' => 1,
                'scheduling_slot_minutes' => 30,
                'scheduling_days' => [1],
                'scheduling_day_starts_at' => '08:00:00',
                'scheduling_day_ends_at' => '18:00:00',
            ],
            'student_cohort_groups' => [],
            'scheduling_demands' => [[
                ...$this->demand(1, 1, 100, [10]),
                'faculty_load_options' => [[
                    'faculty_user_id' => 10,
                    'qualification_id' => 20,
                    'max_allowed_units' => '3.00',
                    'email' => 'faculty@example.test',
                ]],
            ]],
            'rooms' => [[
                'room_id' => 20,
                'room_type' => 'LECTURE_ROOM',
                'capacity' => 40,
                'feature_keys' => [],
                'name' => 'Private room label',
            ]],
            'faculty' => [[
                'faculty_id' => 10,
                'max_allowed_units' => '3.00',
                'name' => 'Private faculty name',
            ]],
            'faculty_availability' => [],
            'existing_commitments' => [],
            'calendar_blocks' => [],
            'time_slots' => [[
                'time_slot_id' => 1,
                'time_block_key' => 'D1-0800',
                'day_of_week' => 1,
                'starts_at' => '08:00:00',
                'ends_at' => '08:30:00',
            ]],
        ];
        $assignments = [[
            'scheduling_demand_id' => 1,
            'term_offering_id' => 1,
            'section_id' => 1,
            'section_delivery_group_id' => 1,
            'cohort_or_student_group_id' => 100,
            'subject_id' => 1,
            'course_component_id' => 1,
            'faculty_id' => 10,
            'faculty_user_id' => 10,
            'room_id' => 20,
            'day' => 1,
            'day_of_week' => 1,
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
            'starts_at' => '08:00:00',
            'ends_at' => '09:00:00',
            'time_slot_id' => 1,
            'time_block_reference' => 'D1-0800',
            'time_block_key' => 'D1-0800',
            'meeting_sequence' => 1,
            'assignment_status' => 'ok',
            'private_note' => 'not-allowed',
        ]];
        $capture = [
            'snapshot' => $snapshot,
            'snapshot_sha256' => str_repeat('a', 64),
            'manifest' => ['scenario' => 'MAX', 'counts' => ['scheduling_demands' => 1]],
            'composition' => ['demands' => 1, 'faculty' => 1, 'rooms' => 1, 'time_slots' => 1],
        ];
        $validation = new ScheduleValidationResult([], [], [], []);
        $builder = new SchedulingParityEvidenceBuilder;

        $first = $builder->build($capture, $assignments, $validation);
        $second = $builder->build($capture, $assignments, $validation);
        $encoded = json_encode($first, JSON_THROW_ON_ERROR);

        $this->assertSame($first, $second);
        $this->assertSame('tal96d5d-parity-v2', $first['evidence_version']);
        $this->assertSame('ok', $first['assignments'][0]['assignment_status']);
        $this->assertTrue($builder->hasValidPayloadHash($first));
        $this->assertStringNotContainsString('registrar@example.test', $encoded);
        $this->assertStringNotContainsString('faculty@example.test', $encoded);
        $this->assertStringNotContainsString('Private faculty name', $encoded);
        $this->assertStringNotContainsString('Private room label', $encoded);
        $this->assertStringNotContainsString('not-allowed', $encoded);

        $first['assignments'][0]['starts_at'] = '08:13:00';

        $this->assertFalse($builder->hasValidPayloadHash($first));
    }

    /** @param list<int> $eligibleFaculty */
    private function demand(int $id, int $deliveryGroup, int $cohort, array $eligibleFaculty): array
    {
        return [
            'scheduling_demand_id' => $id,
            'term_offering_id' => $id,
            'section_id' => $deliveryGroup,
            'section_delivery_group_id' => $deliveryGroup,
            'cohort_or_student_group_id' => $cohort,
            'course_id' => $id,
            'course_component_id' => $id,
            'meeting_count' => 1,
            'load_units' => '3.00',
            'eligible_faculty_user_ids' => $eligibleFaculty,
            'fixed_faculty_user_id' => null,
            'room_required' => true,
            'room_type_requirement' => 'LECTURE_ROOM',
            'required_room_feature_keys' => [],
            'expected_count' => 30,
            'required_duration_minutes' => 60,
            'fixed_room_id' => null,
            'fixed_day_of_week' => null,
            'fixed_start_time' => null,
            'same_faculty_required' => true,
        ];
    }
}
