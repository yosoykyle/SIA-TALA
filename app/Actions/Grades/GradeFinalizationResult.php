<?php

namespace App\Actions\Grades;

use App\Models\Grade;

final readonly class GradeFinalizationResult
{
    public function __construct(
        public string $status,
        public Grade $grade,
        public bool $changed,
    ) {}

    public static function finalized(Grade $grade): self
    {
        return new self('finalized', $grade, true);
    }

    public static function alreadyFinalized(Grade $grade): self
    {
        return new self('already_finalized', $grade, false);
    }

    public static function finalizedByOverride(Grade $grade): self
    {
        return new self('finalized_by_override', $grade, true);
    }

    public static function reopened(Grade $grade): self
    {
        return new self('reopened', $grade, true);
    }
}
