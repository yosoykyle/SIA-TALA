<?php

namespace App\Actions\Academics;

use Illuminate\Support\Collection;

class AcademicYearGwaProjection
{
    public function __construct(
        private readonly AcademicAverageReadiness $readiness,
    ) {}

    /**
     * Compute the Philippine college unit-weighted Academic Year GWA across all
     * subjects belonging to the academic year (never an arithmetic average of term GPAs).
     *
     * @param  Collection<int, covariant array<string, mixed>>  $yearResults
     * @return array{state: string, value: ?string, label: string, reason: ?string, through: ?string, as_of: string, exclusions: list<string>}
     */
    public function forYearResults(Collection $yearResults, string $yearLabel): array
    {
        $state = $this->readiness->forResults($yearResults);

        return [
            'state' => $state,
            'value' => $state === AcademicAverageReadiness::Available ? $this->readiness->average($yearResults) : null,
            'label' => 'Academic Year GWA',
            'reason' => $state === AcademicAverageReadiness::Available ? null : $this->reasonFor($state),
            'through' => $state === AcademicAverageReadiness::Available ? $yearLabel : null,
            'as_of' => now('Asia/Manila')->toIso8601String(),
            'exclusions' => $yearResults->pluck('average_exclusion')->filter()->unique()->values()->all(),
        ];
    }

    private function reasonFor(string $state): string
    {
        return match ($state) {
            AcademicAverageReadiness::GradesNotComplete => 'Official results are not complete for this academic year.',
            AcademicAverageReadiness::IncompleteResultPending => 'Academic year GWA pending — incomplete grade unresolved.',
            AcademicAverageReadiness::NotApplicable => 'Not applicable — no included academic units.',
            default => 'Academic year GWA is unavailable.',
        };
    }
}
