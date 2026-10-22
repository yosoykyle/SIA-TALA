<?php

namespace App\Filament\Applicant\Pages;

use App\Filament\Student\Pages\Enrollment as LearnerEnrollment;

class Enrollment extends LearnerEnrollment
{
    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('applicant') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }
}
