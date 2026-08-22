<?php

namespace App\Actions\Academics;

use App\Models\CourseSpecification;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermAverageLabel;

class TermWeightedAverageProjection
{
    public function __construct(
        private readonly OfficialCourseResultProjection $results,
        private readonly AcademicAverageReadiness $readiness,
    ) {}

    /** @return array{state: string, value: ?string, label: string, reason: ?string, through: ?string} */
    public function forStudentAndTerm(StudentProfile $student, Term $term): array
    {
        $results = $this->results->forStudent($student, $term);
        $state = $this->readiness->forResults($results);
        $label = TermAverageLabel::query()->where('term_id', $term->id)->where('is_current', true)->latest('recorded_at')->value('label')
            ?? 'Term weighted average';

        if ($state !== AcademicAverageReadiness::Available) {
            return ['state' => $state, 'value' => null, 'label' => $label, 'reason' => $this->reasonFor($state), 'through' => null];
        }

        if ($results->contains(fn (array $result): bool => $result['course_specification']?->academic_classification === null)) {
            return [
                'state' => AcademicAverageReadiness::GradesNotComplete,
                'value' => null,
                'label' => $label,
                'reason' => 'Course academic classification is not recorded.',
                'through' => null,
            ];
        }

        $included = $results->filter(fn (array $result): bool => is_numeric($result['result'])
            && ! in_array($result['course_specification']?->academic_classification, [
                CourseSpecification::AcademicClassificationPe,
                CourseSpecification::AcademicClassificationNstp,
            ], true));
        $units = $included->sum(fn (array $result): float => (float) $result['units']);
        $weighted = $included->sum(fn (array $result): float => (float) $result['result'] * (float) $result['units']);

        return [
            'state' => AcademicAverageReadiness::Available,
            'value' => number_format(round($weighted / $units, 2, PHP_ROUND_HALF_UP), 2, '.', ''),
            'label' => $label,
            'reason' => null,
            'through' => $term->label,
        ];
    }

    private function reasonFor(string $state): string
    {
        return match ($state) {
            AcademicAverageReadiness::GradesNotComplete => 'Official results are not complete for this term.',
            AcademicAverageReadiness::IncompleteResultPending => 'An INC remains unresolved for this term.',
            AcademicAverageReadiness::NotApplicable => 'No included numeric units apply to this term.',
            default => 'Academic average is unavailable.',
        };
    }
}
