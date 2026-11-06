<?php

namespace App\Actions\Academics;

use App\Models\StudentProfile;
use App\Models\Term;

class CumulativeGwaProjection
{
    public function __construct(
        private readonly OfficialCourseResultProjection $results,
        private readonly AcademicAverageReadiness $readiness,
    ) {}

    /** @return array{value: ?string, through: ?string, included_attempts: int, state: string, label: string, reason: ?string, as_of: string, exclusions: list<string>} */
    public function forStudent(StudentProfile $student, ?Term $throughTerm = null): array
    {
        $results = $this->results->forStudent($student)
            ->when($throughTerm, fn ($attempts) => $attempts->filter(
                fn (array $result): bool => $this->termOrder($result['term']) <= $this->termOrder($throughTerm),
            ));
        $terms = $results->groupBy(fn (array $result): int => (int) $result['term']?->id)
            ->sortBy(fn ($termResults): string => $this->termOrder($termResults->first()['term']));
        $completeResults = collect();
        $through = null;
        $blockedState = null;
        $blockedTerm = null;

        foreach ($terms as $termResults) {
            $term = $termResults->first()['term'];
            $state = $this->readiness->forResults($termResults);
            if (in_array($state, [AcademicAverageReadiness::GradesNotComplete, AcademicAverageReadiness::IncompleteResultPending], true)) {
                $blockedState = $state;
                $blockedTerm = $term?->label;

                break;
            }

            $completeResults = $completeResults->concat($termResults);
            $through = $term?->label;
        }

        $value = $this->readiness->average($completeResults);

        return [
            'value' => $value,
            'through' => $value === null ? null : $through,
            'included_attempts' => $this->readiness->includedResults($completeResults)->count(),
            'state' => $blockedState ?? ($value === null ? AcademicAverageReadiness::NotApplicable : AcademicAverageReadiness::Available),
            'label' => 'Cumulative GWA',
            'reason' => match ($blockedState) {
                AcademicAverageReadiness::GradesNotComplete => 'Grades not complete for '.$blockedTerm.'.',
                AcademicAverageReadiness::IncompleteResultPending => 'Current academic average pending — incomplete grade unresolved in '.$blockedTerm.'.',
                default => $value === null ? 'Not applicable — no included academic units.' : null,
            },
            'as_of' => now('Asia/Manila')->toIso8601String(),
            'exclusions' => $completeResults->pluck('average_exclusion')->filter()->unique()->values()->all(),
        ];
    }

    private function termOrder(?Term $term): string
    {
        return ($term?->ends_on?->toDateString() ?? '').'|'.($term?->starts_on?->toDateString() ?? '').'|'.sprintf('%010d', $term?->id ?? 0);
    }
}
