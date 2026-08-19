<?php

namespace App\Actions\SystemAdministration;

use App\Models\Room;
use InvalidArgumentException;

/**
 * Defines the deterministic MIN, MIDDLE, and MAX acceptance-data shapes added by TAL-96D2C.
 *
 * These manifests support testing, UAT, and defense demonstrations. They do not
 * establish production capacity limits or invoke the scheduling solver.
 */
final class SchedulingAcceptanceScenarioCatalog
{
    public const Min = 'MIN';

    public const Middle = 'MIDDLE';

    public const Max = 'MAX';

    /** @return list<string> */
    public function keys(): array
    {
        return [self::Min, self::Middle, self::Max];
    }

    public function normalize(string $scenario): string
    {
        $normalized = strtoupper(trim($scenario));

        if (! in_array($normalized, $this->keys(), true)) {
            throw new InvalidArgumentException(
                'Unknown scheduling acceptance scenario. Use MIN, MIDDLE, or MAX.',
            );
        }

        return $normalized;
    }

    public function isExternallyArranged(string $program, string $courseCode): bool
    {
        return in_array(strtoupper($program).'|'.strtoupper($courseCode), [
            'DIT|HMPRAC02',
            'DTHM|HMPRAC02',
            'DTHM|HMPRAC03',
        ], true);
    }

    /**
     * @return array{
     *     scenario:string,
     *     basis:string,
     *     limitation:string,
     *     counts:array{
     *         students:int,
     *         cohorts:int,
     *         faculty:int,
     *         offerings:int,
     *         sections:int,
     *         scheduling_demands:int
     *     },
     *     faculty_evidence:array{
     *         client_reported_faculty:int|null,
     *         synthetic_scheduling_faculty:int,
     *         total_teaching_units:float,
     *         arithmetic_faculty_lower_bound:int,
     *         max_units_per_faculty:float,
     *         maximum_constructed_load:float,
     *         availability_assumption:'FULL_OPERATING_GRID',
     *         bounded_readiness:'PASS',
     *         unassignable_workloads:list<string>,
     *         interpretation:string
     *     },
     *     curriculum_evidence:array{
     *         total_authority:'COURSE_ROWS',
     *         missing_course_policy:'DO_NOT_INVENT',
     *         third_year_second_semester:array<string,array{
     *             source_rows:int,
     *             computed_units:int,
     *             printed_subtotal:int,
     *             disposition:'ALIGNED'|'SOURCE_SUBTOTAL_DISCREPANCY'
     *         }>,
     *         historical_fixture:array{
     *             version:'TAL96D5D_SYNTHETIC_V1',
     *             scheduling_demands:80,
     *             status:'HISTORICAL_ONLY'
     *         }
     *     },
     *     operating_grid:array{days:list<int>,starts_at:string,ends_at:string,slot_minutes:int},
     *     solver_feasibility:'NOT_EVALUATED',
     *     solver_optimality:'NOT_EVALUATED'
     * }
     */
    public function manifest(string $scenario): array
    {
        $scenario = $this->normalize($scenario);
        $cohorts = $this->cohorts($scenario);
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
        $facultyEvidence = $this->facultyEvidence($scenario);

        return [
            'scenario' => $scenario,
            'basis' => $this->basis($scenario),
            'limitation' => $this->limitation($scenario),
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

    /**
     * @return array<string, array{
     *     program:string,
     *     year:string,
     *     students:int,
     *     courses:list<string>
     * }>
     */
    public function cohorts(string $scenario): array
    {
        $scenario = $this->normalize($scenario);

        if ($scenario === self::Min) {
            return [
                'DBM-1A' => $this->cohort('DBM', 'First Year', 10),
                'DBM-2A' => $this->cohort('DBM', 'Second Year', 2),
                'DIT-1A' => $this->cohort('DIT', 'First Year', 10),
                'DIT-2A' => $this->cohort('DIT', 'Second Year', 3),
                'DTHM-1A' => $this->cohort('DTHM', 'First Year', 15),
                'DTHM-2A' => $this->cohort('DTHM', 'Second Year', 7),
            ];
        }

        $cohorts = [];

        foreach ($this->programs() as $program) {
            foreach ($this->years() as $yearNumber => $year) {
                $cohortCode = "{$program}-{$yearNumber}A";
                $cohorts[$cohortCode] = $this->cohort($program, $year, 30);
            }
        }

        if ($scenario === self::Middle) {
            return $cohorts;
        }

        foreach ($this->programs() as $program) {
            foreach ($this->years() as $yearNumber => $year) {
                $cohortCode = "{$program}-{$yearNumber}B";
                $cohorts[$cohortCode] = $this->cohort($program, $year, 30);
            }
        }

        $cohorts['DBM-1C'] = $this->cohort('DBM', 'First Year', 30);
        $cohorts['DIT-1C'] = $this->cohort('DIT', 'First Year', 30);

        return $cohorts;
    }

    /** @return list<array{string, string, string}> */
    public function roomDefinitions(string $scenario): array
    {
        $scenario = $this->normalize($scenario);

        if ($scenario === self::Max) {
            return [
                ['LEC-101', 'Lecture Room 101', Room::TypeLectureRoom],
                ['LEC-102', 'Lecture Room 102', Room::TypeLectureRoom],
                ['LEC-103', 'Lecture Room 103', Room::TypeLectureRoom],
                ['LEC-104', 'Lecture Room 104', Room::TypeLectureRoom],
                ['LAB-101', 'Applied Skills Laboratory 1', Room::TypeLaboratory],
                ['LAB-102', 'Applied Skills Laboratory 2', Room::TypeLaboratory],
                ['LAB-103', 'Applied Skills Laboratory 3', Room::TypeLaboratory],
                ['COMP-101', 'Computer Laboratory 1', Room::TypeComputerLaboratory],
                ['COMP-102', 'Computer Laboratory 2', Room::TypeComputerLaboratory],
                ['COMP-103', 'Computer Laboratory 3', Room::TypeComputerLaboratory],
            ];
        }

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
            throw new InvalidArgumentException("Unknown scenario program [{$program}].");
        }

        if (! isset($courses[$program][$year])) {
            throw new InvalidArgumentException("Unknown scenario year level [{$year}].");
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

    /** @return list<string> */
    private function programs(): array
    {
        return ['DBM', 'DIT', 'DTHM'];
    }

    /** @return array<int, string> */
    private function years(): array
    {
        return [
            1 => 'First Year',
            2 => 'Second Year',
            3 => 'Third Year',
        ];
    }

    private function scopeKey(string $program, string $year): string
    {
        return "{$program}|{$year}";
    }

    private function basis(string $scenario): string
    {
        return match ($scenario) {
            self::Min => 'Current client-reported population: 47 students across six first- and second-year program cohorts.',
            self::Middle => 'Representative three-year operating scenario: one 30-student cohort for each of three programs and three year levels.',
            self::Max => 'Client-reported historical scale: 600 students represented as twenty deterministic 30-student logical cohorts; the reported fourteen faculty are evidence, not the generated scheduling roster.',
            default => throw new InvalidArgumentException("Unknown scheduling acceptance scenario [{$scenario}]."),
        };
    }

    private function limitation(string $scenario): string
    {
        return match ($scenario) {
            self::Min => 'Uses the client-reported 47 students and nine-faculty count; identities, faculty records, rooms, qualifications, and operational availability remain synthetic.',
            self::Middle => 'The 270-student population and fourteen-faculty operating roster are synthetic acceptance inputs. Current-term course rows follow the approved client-aligned curriculum disposition; identities and operational assignments remain synthetic.',
            self::Max => 'The 600-student total and fourteen-faculty count are client-reported. Fourteen faculty cannot carry 534 teaching units under the configured 21-unit ceiling, so the acceptance fixture uses a separate sufficient synthetic scheduling roster.',
            default => throw new InvalidArgumentException("Unknown scheduling acceptance scenario [{$scenario}]."),
        };
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
     *     }>,
     *     historical_fixture:array{
     *         version:'TAL96D5D_SYNTHETIC_V1',
     *         scheduling_demands:80,
     *         status:'HISTORICAL_ONLY'
     *     }
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
            'historical_fixture' => [
                'version' => 'TAL96D5D_SYNTHETIC_V1',
                'scheduling_demands' => 80,
                'status' => 'HISTORICAL_ONLY',
            ],
        ];
    }

    /**
     * These figures describe a deterministic load-and-qualification construction.
     * They do not claim timetable feasibility or a mathematically minimal roster.
     *
     * @return array{
     *     client_reported_faculty:int|null,
     *     synthetic_scheduling_faculty:int,
     *     total_teaching_units:float,
     *     arithmetic_faculty_lower_bound:int,
     *     max_units_per_faculty:float,
     *     maximum_constructed_load:float,
     *     availability_assumption:'FULL_OPERATING_GRID',
     *     bounded_readiness:'PASS',
     *     unassignable_workloads:list<string>,
     *     interpretation:string
     * }
     */
    private function facultyEvidence(string $scenario): array
    {
        return match ($scenario) {
            self::Min => [
                'client_reported_faculty' => 9,
                'synthetic_scheduling_faculty' => 9,
                'total_teaching_units' => 162.0,
                'arithmetic_faculty_lower_bound' => 8,
                'max_units_per_faculty' => 21.0,
                'maximum_constructed_load' => 19.0,
                'availability_assumption' => 'FULL_OPERATING_GRID',
                'bounded_readiness' => 'PASS',
                'unassignable_workloads' => [],
                'interpretation' => 'The client-reported nine-faculty roster passes the bounded load-and-qualification construction for the 54-demand fixture.',
            ],
            self::Middle => [
                'client_reported_faculty' => null,
                'synthetic_scheduling_faculty' => 14,
                'total_teaching_units' => 241.0,
                'arithmetic_faculty_lower_bound' => 12,
                'max_units_per_faculty' => 21.0,
                'maximum_constructed_load' => 18.0,
                'availability_assumption' => 'FULL_OPERATING_GRID',
                'bounded_readiness' => 'PASS',
                'unassignable_workloads' => [],
                'interpretation' => 'Fourteen synthetic faculty provide operating headroom above the twelve-faculty arithmetic lower bound; the count is not a midpoint or a proven minimum.',
            ],
            self::Max => [
                'client_reported_faculty' => 14,
                'synthetic_scheduling_faculty' => 26,
                'total_teaching_units' => 534.0,
                'arithmetic_faculty_lower_bound' => 26,
                'max_units_per_faculty' => 21.0,
                'maximum_constructed_load' => 21.0,
                'availability_assumption' => 'FULL_OPERATING_GRID',
                'bounded_readiness' => 'PASS',
                'unassignable_workloads' => [],
                'interpretation' => 'The historical fourteen-faculty count is preserved as evidence but is insufficient for 534 teaching units. Twenty-six is a sufficient synthetic construction, not a proven institutional minimum.',
            ],
            default => throw new InvalidArgumentException("Unknown scheduling acceptance scenario [{$scenario}]."),
        };
    }
}
