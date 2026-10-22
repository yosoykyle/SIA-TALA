<?php

namespace App\Actions\Academics;

use App\Models\AcademicDecision;
use App\Models\StudentProfile;
use App\Models\Term;

class AcademicEnrollmentEffect
{
    public function __construct(private readonly OfficialCourseResultProjection $results) {}

    /** @return array{effect: string, source: string, reason: string} */
    public function forStudent(StudentProfile $student, ?Term $term = null): array
    {
        if ($student->blocksEnrollmentByLifecycle()) {
            return [
                'effect' => AcademicDecision::EffectBlocked,
                'source' => 'Current student lifecycle',
                'reason' => 'The current recorded lifecycle state blocks enrollment.',
            ];
        }

        $decision = AcademicDecision::query()
            ->where('student_profile_id', $student->id)
            ->where('state', 'ACTIVE')
            ->whereDate('effective_from', '<=', today())
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', today()))
            ->where(fn ($query) => $query->whereNull('term_id')->when($term, fn ($termQuery) => $termQuery->orWhere('term_id', $term->id)))
            ->latest('recorded_at')
            ->latest('id')
            ->first();

        if ($decision instanceof AcademicDecision) {
            return ['effect' => $decision->effect, 'source' => $decision->authority_reference, 'reason' => $decision->reason];
        }

        $results = $this->results->forStudent($student)
            ->filter(fn (array $result): bool => $result['course_specification']?->course_id !== null)
            ->sortBy([['term.starts_on', 'asc'], ['course_enrollment.id', 'asc']])
            ->keyBy('course_specification.course_id');
        $requiresAdvising = $results->contains(fn (array $result): bool => ($result['event'] === null
                && $result['term']?->ends_on !== null && $result['term']->ends_on->isPast())
            || in_array($result['result'], ['INC', 'DRP', 'W'], true)
            || (is_numeric($result['result']) && (float) $result['result'] > 4.00));

        if ($requiresAdvising) {
            return [
                'effect' => AcademicDecision::EffectAdvisingRequired,
                'source' => 'Released academic-record projection',
                'reason' => 'Current unresolved or deficient course results require an individually advised selection; only affected prerequisites remain unavailable.',
            ];
        }

        return [
            'effect' => AcademicDecision::EffectAllowed,
            'source' => 'Current released academic and lifecycle records',
            'reason' => 'No recorded academic or lifecycle authority blocks ordinary curriculum placement.',
        ];
    }
}
