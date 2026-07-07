<?php

namespace App\Actions\StudentHub;

use App\Models\GradeRosterRow;

class StudentGradeLabelFormatter
{
    public function displayGrade(?string $code, ?string $category): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return match (true) {
            is_numeric($code) => $code,
            $code === 'INC' || $category === GradeRosterRow::CategoryIncomplete => 'Incomplete',
            $code === 'P' || $category === GradeRosterRow::CategoryPending => 'Pending Grade',
            in_array($code, ['DRP', 'W'], true) || $category === GradeRosterRow::CategoryWithdrawn => 'Withdrawn',
            $code === 'TC' || $category === GradeRosterRow::CategoryTransferCredit => 'Transfer Credit',
            default => $code,
        };
    }
}
