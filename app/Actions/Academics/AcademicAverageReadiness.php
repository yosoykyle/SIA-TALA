<?php

namespace App\Actions\Academics;

use Illuminate\Support\Collection;

class AcademicAverageReadiness
{
    public const GradesNotComplete = 'GradesNotComplete';

    public const IncompleteResultPending = 'IncompleteResultPending';

    public const Available = 'Available';

    public const NotApplicable = 'NotApplicable';

    /** @param Collection<int, covariant array<string, mixed>> $results */
    public function forResults(Collection $results): string
    {
        if ($results->contains(fn (array $result): bool => ! ($result['outcome_complete'] ?? $result['event'] !== null)
            || $result['course_specification']?->academic_classification === null
            || (is_numeric($result['result']) && $result['units'] === ''))) {
            return self::GradesNotComplete;
        }

        if ($results->contains(fn (array $result): bool => $result['result'] === 'INC'
            && ! $this->excludedClassification($result)
            && ! ($result['inc_settled'] ?? false))) {
            return self::IncompleteResultPending;
        }

        return $this->includedResults($results)->isEmpty() ? self::NotApplicable : self::Available;
    }

    /**
     * @param  Collection<int, covariant array<string, mixed>>  $results
     * @return Collection<int, covariant array<string, mixed>>
     */
    public function includedResults(Collection $results): Collection
    {
        return $results->filter(fn (array $result): bool => is_numeric($result['result'])
            && ! $this->excludedClassification($result)
            && $result['course_specification']?->academic_classification !== null
            && (float) $result['units'] > 0);
    }

    /** @param Collection<int, covariant array<string, mixed>> $results */
    public function average(Collection $results): ?string
    {
        $units = 0;
        $weighted = 0;

        foreach ($this->includedResults($results) as $result) {
            $attemptUnits = $this->scaledDecimal((string) $result['units'], 2);
            $units += $attemptUnits;
            $weighted += $this->scaledDecimal((string) $result['result'], 4) * $attemptUnits;
        }

        if ($units === 0) {
            return null;
        }

        $denominator = $units * 10000;
        $numerator = $weighted * 100;
        $hundredths = intdiv($numerator, $denominator)
            + (($numerator % $denominator) * 2 >= $denominator ? 1 : 0);

        return sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100);
    }

    /** Preserve the stored four-decimal grade and two-decimal unit scales without intermediate rounding. */
    private function scaledDecimal(string $value, int $scale): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) $whole * (10 ** $scale) + (int) str_pad($fraction, $scale, '0');
    }

    /** @param array<string, mixed> $result */
    private function excludedClassification(array $result): bool
    {
        return in_array($result['course_specification']?->academic_classification, ['PE', 'NSTP'], true);
    }
}
