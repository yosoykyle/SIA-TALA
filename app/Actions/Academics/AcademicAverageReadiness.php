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
        if ($results->contains(fn (array $result): bool => $result['event'] === null)) {
            return self::GradesNotComplete;
        }

        if ($results->contains(fn (array $result): bool => $result['result'] === 'INC')) {
            return self::IncompleteResultPending;
        }

        $included = $results->filter(fn (array $result): bool => $this->includedNumeric($result));

        return $included->isEmpty() ? self::NotApplicable : self::Available;
    }

    /** @param array<string, mixed> $result */
    private function includedNumeric(array $result): bool
    {
        $classification = $result['course_specification']?->academic_classification;

        return is_numeric($result['result'])
            && ! in_array($classification, ['PE', 'NSTP'], true)
            && (float) $result['units'] > 0;
    }
}
