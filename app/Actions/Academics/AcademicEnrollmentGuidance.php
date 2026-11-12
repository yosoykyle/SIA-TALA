<?php

namespace App\Actions\Academics;

use App\Actions\Enrollment\RegistrationEntryContext;
use App\Models\AcademicDecision;
use App\Models\StudentProfile;
use App\Models\Term;

class AcademicEnrollmentGuidance
{
    public function __construct(
        private readonly AcademicEnrollmentEffect $effects,
        private readonly RegistrationEntryContext $entry,
    ) {}

    /** @return array{effect:string,source:string,reason:string,student_reason:string,label:string,scope_label:string,context_state:string,context_reason:?string,needs_attention:bool,term_id:?int} */
    public function forStudent(StudentProfile $student, ?Term $term = null): array
    {
        $context = $term === null && $student->user !== null
            ? $this->entry->forUser($student->user)
            : null;
        $term ??= $context['guidance_term'] ?? null;
        $effect = $this->effects->forStudent($student, $term);
        $state = $context['context_state'] ?? ($term !== null ? 'selected' : 'unavailable');
        $unconfirmed = $term === null && $state === 'ambiguous';
        $reason = $unconfirmed && $effect['effect'] === AcademicDecision::EffectAllowed
            ? $context['reason']
            : ($effect['student_reason'] ?? $effect['reason']);
        $term?->loadMissing('academicYear');

        return [
            ...$effect,
            'student_reason' => $reason,
            'label' => $unconfirmed && $effect['effect'] === AcademicDecision::EffectAllowed
                ? 'Enrollment Term needs confirmation'
                : AcademicDecision::effectOptions()[$effect['effect']],
            'scope_label' => $term !== null
                ? 'Enrollment · '.collect([$term->label, $term->academicYear?->label])->filter()->implode(' · ')
                : 'Student-wide · next enrollment Term not confirmed',
            'context_state' => $state,
            'context_reason' => $context['reason'] ?? null,
            'needs_attention' => $effect['effect'] !== AcademicDecision::EffectAllowed || $unconfirmed,
            'term_id' => $term?->id,
        ];
    }
}
