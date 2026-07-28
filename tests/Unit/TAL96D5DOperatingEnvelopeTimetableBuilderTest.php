<?php

namespace Tests\Unit;

use App\Actions\Scheduling\SchedulingOperatingEnvelopeTimetableBuilder;
use PHPUnit\Framework\TestCase;

final class TAL96D5DOperatingEnvelopeTimetableBuilderTest extends TestCase
{
    public function test_it_builds_sanitized_readable_section_faculty_and_room_projections(): void
    {
        $evidence = (new SchedulingOperatingEnvelopeTimetableBuilder)->build(
            snapshot: [
                'scheduling_demands' => [[
                    'scheduling_demand_id' => 41,
                    'section_id' => 11,
                    'course_code' => 'CC102',
                    'modality' => 'FACE_TO_FACE',
                ]],
                'rooms' => [[
                    'room_id' => 31,
                    'code' => 'COMP-101',
                ]],
            ],
            assignments: [[
                'scheduling_demand_id' => 41,
                'faculty_user_id' => 21,
                'room_id' => 31,
                'day_of_week' => 2,
                'starts_at' => '09:00:00',
                'ends_at' => '12:00:00',
                'assignment_status' => 'ok',
            ]],
            labels: [
                'sections' => [11 => 'DIT-1A'],
                'faculty' => [21 => 'Faculty 01'],
            ],
        );

        $this->assertSame(1, $evidence['assignment_count']);
        $this->assertSame('DIT-1A', $evidence['assignments'][0]['section']);
        $this->assertSame('CC102', $evidence['assignments'][0]['course']);
        $this->assertSame('Faculty 01', $evidence['assignments'][0]['faculty']);
        $this->assertSame('COMP-101', $evidence['assignments'][0]['room']);
        $this->assertSame('Tuesday', $evidence['assignments'][0]['day']);
        $this->assertSame('09:00-12:00', $evidence['assignments'][0]['time']);
        $this->assertCount(1, $evidence['section_timetables']['DIT-1A']);
        $this->assertCount(1, $evidence['faculty_timetables']['Faculty 01']);
        $this->assertCount(1, $evidence['room_timetables']['COMP-101']);
        $this->assertStringNotContainsString('email', mb_strtolower(json_encode($evidence, JSON_THROW_ON_ERROR)));
    }
}
