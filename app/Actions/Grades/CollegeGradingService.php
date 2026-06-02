<?php

namespace App\Actions\Grades;

use App\Exceptions\InvalidGradeException;
use App\Exceptions\MissingGradePeriodException;

class CollegeGradingService
{
    /**
     * @var array<int, float>
     */
    private array $transmutationTable = [
        98 => 1.00,
        93 => 1.25,
        90 => 1.50,
        87 => 1.75,
        84 => 2.00,
        82 => 2.25,
        80 => 2.50,
        78 => 2.75,
        75 => 3.00,
        74 => 4.00,
    ];

    public function transmute(int $roundedRaw): string
    {
        foreach ($this->transmutationTable as $minimumScore => $equivalentGrade) {
            if ($roundedRaw >= $minimumScore) {
                return $this->formatGrade($equivalentGrade);
            }
        }

        return $this->formatGrade(5.00);
    }

    /**
     * @param  array<string, int|float|string|null>  $periodScores
     * @return array{final_raw_average: string, equivalent_grade: string, remarks: string, prelim: string, midterm: string, final: string}
     *
     * @throws InvalidGradeException
     * @throws MissingGradePeriodException
     */
    public function calculateFinalGrade(array $periodScores): array
    {
        $requiredPeriods = ['prelim', 'midterm', 'final'];

        foreach ($requiredPeriods as $period) {
            if (! array_key_exists($period, $periodScores)) {
                throw new MissingGradePeriodException("Missing required grade period: {$period}");
            }
        }

        $extraPeriods = array_diff(array_keys($periodScores), $requiredPeriods);

        if ($extraPeriods !== []) {
            throw new InvalidGradeException('College grade payload only accepts prelim, midterm, and final.');
        }

        $normalized = [];

        foreach ($periodScores as $period => $score) {
            if ($score === null || $score === '' || ! is_numeric($score)) {
                throw new InvalidGradeException("Invalid raw score for period {$period}. Must be numeric 0-100.");
            }

            $numericScore = (float) $score;

            if ($numericScore < 0.0 || $numericScore > 100.0) {
                throw new InvalidGradeException("Invalid raw score {$score} for period {$period}. Must be 0-100.");
            }

            $normalized[$period] = $this->formatGrade($numericScore);
        }

        $rawAverage = ((float) $normalized['prelim'] * 0.30)
            + ((float) $normalized['midterm'] * 0.30)
            + ((float) $normalized['final'] * 0.40);
        $roundedRaw = (int) round($rawAverage, 0, PHP_ROUND_HALF_UP);
        $equivalentGrade = $this->transmute($roundedRaw);

        return [
            'final_raw_average' => $this->formatGrade((float) $roundedRaw),
            'equivalent_grade' => $equivalentGrade,
            'remarks' => (float) $equivalentGrade <= 3.00 ? 'passed' : 'failed',
            'prelim' => $normalized['prelim'],
            'midterm' => $normalized['midterm'],
            'final' => $normalized['final'],
        ];
    }

    private function formatGrade(float $grade): string
    {
        return number_format(round($grade, 2), 2, '.', '');
    }
}
