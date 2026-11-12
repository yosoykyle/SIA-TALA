<?php

namespace App\Actions\Academics;

use App\Models\AcademicDecision;
use App\Models\StudentProfile;
use App\Models\Term;

class AcademicEnrollmentEffect
{
    public function __construct(private readonly OfficialCourseResultProjection $results) {}

    /**
     * `student_reason` is the plain-language Student Hub wording, present when it differs from the staff-facing reason.
     *
     * @return array{effect: string, source: string, reason: string, student_reason?: string}
     */
    public function forStudent(StudentProfile $student, ?Term $term = null): array
    {
        if ($student->blocksEnrollmentByLifecycle()) {
            return [
                'effect' => AcademicDecision::EffectBlocked,
                'source' => 'Current student lifecycle',
                'reason' => 'The current recorded lifecycle state blocks enrollment.',
            ];
        }

        $schoolDate = today('Asia/Manila')->toDateString();
        $decision = AcademicDecision::query()
            ->where('student_profile_id', $student->id)
            ->whereIn('state', ['ACTIVE', 'SUPERSEDED'])
            ->whereDate('effective_from', '<=', $schoolDate)
            ->where(fn ($query) => $query->whereNull('term_id')->when($term, fn ($termQuery) => $termQuery->orWhere('term_id', $term->id)))
            ->orderByDesc('term_id')
            ->latest('recorded_at')
            ->latest('id')
            ->get()
            ->unique('term_id')
            ->first(fn (AcademicDecision $decision): bool => $decision->effective_until === null
                || $decision->effective_until->toDateString() >= $schoolDate);

        if ($decision instanceof AcademicDecision) {
            return [
                'effect' => $decision->effect,
                'source' => $decision->authority_reference ?: 'Recorded academic decision',
                'reason' => $decision->reason,
            ];
        }

        $results = $this->results->forStudent($student)
            ->filter(fn (array $result): bool => $result['course_specification']?->course_id !== null)
            ->sortBy([['term.starts_on', 'asc'], ['course_enrollment.id', 'asc']])
            ->keyBy('course_specification.course_id');
        $requiresAdvising = $results->contains(fn (array $result): bool => (! $result['outcome_complete']
                && $result['term']?->ends_on !== null && $result['term']->ends_on->isPast())
            || in_array($result['result'], ['INC', 'DRP', 'W'], true)
            || (is_numeric($result['result']) && (float) $result['result'] > 4.00));

        if ($requiresAdvising) {
            return [
                'effect' => AcademicDecision::EffectAdvisingRequired,
                'source' => 'Released academic-record projection',
                'reason' => 'Current unresolved or deficient course results require an individually advised selection; only affected prerequisites remain unavailable.',
                'student_reason' => 'You have a subject result that is still open or was not passed. You can still enroll next term. The Registrar will help you choose your subjects, and only subjects that depend on the affected one stay on hold.',
            ];
        }

        return [
            'effect' => AcademicDecision::EffectAllowed,
            'source' => 'Current released academic and lifecycle records',
            'reason' => 'No recorded academic or lifecycle authority blocks ordinary curriculum placement.',
        ];
    }
}
