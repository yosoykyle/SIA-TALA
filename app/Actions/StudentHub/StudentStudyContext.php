<?php

namespace App\Actions\StudentHub;

use App\Actions\Enrollment\CurrentOfficialEnrollmentResolver;
use App\Actions\Enrollment\EnrollmentAcademicContextResolver;
use App\Models\Term;
use App\Models\User;

class StudentStudyContext
{
    public function __construct(
        private CurrentOfficialEnrollmentResolver $officialEnrollment,
        private EnrollmentAcademicContextResolver $academicContext,
    ) {}

    /**
     * @return array{program_name:?string, curriculum_levels:list<string>, study_level_label:string, semester:?string, academic_year:?string, term_label:?string, enrollment_id:?int, is_official:bool}
     */
    public function forStudent(User $student): array
    {
        $profile = $student->studentProfile()->with('program')->first();
        $enrollment = $profile === null ? null : $this->officialEnrollment->forProfile($profile);
        $context = [
            'program_name' => $profile?->program?->name,
            'curriculum_levels' => [],
            'study_level_label' => 'Unavailable',
            'semester' => null,
            'academic_year' => null,
            'term_label' => null,
            'enrollment_id' => null,
            'is_official' => false,
        ];

        if ($enrollment === null) {
            return $context;
        }

        $enrollment->loadMissing('term.academicYear');
        $levels = $this->academicContext->forEnrollment($enrollment)['curriculum_levels'];
        $labels = array_map(fn (string $level): string => ctype_digit($level) ? 'Year '.$level : $level, $levels);

        return array_replace($context, [
            'curriculum_levels' => $levels,
            'study_level_label' => match (count($labels)) {
                0 => 'Unavailable',
                1 => $labels[0],
                default => 'Mixed levels ('.implode(', ', $labels).')',
            },
            'semester' => Term::typeOptions()[$enrollment->term?->type] ?? null,
            'academic_year' => $enrollment->term?->academicYear?->label,
            'term_label' => $enrollment->term?->label,
            'enrollment_id' => (int) $enrollment->getKey(),
            'is_official' => true,
        ]);
    }
}
