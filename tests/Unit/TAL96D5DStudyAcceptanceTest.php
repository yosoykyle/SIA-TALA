<?php

namespace Tests\Unit;

use App\Actions\Scheduling\SchedulingOperatingEnvelopeStudyAcceptance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TAL96D5DStudyAcceptanceTest extends TestCase
{
    public function test_optimal_zero_gap_operational_result_meets_the_strict_study_rule(): void
    {
        $this->assertTrue((new SchedulingOperatingEnvelopeStudyAcceptance)->meetsStrictRule(
            operationallyValid: true,
            solverStatus: 'optimal',
            relativeGap: 0.0,
        ));
    }

    #[DataProvider('nonQualifyingResults')]
    public function test_feasible_or_unproven_results_do_not_meet_the_strict_study_rule(
        bool $operationallyValid,
        string $solverStatus,
        ?float $relativeGap,
    ): void {
        $this->assertFalse((new SchedulingOperatingEnvelopeStudyAcceptance)->meetsStrictRule(
            operationallyValid: $operationallyValid,
            solverStatus: $solverStatus,
            relativeGap: $relativeGap,
        ));
    }

    /** @return iterable<string, array{bool,string,float|null}> */
    public static function nonQualifyingResults(): iterable
    {
        yield 'feasible is operational but not proven optimal' => [true, 'feasible', 0.0];
        yield 'optimal with a nonzero gap is not accepted' => [true, 'optimal', 0.01];
        yield 'missing gap is not accepted' => [true, 'optimal', null];
        yield 'independent validation failure is not accepted' => [false, 'optimal', 0.0];
    }
}
