<?php

namespace App\Actions\SystemAdministration;

use App\Models\Room;
use InvalidArgumentException;

/**
 * Defines the single Canonical TALA Scheduling Dataset used by acceptance journeys.
 *
 * This manifest supports testing, UAT, and defense demonstrations. It does not
 * establish production capacity limits or invoke the scheduling solver.
 */
final class CanonicalTalaSchedulingDataset
{
    public function isExternallyArranged(string $program, string $courseCode): bool
    {
        return in_array(strtoupper($program).'|'.strtoupper($courseCode), [
            'DIT|HMPRAC02',
            'DTHM|HMPRAC02',
            'DTHM|HMPRAC03',
        ], true);
    }

    public function manifest(): array
    {
        $cohorts = $this->cohorts();
        $scopeCourseCounts = collect($cohorts)
            ->mapWithKeys(fn (array $cohort): array => [
                $this->scopeKey($cohort['program'], $cohort['year']) => count($cohort['courses']),
            ]);
        $sectionCount = collect($cohorts)->sum(
            fn (array $cohort): int => count($cohort['courses']),
        );
        $demandCount = collect($cohorts)->sum(
            fn (array $cohort): int => collect($cohort['courses'])
                ->reject(fn (string $courseCode): bool => $this->isExternallyArranged($cohort['program'], $courseCode))
                ->count(),
        );
        $facultyEvidence = $this->facultyEvidence();

        return [
            'dataset' => 'CANONICAL_TALA_SCHEDULING_DATASET',
            'basis' => $this->basis(),
            'limitation' => $this->limitation(),
            'counts' => [
                'students' => collect($cohorts)->sum('students'),
                'cohorts' => count($cohorts),
                'faculty' => $facultyEvidence['synthetic_scheduling_faculty'],
                'offerings' => $scopeCourseCounts->sum(),
                'sections' => $sectionCount,
                'scheduling_demands' => $demandCount,
            ],
            'faculty_evidence' => $facultyEvidence,
            'curriculum_evidence' => $this->curriculumEvidence(),
            'operating_grid' => [
                'days' => [1, 2, 3, 4, 5, 6],
                'starts_at' => '07:00:00',
                'ends_at' => '21:00:00',
                'slot_minutes' => 30,
            ],
            'solver_feasibility' => 'NOT_EVALUATED',
            'solver_optimality' => 'NOT_EVALUATED',
        ];
    }

    public function cohorts(): array
    {
        return [
            'DBM-1A' => $this->cohort('DBM', 'First Year', 10),
            'DBM-2A' => $this->cohort('DBM', 'Second Year', 2),
            'DIT-1A' => $this->cohort('DIT', 'First Year', 10),
            'DIT-2A' => $this->cohort('DIT', 'Second Year', 3),
            'DTHM-1A' => $this->cohort('DTHM', 'First Year', 15),
            'DTHM-2A' => $this->cohort('DTHM', 'Second Year', 7),
        ];
    }

    public function roomDefinitions(): array
    {
        return [
            ['LEC-101', 'Lecture Room 101', Room::TypeLectureRoom],
            ['LEC-102', 'Lecture Room 102', Room::TypeLectureRoom],
            ['LEC-103', 'Lecture Room 103', Room::TypeLectureRoom],
            ['LEC-104', 'Lecture Room 104', Room::TypeLectureRoom],
            ['LAB-101', 'Applied Skills Laboratory 1', Room::TypeLaboratory],
            ['LAB-102', 'Applied Skills Laboratory 2', Room::TypeLaboratory],
            ['COMP-101', 'Computer Laboratory 1', Room::TypeComputerLaboratory],
            ['COMP-102', 'Computer Laboratory 2', Room::TypeComputerLaboratory],
            ['SPEC-101', 'Specialized Demonstration Room 1', Room::TypeSpecialRoom],
            ['SPEC-102', 'Specialized Demonstration Room 2', Room::TypeSpecialRoom],
        ];
    }

    /**
     * @return list<string>
     */
    public function courseCodes(string $program, string $year): array
    {
        $courses = [
            'DBM' => [
                'First Year' => ['GE04', 'GE05', 'BME05', 'BME04', 'CSNCII', 'GE06', 'PE02', 'FOSNCII', 'NSTP02', 'BME06'],
                'Second Year' => ['AGRONCIII', 'GE10', 'GE09', 'BME09', 'BME10', 'BME11', 'BME12', 'BME13', 'PE04'],
                'Third Year' => ['THC09', 'THC10', 'HMPRAC03', 'HPC22', 'COOPMM', 'BME20', 'BME21', 'BME16'],
            ],
            'DIT' => [
                'First Year' => ['GE04', 'GE05', 'GE06', 'CC102', 'PHY101', 'CC103', 'NSTP02', 'PE02'],
                'Second Year' => ['TECH001', 'NET102', 'VGDNCIII', 'IAS101', 'DM101', 'PE04', 'HCI101', 'IAS102'],
                'Third Year' => ['SIA101', 'SF101', 'PD101', 'CAP102', 'WEBNCIII2', 'NIHONGO02', 'HMPRAC02'],
            ],
            'DTHM' => [
                'First Year' => ['HSKPNCII', 'THC05', 'THC04', 'GE04', 'GE05', 'GE06', 'THC03', 'HPC07', 'PE02', 'NSTP02'],
                'Second Year' => ['THC07', 'HPC11EMS', 'THC08', 'BME01', 'HPC13EMS', 'GE10', 'GE09', 'PE04', 'THC06'],
                'Third Year' => ['THC09', 'THC10', 'HMPRAC03', 'HPC22', 'HPC23', 'HPC24', 'HPC20', 'HMPRAC02'],
            ],
        ];

        if (! isset($courses[$program])) {
            throw new InvalidArgumentException("Unknown canonical program [{$program}].");
        }

        if (! isset($courses[$program][$year])) {
            throw new InvalidArgumentException("Unknown canonical year level [{$year}].");
        }

        return $courses[$program][$year];
    }

    /**
     * @return array{program:string,year:string,students:int,courses:list<string>}
     */
    private function cohort(string $program, string $year, int $students): array
    {
        return [
            'program' => $program,
            'year' => $year,
            'students' => $students,
            'courses' => $this->courseCodes($program, $year),
        ];
    }

    private function scopeKey(string $program, string $year): string
    {
        return "{$program}|{$year}";
    }

    private function basis(): string
    {
        return 'Current coordinated acceptance population: 47 students across six first- and second-year program cohorts.';
    }

    private function limitation(): string
    {
        return 'Uses the coordinated 47-student and nine-Faculty dataset; identities, Faculty records, rooms, qualifications, and availability are synthetic acceptance data.';
    }

    /**
     * @return array{
     *     total_authority:'COURSE_ROWS',
     *     missing_course_policy:'DO_NOT_INVENT',
     *     third_year_second_semester:array<string,array{
     *         source_rows:int,
     *         computed_units:int,
     *         printed_subtotal:int,
     *         disposition:'ALIGNED'|'SOURCE_SUBTOTAL_DISCREPANCY'
     *     }>
     * }
     */
    private function curriculumEvidence(): array
    {
        return [
            'total_authority' => 'COURSE_ROWS',
            'missing_course_policy' => 'DO_NOT_INVENT',
            'third_year_second_semester' => [
                'DBM' => [
                    'source_rows' => 8,
                    'computed_units' => 25,
                    'printed_subtotal' => 28,
                    'disposition' => 'SOURCE_SUBTOTAL_DISCREPANCY',
                ],
                'DIT' => [
                    'source_rows' => 7,
                    'computed_units' => 25,
                    'printed_subtotal' => 25,
                    'disposition' => 'ALIGNED',
                ],
                'DTHM' => [
                    'source_rows' => 8,
                    'computed_units' => 29,
                    'printed_subtotal' => 23,
                    'disposition' => 'SOURCE_SUBTOTAL_DISCREPANCY',
                ],
            ],
        ];
    }

    private function facultyEvidence(): array
    {
        return [
            'client_reported_faculty' => 9,
            'synthetic_scheduling_faculty' => 9,
            'total_teaching_units' => 162.0,
            'arithmetic_faculty_lower_bound' => 8,
            'max_units_per_faculty' => 21.0,
            'maximum_constructed_load' => 19.0,
            'availability_assumption' => 'FULL_OPERATING_GRID',
            'bounded_readiness' => 'PASS',
            'unassignable_workloads' => [],
            'interpretation' => 'Nine synthetic Faculty can carry the canonical workload within the configured 21-unit ceiling.',
        ];
    }
}
