<?php

namespace App\Actions\Enrollment;

use App\Actions\Academics\AcademicEnrollmentEffect;
use App\Models\AcademicDecision;
use App\Models\AdmissionApplication;
use App\Models\Enrollment;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use Illuminate\Validation\ValidationException;

class RegistrationSelectionBasisResolver
{
    public function __construct(private readonly AcademicEnrollmentEffect $academicEffect) {}

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
            || StudentLifecycleChange::query()->where('student_profile_id', $studentProfile->id)
                ->where('type', StudentLifecycleChange::TypeProgramShift)->where('state', StudentLifecycleChange::StateApplied)
                ->where('target_curriculum_version_id', $studentProfile->curriculum_version_id)
                ->when($term, fn ($query) => $query->where('term_id', $term->id))->exists()
            ? Enrollment::SelectionIndividuallyAdvised
            : Enrollment::SelectionStandardCurriculum;
    }
}
