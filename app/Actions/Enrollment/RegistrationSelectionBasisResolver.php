<?php

namespace App\Actions\Enrollment;

use App\Actions\Academics\AcademicEnrollmentEffect;
use App\Actions\Academics\IrregularStudentReasons;
use App\Models\AcademicDecision;
use App\Models\AdmissionApplication;
use App\Models\Enrollment;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use Illuminate\Validation\ValidationException;

class RegistrationSelectionBasisResolver
{
    public function __construct(
        private readonly AcademicEnrollmentEffect $academicEffect,
        private readonly IrregularStudentReasons $irregularReasons,
    ) {}

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

    public function forContinuingStudent(StudentProfile $studentProfile, ?Term $term = null): string
    {
        $effect = $this->academicEffect->forStudent($studentProfile, $term);
        if (in_array($effect['effect'], [AcademicDecision::EffectBlocked, AcademicDecision::EffectPendingDecision], true)) {
            throw ValidationException::withMessages([
                'academic_effect' => $effect['reason'],
            ]);
        }

        return $effect['effect'] === AcademicDecision::EffectAdvisingRequired
            || $this->irregularReasons->activeUnenrolledShift($studentProfile) instanceof StudentLifecycleChange
            || ($this->decidedByRecords($effect) && $this->irregularReasons->isReturnee($studentProfile))
            ? Enrollment::SelectionIndividuallyAdvised
            : Enrollment::SelectionStandardCurriculum;
    }

    /**
     * A returnee is advised unless the Registrar recorded a decision (for example "Keep regular") (#99 Scope 11).
     *
     * @param  array{effect: string, source: string}  $effect
     */
    private function decidedByRecords(array $effect): bool
    {
        return $effect['effect'] === AcademicDecision::EffectAllowed
            && $effect['source'] === AcademicEnrollmentEffect::SourceReleasedRecords;
    }
}
