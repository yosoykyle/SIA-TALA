<?php

namespace App\Actions\Academics;

use App\Models\CourseSpecification;
use App\Models\StudentProfile;

class CumulativeGwaProjection
{
    public function __construct(private readonly OfficialCourseResultProjection $results) {}

    /** @return array{value: ?string, through: ?string, included_attempts: int} */
    public function forStudent(StudentProfile $student): array
    {
        $included = $this->results->forStudent($student)
            ->filter(function (array $result): bool {
                $courseSpecification = $result['course_specification'];

                return is_numeric($result['result'])
                    && $courseSpecification instanceof CourseSpecification
                    && ! in_array($courseSpecification->academic_classification, [
                        CourseSpecification::AcademicClassificationPe,
                        CourseSpecification::AcademicClassificationNstp,
                    ], true);
            });

        if ($included->isEmpty()) {
            return ['value' => null, 'through' => null, 'included_attempts' => 0];
        }

        $units = $included->sum(fn (array $result): float => (float) $result['units']);
        $weighted = $included->sum(fn (array $result): float => (float) $result['result'] * (float) $result['units']);
        $through = $included->sortBy(fn (array $result): string => (string) $result['term']?->ends_on)->last()['term']?->label;

        return [
            'value' => number_format(round($weighted / $units, 2, PHP_ROUND_HALF_UP), 2, '.', ''),
            'through' => $through,
            'included_attempts' => $included->count(),
        ];
    }
}
