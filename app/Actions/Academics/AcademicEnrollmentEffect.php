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
        $decision = AcademicDecision::query()
            ->where('student_profile_id', $student->id)
            ->where('state', 'ACTIVE')
            ->whereDate('effective_from', '<=', today())
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', today()))
            ->when($term, fn ($query) => $query->where(fn ($termQuery) => $termQuery->whereNull('term_id')->orWhere('term_id', $term->id)))
            ->latest('recorded_at')
            ->first();

        if ($decision instanceof AcademicDecision) {
            return ['effect' => $decision->effect, 'source' => $decision->authority_reference, 'reason' => $decision->reason];
        }

        if ($student->blocksEnrollmentByLifecycle()) {
            return [
                'effect' => AcademicDecision::EffectBlocked,
                'source' => 'Current student lifecycle',
                'reason' => 'The current recorded lifecycle state blocks enrollment.',
            ];
        }

        $results = $this->results->forStudent($student);
        $unresolvedPastResult = $results->contains(fn (array $result): bool => $result['event'] === null
            && $result['term']?->ends_on !== null
            && $result['term']->ends_on->isPast());

        if ($unresolvedPastResult) {
            return [
                'effect' => AcademicDecision::EffectPendingDecision,
                'source' => 'Released academic-record projection',
                'reason' => 'An official result needed for the academic effect remains unresolved. Registrar review is required.',
            ];
        }

        $requiresAdvising = $results->contains(fn (array $result): bool => $result['result'] === 'INC'
            || (is_numeric($result['result']) && (float) $result['result'] > 4.00));

        if ($requiresAdvising) {
            return [
                'effect' => AcademicDecision::EffectAdvisingRequired,
                'source' => 'Released academic-record projection',
                'reason' => 'A released deficient or incomplete result requires an individually advised registration proposal.',
            ];
        }

        return [
            'effect' => AcademicDecision::EffectAllowed,
            'source' => 'Current released academic and lifecycle records',
            'reason' => 'No recorded academic or lifecycle authority blocks ordinary curriculum placement.',
        ];
    }
}
