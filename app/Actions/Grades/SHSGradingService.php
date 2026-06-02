<?php

namespace App\Actions\Grades;

use App\Exceptions\InvalidGradeException;

class SHSGradingService
{
    /**
     * @param  array<string, int|float|string|null>  $quarterlyGrades
     * @return array{final_grade: string, remarks: string, q1: string, q2: string}
     *
     * @throws InvalidGradeException
     */
    public function calculateFinalGrade(array $quarterlyGrades): array
    {
        $expectedPeriods = ['q1', 'q2'];
        $submittedPeriods = array_keys($quarterlyGrades);

        sort($submittedPeriods);

        if ($submittedPeriods !== $expectedPeriods) {
            throw new InvalidGradeException('SHS final grade requires exactly q1 and q2 for the active semester.');
        }

        $normalized = [];

        foreach ($quarterlyGrades as $period => $grade) {
            if ($grade === null || $grade === '' || ! is_numeric($grade)) {
                throw new InvalidGradeException("Missing or non-numeric transmuted grade for period {$period}.");
            }

            $numericGrade = (float) $grade;

            if (! $this->isValidTransmutedGrade($numericGrade)) {
                throw new InvalidGradeException("Invalid transmuted grade {$grade} for period {$period}. Must be 60-100.");
            }

            $normalized[$period] = $this->formatGrade($numericGrade);
        }

        $finalGrade = (((float) $normalized['q1']) + ((float) $normalized['q2'])) / 2;
        $formattedFinalGrade = $this->formatGrade($finalGrade);

        return [
            'final_grade' => $formattedFinalGrade,
            'remarks' => (float) $formattedFinalGrade >= 75.0 ? 'passed' : 'failed',
            'q1' => $normalized['q1'],
            'q2' => $normalized['q2'],
        ];
    }

    public function isValidTransmutedGrade(float $grade): bool
    {
        return $grade >= 60.0 && $grade <= 100.0;
    }

    private function formatGrade(float $grade): string
    {
        return number_format(round($grade, 2), 2, '.', '');
    }
}
