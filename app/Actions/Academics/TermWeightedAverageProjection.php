<?php

namespace App\Actions\Academics;

use App\Models\StudentProfile;
use App\Models\Term;
use Illuminate\Support\Collection;

class TermWeightedAverageProjection
{
    public function __construct(
        private readonly OfficialCourseResultProjection $results,
        private readonly AcademicAverageReadiness $readiness,
    ) {}

    /** @return array{state: string, value: ?string, label: string, reason: ?string, through: ?string, as_of: string, exclusions: list<string>} */
    public function forStudentAndTerm(StudentProfile $student, Term $term): array
    {
        return $this->forResults($this->results->forStudent($student, $term), $term);
    }

    /** @param Collection<int, array<string, mixed>> $results
     * @return array{state: string, value: ?string, label: string, reason: ?string, through: ?string, as_of: string, exclusions: list<string>}
     */
    public function forResults(Collection $results, Term $term): array
    {
        return $this->forPeriodResults($results->where('term.id', $term->id), (string) $term->label);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $periodResults
     * @return array{state: string, value: ?string, label: string, reason: ?string, through: ?string, as_of: string, exclusions: list<string>}
     */
    public function forPeriodResults(Collection $periodResults, string $periodLabel): array
    {
        $state = $this->readiness->forResults($periodResults);

        return [
            'state' => $state,
            'value' => $state === AcademicAverageReadiness::Available ? $this->readiness->average($periodResults) : null,
            'label' => 'Term weighted average',
            'reason' => $state === AcademicAverageReadiness::Available ? null : $this->reasonFor($state),
            'through' => $state === AcademicAverageReadiness::Available ? $periodLabel : null,
            'as_of' => now('Asia/Manila')->toIso8601String(),
            'exclusions' => $periodResults->pluck('average_exclusion')->filter()->unique()->values()->all(),
        ];
    }

    private function reasonFor(string $state): string
    {
        return match ($state) {
            AcademicAverageReadiness::GradesNotComplete => 'Official results or course classifications are not complete for this term.',
            AcademicAverageReadiness::IncompleteResultPending => 'Current academic average pending — incomplete grade unresolved.',
            AcademicAverageReadiness::NotApplicable => 'Not applicable — no included academic units.',
            default => 'Academic average is unavailable.',
        };
    }
}
