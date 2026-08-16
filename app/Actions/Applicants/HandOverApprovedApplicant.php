<?php

namespace App\Actions\Applicants;

use App\Models\ApplicantIntake;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * @deprecated Admission no longer creates Student or enrollment records.
 */
class HandOverApprovedApplicant
{
    public function execute(
        ApplicantIntake $intake,
        User $actor,
        ?StudentProfile $confirmedExistingProfile = null,
    ): StudentProfile {
        throw ValidationException::withMessages([
            'application' => 'The legacy Applicant handover is retired. Admission ends at the derived Ready for Enrollment state; Student and enrollment records are created only by the authorized registration journey.',
        ]);
    }
}
