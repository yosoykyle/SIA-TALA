<?php

namespace App\Actions\Scheduling;

/** Applies the research-only rule without narrowing production acceptance. */
final class SchedulingOperatingEnvelopeStudyAcceptance
{
    public function meetsStrictRule(
        bool $operationallyValid,
        string $solverStatus,
        ?float $relativeGap,
    ): bool {
        return $operationallyValid
            && $solverStatus === 'optimal'
            && $relativeGap !== null
            && abs($relativeGap) <= 1.0E-9;
    }
}
