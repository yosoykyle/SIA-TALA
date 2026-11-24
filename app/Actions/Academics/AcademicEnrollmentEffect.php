<?php

namespace App\Actions\Academics;

use App\Actions\Enrollment\RegistrationAcademicEligibilityQuery;
use App\Models\AcademicDecision;
use App\Models\CurriculumEntry;
use App\Models\ProgramShiftCreditEntry;
use App\Models\RegistrationCreditEvaluation;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use Illuminate\Support\Collection;

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
            ->where('state', AcademicDecision::StateActive)
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

        $attemptsByCourse = $this->results->forStudent($student)
            ->filter(fn (array $result): bool => $result['course_specification']?->course_id !== null)
            ->groupBy(fn (array $result): int => (int) $result['course_specification']->course_id);
        $creditedCourseIds = $this->creditedCourseIds($student);

        $requiresAdvising = $attemptsByCourse->contains(function (Collection $attempts, int $courseId) use ($creditedCourseIds): bool {
            if ($creditedCourseIds->contains($courseId)
                || $attempts->contains(fn (array $attempt): bool => RegistrationAcademicEligibilityQuery::isPassingResult($attempt['result'] ?? null))) {
                return false;
            }

            return $attempts->contains(fn (array $result): bool => (! $result['outcome_complete']
                    && $result['term']?->ends_on !== null && $result['term']->ends_on->isPast())
                || in_array($result['result'], ['INC', 'DRP', 'W'], true)
                || (is_numeric($result['result']) && (float) $result['result'] > 4.00));
        });

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

    /** @return Collection<int, int> */
    private function creditedCourseIds(StudentProfile $student): Collection
    {
        $transferEntryIds = RegistrationCreditEvaluation::creditedEntryIdsForStudent($student);
        $transferCourseIds = $transferEntryIds !== []
            ? CurriculumEntry::query()
                ->with('courseSpecification')
                ->whereIn('id', $transferEntryIds)
                ->get()
                ->pluck('courseSpecification.course_id')
            : collect();

        $shiftCreditCourseIds = ProgramShiftCreditEntry::query()
            ->with('curriculumEntry.courseSpecification')
            ->where('treatment', ProgramShiftCreditEntry::TreatmentAccepted)
            ->where('state', ProgramShiftCreditEntry::StateRecorded)
            ->whereHas('curriculumEntry', fn ($query) => $query->where('curriculum_version_id', $student->curriculum_version_id))
            ->whereHas('lifecycleChange', fn ($query) => $query
                ->where('student_profile_id', $student->id)
                ->where('state', StudentLifecycleChange::StateApplied))
            ->get()
            ->filter(fn (ProgramShiftCreditEntry $credit): bool => $credit->numeric_grade === null || (float) $credit->numeric_grade <= 4.00)
            ->pluck('curriculumEntry.courseSpecification.course_id');

        return $transferCourseIds
            ->merge($shiftCreditCourseIds)
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
    }
}
