<?php

namespace App\Actions\Enrollment;

use App\Models\AdmissionApplication;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use Illuminate\Validation\ValidationException;

class RegistrationSelectionBasisResolver
{
    public function forApplicant(AdmissionApplication $application): string
    {
        return match ($application->application_path) {
            AdmissionApplication::PathFirstYear => Enrollment::SelectionStandardCurriculum,
            AdmissionApplication::PathTransferee => Enrollment::SelectionIndividuallyAdvised,
            default => throw ValidationException::withMessages([
                'selection_basis' => 'The Application source does not establish a supported registration basis.',
            ]),
        };
    }

    public function forContinuingStudent(StudentProfile $studentProfile): string
    {
        if (in_array($studentProfile->academic_standing, [StudentProfile::StandingRegular, StudentProfile::StandingNotYetEvaluated], true)) {
            return Enrollment::SelectionStandardCurriculum;
        }

        if (in_array($studentProfile->academic_standing, [
            StudentProfile::StandingIrregular,
            StudentProfile::StandingProbationary,
            StudentProfile::StandingDeficient,
            StudentProfile::StandingBlockedByPrerequisite,
            StudentProfile::StandingMustRepeatYear,
            StudentProfile::StandingCompletionCandidate,
            StudentProfile::StandingGraduationCandidate,
        ], true)) {
            return Enrollment::SelectionIndividuallyAdvised;
        }

        throw ValidationException::withMessages([
            'selection_basis' => 'The current academic source does not establish a supported registration basis.',
        ]);
    }
}
