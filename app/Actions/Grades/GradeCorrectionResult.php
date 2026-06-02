<?php

namespace App\Actions\Grades;

use App\Models\GradeCorrection;

final readonly class GradeCorrectionResult
{
    public function __construct(
        public string $status,
        public GradeCorrection $correction,
        public bool $changed,
    ) {}

    public static function underReview(GradeCorrection $correction): self
    {
        return new self('under_review', $correction, true);
    }

    public static function rejected(GradeCorrection $correction): self
    {
        return new self('rejected', $correction, true);
    }

    public static function resolved(GradeCorrection $correction): self
    {
        return new self('resolved', $correction, true);
    }

    public static function resolvedWithGradeChange(GradeCorrection $correction): self
    {
        return new self('resolved_with_grade_change', $correction, true);
    }
}
