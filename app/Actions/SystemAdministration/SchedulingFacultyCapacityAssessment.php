<?php

namespace App\Actions\SystemAdministration;

use InvalidArgumentException;
use RuntimeException;

class SchedulingFacultyCapacityAssessment
{
    /**
     * This bounded acceptance-fixture calculation proves only that the supplied
     * workloads can be assigned within the declared load and qualification
     * inputs. It is not a CP-SAT solve and does not prove timetable feasibility.
     *
     * @param  list<array{key:string,course_code:string,units:float|int}>  $workloads
     * @param  array<string, list<int>>|null  $eligibleFacultyByCourse
     * @return array{
     *     readiness:'PASS'|'FAIL',
     *     total_teaching_units:float,
     *     arithmetic_faculty_lower_bound:int,
     *     faculty_loads:list<float>,
     *     maximum_constructed_load:float,
     *     assignments:list<array{workload_key:string,course_code:string,units:float,faculty_index:int}>,
     *     faculty_course_codes:array<int,list<string>>,
     *     unassigned_workloads:list<string>
     * }
     */
    public function assess(
        array $workloads,
        int $facultyCount,
        float $maxUnits = 21.0,
        ?array $eligibleFacultyByCourse = null,
    ): array {
        if ($facultyCount < 1) {
            throw new InvalidArgumentException('Faculty count must be at least one.');
        }

        if ($maxUnits <= 0) {
            throw new InvalidArgumentException('Maximum units must be greater than zero.');
        }

        usort($workloads, function (array $left, array $right): int {
            $unitComparison = (float) $right['units'] <=> (float) $left['units'];

            if ($unitComparison !== 0) {
                return $unitComparison;
            }

            return [$left['course_code'], $left['key']]
                <=> [$right['course_code'], $right['key']];
        });

        $totalTeachingUnits = array_sum(array_map(
            fn (array $workload): float => (float) $workload['units'],
            $workloads,
        ));
        $facultyLoads = array_fill(0, $facultyCount, 0.0);
        $facultyCourseCodes = array_fill(0, $facultyCount, []);
        $assignments = [];
        $unassignedWorkloads = [];

        foreach ($workloads as $workload) {
            $units = (float) $workload['units'];
            $courseCode = $workload['course_code'];
            $eligibleFaculty = $eligibleFacultyByCourse !== null
                && array_key_exists($courseCode, $eligibleFacultyByCourse)
                    ? $eligibleFacultyByCourse[$courseCode]
                    : range(0, $facultyCount - 1);

            $eligibleFaculty = array_values(array_filter(
                array_unique($eligibleFaculty),
                fn (int $facultyIndex): bool => $facultyIndex >= 0
                    && $facultyIndex < $facultyCount
                    && $maxUnits >= $facultyLoads[$facultyIndex] + $units,
            ));

            usort(
                $eligibleFaculty,
                fn (int $left, int $right): int => [$facultyLoads[$left], $left]
                    <=> [$facultyLoads[$right], $right],
            );

            if ($eligibleFaculty === []) {
                $unassignedWorkloads[] = $workload['key'];

                continue;
            }

            $facultyIndex = $eligibleFaculty[0];
            $facultyLoads[$facultyIndex] += $units;
            $facultyCourseCodes[$facultyIndex][] = $courseCode;
            $assignments[] = [
                'workload_key' => $workload['key'],
                'course_code' => $courseCode,
                'units' => $units,
                'faculty_index' => $facultyIndex,
            ];
        }

        $facultyCourseCodes = array_map(function (array $courseCodes): array {
            $courseCodes = array_values(array_unique($courseCodes));
            sort($courseCodes);

            return $courseCodes;
        }, $facultyCourseCodes);

        return [
            'readiness' => $unassignedWorkloads === [] ? 'PASS' : 'FAIL',
            'total_teaching_units' => $totalTeachingUnits,
            'arithmetic_faculty_lower_bound' => (int) ceil($totalTeachingUnits / $maxUnits),
            'faculty_loads' => $facultyLoads,
            'maximum_constructed_load' => max($facultyLoads),
            'assignments' => $assignments,
            'faculty_course_codes' => $facultyCourseCodes,
            'unassigned_workloads' => $unassignedWorkloads,
        ];
    }

    /**
     * Returns the first roster size passed by this deterministic construction.
     * The result is sufficient evidence, not a mathematical proof of minimality.
     *
     * @param  list<array{key:string,course_code:string,units:float|int}>  $workloads
     */
    public function firstPassingFacultyCount(
        array $workloads,
        int $startingFacultyCount,
        int $maximumFacultyCount,
        float $maxUnits = 21.0,
    ): int {
        if ($startingFacultyCount > $maximumFacultyCount) {
            throw new InvalidArgumentException('Starting faculty count cannot exceed the maximum faculty count.');
        }

        for ($facultyCount = $startingFacultyCount; $facultyCount <= $maximumFacultyCount; $facultyCount++) {
            if ($this->assess($workloads, $facultyCount, $maxUnits)['readiness'] === 'PASS') {
                return $facultyCount;
            }
        }

        throw new RuntimeException('No sufficient faculty roster was found within the bounded search.');
    }
}
