<?php

namespace Tests\Unit;

use App\Actions\SystemAdministration\SchedulingFacultyCapacityAssessment;
use PHPUnit\Framework\TestCase;

class TAL96D2CSchedulingFacultyCapacityAssessmentTest extends TestCase
{
    public function test_it_constructs_a_deterministic_qualification_aware_load_assignment(): void
    {
        $assessment = (new SchedulingFacultyCapacityAssessment)->assess(
            workloads: [
                ['key' => 'A-1', 'course_code' => 'A', 'units' => 4.0],
                ['key' => 'A-2', 'course_code' => 'A', 'units' => 4.0],
                ['key' => 'B-1', 'course_code' => 'B', 'units' => 4.0],
                ['key' => 'B-2', 'course_code' => 'B', 'units' => 4.0],
            ],
            facultyCount: 2,
            maxUnits: 8.0,
            eligibleFacultyByCourse: [
                'A' => [0],
                'B' => [1],
            ],
        );

        $this->assertSame('PASS', $assessment['readiness']);
        $this->assertSame(16.0, $assessment['total_teaching_units']);
        $this->assertSame(2, $assessment['arithmetic_faculty_lower_bound']);
        $this->assertSame([8.0, 8.0], $assessment['faculty_loads']);
        $this->assertSame([], $assessment['unassigned_workloads']);
    }

    public function test_it_reports_qualification_gaps_and_load_ceiling_failures(): void
    {
        $qualificationGap = (new SchedulingFacultyCapacityAssessment)->assess(
            workloads: [
                ['key' => 'A-1', 'course_code' => 'A', 'units' => 3.0],
            ],
            facultyCount: 1,
            maxUnits: 21.0,
            eligibleFacultyByCourse: ['A' => []],
        );
        $overload = (new SchedulingFacultyCapacityAssessment)->assess(
            workloads: [
                ['key' => 'A-1', 'course_code' => 'A', 'units' => 12.0],
                ['key' => 'A-2', 'course_code' => 'A', 'units' => 12.0],
            ],
            facultyCount: 1,
            maxUnits: 21.0,
            eligibleFacultyByCourse: ['A' => [0]],
        );

        $this->assertSame('FAIL', $qualificationGap['readiness']);
        $this->assertSame(['A-1'], $qualificationGap['unassigned_workloads']);
        $this->assertSame('FAIL', $overload['readiness']);
        $this->assertSame(['A-2'], $overload['unassigned_workloads']);
    }

    public function test_it_finds_the_first_sufficient_roster_without_calling_it_a_proven_minimum(): void
    {
        $facultyCount = (new SchedulingFacultyCapacityAssessment)->firstPassingFacultyCount(
            workloads: [
                ['key' => 'A-1', 'course_code' => 'A', 'units' => 11.0],
                ['key' => 'B-1', 'course_code' => 'B', 'units' => 11.0],
            ],
            startingFacultyCount: 1,
            maximumFacultyCount: 3,
            maxUnits: 21.0,
        );

        $this->assertSame(2, $facultyCount);
    }
}
