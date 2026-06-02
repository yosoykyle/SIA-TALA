<?php

namespace App\Enums;

enum GradeCorrectionStatus: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Resolved = 'resolved';
    case Rejected = 'rejected';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Resolved, self::Rejected], true);
    }
}
