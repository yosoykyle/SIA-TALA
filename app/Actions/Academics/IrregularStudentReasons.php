<?php

namespace App\Actions\Academics;

use App\Actions\Enrollment\RegistrationAcademicEligibilityQuery;
use App\Actions\Grades\IncDeadlineService;
use App\Models\AcademicDecision;
use App\Models\CourseRequirement;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\ProgramShiftCreditEntry;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use Illuminate\Support\Collection;

/**
 * Names why a Student follows the Individually Advised ("irregular") path (#99 Scope 11).
 *
 * "Irregular" is a display label only (PRD 04 §2.1): the enrollment effect still comes from
 * {@see AcademicEnrollmentEffect}. This projection adds the named reasons and subjects behind it.
 */
class IrregularStudentReasons
{
    public const ReviewCountCacheKey = 'student-records:irregular-review-count';

    public const ReasonReturnee = 'returnee';

    public const ReasonShiftee = 'shiftee';

    public const ReasonFailed = 'failed';

    public const ReasonIncomplete = 'incomplete';

    public const ReasonDroppedSubject = 'dropped_subject';

    public const ReasonDroppedTerm = 'dropped_term';

    public const ReasonPendingResult = 'pending_result';

    public const ReasonPrerequisite = 'prerequisite';

    public const ReasonRegistrarDecision = 'registrar_decision';

    /** Display priority: the first reason present is the primary reason. */
    private const Priority = [
        self::ReasonReturnee,
        self::ReasonShiftee,
        self::ReasonFailed,
        self::ReasonIncomplete,
        self::ReasonDroppedSubject,
        self::ReasonDroppedTerm,
        self::ReasonPendingResult,
        self::ReasonPrerequisite,
        self::ReasonRegistrarDecision,
    ];

    public function __construct(
        private readonly AcademicEnrollmentEffect $effects,
        private readonly OfficialCourseResultProjection $results,
    ) {}

    /**
     * @return array{
     *     is_irregular: bool,
     *     effect: string,
     *     kept_regular: bool,
     *     decision_reason: ?string,
     *     status_label: string,
     *     primary: ?array{code: string, label: string, details: string, subjects: list<string>},
     *     reasons: list<array{code: string, label: string, details: string, subjects: list<string>}>
     * }
     */
    public function forStudent(StudentProfile $student, ?Term $term = null): array
    {
        $effect = $this->effects->forStudent($student, $term);
        $decision = $this->activeDecision($student, $term);
        $reasons = collect()
            ->merge($this->returneeReasons($student))
            ->merge($this->shifteeReasons($student))
            ->merge($this->resultReasons($student));

        if ($decision?->effect === AcademicDecision::EffectAdvisingRequired && $reasons->isEmpty()) {
            $reasons->push($this->reason(self::ReasonRegistrarDecision, 'Registrar decision', (string) $decision->reason, []));
        }

        $reasons = $reasons
            ->sortBy(fn (array $reason): int => array_search($reason['code'], self::Priority, true))
            ->values();
        $keptRegular = $decision?->effect === AcademicDecision::EffectAllowed && $reasons->isNotEmpty();
        $isIrregular = ! $keptRegular
            && $effect['effect'] !== AcademicDecision::EffectBlocked
            && ($effect['effect'] === AcademicDecision::EffectAdvisingRequired || $reasons->contains(
                fn (array $reason): bool => in_array($reason['code'], [self::ReasonReturnee, self::ReasonShiftee], true),
            ));
        $primary = $isIrregular ? $reasons->first() : null;

        return [
            'is_irregular' => $isIrregular,
            'effect' => $effect['effect'],
            'kept_regular' => $keptRegular,
            'decision_reason' => $decision?->reason,
            'status_label' => $isIrregular ? 'Irregular'.($primary ? ' – '.$primary['label'] : '') : 'Regular',
            'primary' => $primary,
            'reasons' => $isIrregular ? $reasons->all() : [],
        ];
    }

    /**
     * Flagged by the records but not yet confirmed or kept regular by the Registrar.
     */
    public function needsReview(StudentProfile $student): bool
    {
        return $student->lifecycle_status === StudentProfile::LifecycleActive
            && $this->activeDecision($student, null) === null
            && $this->forStudent($student)['is_irregular'];
    }

    /**
     * Active Students awaiting the Registrar's Irregular review, computed once per request.
     *
     * @return Collection<int, int>
     */
    public function flaggedStudentIds(): Collection
    {
        return once(fn (): Collection => StudentProfile::query()
            ->where('lifecycle_status', StudentProfile::LifecycleActive)
            ->whereHas('enrollments', fn ($query) => $query->whereNotNull('officially_enrolled_at'))
            ->get()
            ->filter(fn (StudentProfile $student): bool => $this->needsReview($student))
            ->map(fn (StudentProfile $student): int => (int) $student->id)
            ->values());
    }

    /**
     * True when the Student returns after a Reactivation or a skipped regular semester (#99 Scope 11 decision).
     */
    public function isReturnee(StudentProfile $student): bool
    {
        return $this->returneeReasons($student)->isNotEmpty();
    }

    private function activeDecision(StudentProfile $student, ?Term $term): ?AcademicDecision
    {
        $schoolDate = today(config('app.display_timezone'))->toDateString();

        return AcademicDecision::query()
            ->where('student_profile_id', $student->id)
            ->where('state', AcademicDecision::StateActive)
            ->whereDate('effective_from', '<=', $schoolDate)
            ->where(fn ($query) => $query->whereNull('term_id')->when($term, fn ($termQuery) => $termQuery->orWhere('term_id', $term->id)))
            ->orderByDesc('term_id')
            ->latest('recorded_at')
            ->latest('id')
            ->get()
            ->first(fn (AcademicDecision $decision): bool => $decision->effective_until === null
                || $decision->effective_until->toDateString() >= $schoolDate);
    }

    /**
     * Courses the student must take again: failed, dropped, withdrawn, or an INC past its completion deadline.
     *
     * @return Collection<int, int>
     */
    public function mandatoryRetakeCourseIds(StudentProfile $student): Collection
    {
        $attempts = $this->results->forStudent($student)
            ->filter(fn (array $result): bool => $result['course_specification']?->course_id !== null);

        return $attempts
            ->groupBy(fn (array $result): int => (int) $result['course_specification']->course_id)
            ->reject(fn (Collection $courseAttempts): bool => $courseAttempts->contains(
                fn (array $attempt): bool => RegistrationAcademicEligibilityQuery::isPassingResult($attempt['result'] ?? null)
                    || ($attempt['result'] ?? null) === 'CREDIT',
            ))
            ->map(fn (Collection $courseAttempts): array => $courseAttempts->last())
            ->filter(fn (array $result): bool => (is_numeric($result['result']) && (float) $result['result'] > 4.00)
                || in_array($result['result'], ['DRP', 'W'], true)
                || ($result['result'] === 'INC' && ($result['inc_state'] ?? null) === IncDeadlineService::StateCompletionOverdue))
            ->keys()
            ->map(fn (mixed $id): int => (int) $id)
            ->values();
    }

    /** @return Collection<int, array{code: string, label: string, details: string, subjects: list<string>}> */
    private function resultReasons(StudentProfile $student): Collection
    {
        $attempts = $this->results->forStudent($student)
            ->filter(fn (array $result): bool => $result['course_specification']?->course_id !== null);
        $byCourse = $attempts->groupBy(fn (array $result): int => (int) $result['course_specification']->course_id);
        $creditedOrPassed = $byCourse->filter(fn (Collection $courseAttempts): bool => $courseAttempts->contains(
            fn (array $attempt): bool => RegistrationAcademicEligibilityQuery::isPassingResult($attempt['result'] ?? null)
                || ($attempt['result'] ?? null) === 'CREDIT',
        ))->keys();

        $open = $byCourse->reject(fn (Collection $courseAttempts, int $courseId): bool => $creditedOrPassed->contains($courseId))
            ->map(fn (Collection $courseAttempts): array => $courseAttempts->last());

        $failed = $open->filter(fn (array $result): bool => is_numeric($result['result']) && (float) $result['result'] > 4.00);
        $incomplete = $open->filter(fn (array $result): bool => $result['result'] === 'INC');
        $dropped = $open->filter(fn (array $result): bool => $result['result'] === 'DRP');
        $withdrawn = $open->filter(fn (array $result): bool => $result['result'] === 'W');
        $pending = $open->filter(fn (array $result): bool => ! $result['outcome_complete']
            && $result['result'] !== 'INC'
            && $result['term']?->ends_on !== null && $result['term']->ends_on->isPast());

        $reasons = collect();
        if ($failed->isNotEmpty()) {
            $reasons->push($this->reason(self::ReasonFailed, 'Failed subject', 'You did not pass '.$failed->map(
                fn (array $result): string => $this->subjectLabel($result).' (Grade: '.number_format((float) $result['result'], 2).') in '.$this->termLabel($result['term']),
            )->implode('; ').'.', $failed->map($this->subjectCode(...))->values()->all()));
        }
        if ($incomplete->isNotEmpty()) {
            $reasons->push($this->reason(self::ReasonIncomplete, 'Incomplete grade (INC)', $incomplete->map(function (array $result): string {
                $deadline = $result['inc_deadline'] ?? null;
                $overdue = ($result['inc_state'] ?? null) === IncDeadlineService::StateCompletionOverdue;

                return $this->subjectLabel($result).': '.match (true) {
                    $overdue => 'INC – deadline passed, retake required',
                    $deadline !== null => 'comply by '.$deadline->timezone(config('app.display_timezone'))->format('M j, Y').' to avoid a retake',
                    default => 'completion deadline not set yet',
                };
            })->implode('; ').'.', $incomplete->map($this->subjectCode(...))->values()->all()));
        }
        if ($dropped->isNotEmpty()) {
            $reasons->push($this->reason(self::ReasonDroppedSubject, 'Dropped subject', 'Approved drop: '.$dropped->map($this->subjectLabel(...))->implode('; ').'. You need to take it again.', $dropped->map($this->subjectCode(...))->values()->all()));
        }
        if ($withdrawn->isNotEmpty()) {
            $terms = $withdrawn->map(fn (array $result): string => $this->termLabel($result['term']))->unique()->implode(', ');
            $reasons->push($this->reason(self::ReasonDroppedTerm, 'Dropped term', 'You withdrew from '.$terms.'. Subjects to take again: '.$withdrawn->map($this->subjectCode(...))->implode(', ').'.', $withdrawn->map($this->subjectCode(...))->values()->all()));
        }
        if ($pending->isNotEmpty()) {
            $reasons->push($this->reason(self::ReasonPendingResult, 'Result not yet recorded', 'No final result is recorded yet for '.$pending->map($this->subjectLabel(...))->implode('; ').'.', $pending->map($this->subjectCode(...))->values()->all()));
        }

        $unpassedCourseIds = $failed->merge($incomplete)->merge($dropped)->merge($withdrawn)
            ->map(fn (array $result): int => (int) $result['course_specification']->course_id)
            ->values();
        $prerequisite = $this->prerequisiteReason($student, $unpassedCourseIds, $creditedOrPassed);
        if ($prerequisite !== null) {
            $reasons->push($prerequisite);
        }

        return $reasons;
    }

    /**
     * @param  Collection<int, int>  $unpassedCourseIds
     * @param  Collection<int, int>  $satisfiedCourseIds
     * @return array{code: string, label: string, details: string, subjects: list<string>}|null
     */
    private function prerequisiteReason(StudentProfile $student, Collection $unpassedCourseIds, Collection $satisfiedCourseIds): ?array
    {
        if ($unpassedCourseIds->isEmpty() || $student->curriculum_version_id === null) {
            return null;
        }

        $entries = CurriculumEntry::query()
            ->with('courseSpecification.course')
            ->where('curriculum_version_id', $student->curriculum_version_id)
            ->get()
            ->reject(fn (CurriculumEntry $entry): bool => $satisfiedCourseIds->contains((int) $entry->courseSpecification?->course_id)
                || $unpassedCourseIds->contains((int) $entry->courseSpecification?->course_id));

        $blocked = CourseRequirement::query()
            ->with('relatedCourse')
            ->whereIn('course_specification_id', $entries->pluck('course_specification_id'))
            ->where('state', CourseRequirement::StateActive)
            ->where('rule_type', CourseRequirement::TypePrerequisite)
            ->whereIn('related_course_id', $unpassedCourseIds)
            ->get()
            ->map(function (CourseRequirement $requirement) use ($entries): ?string {
                $entry = $entries->firstWhere('course_specification_id', $requirement->course_specification_id);
                $locked = $entry?->courseSpecification?->course?->code;

                return $locked === null ? null : ($requirement->relatedCourse?->code ?? 'a prerequisite').' → cannot take '.$locked;
            })
            ->filter()
            ->unique()
            ->values();

        return $blocked->isEmpty()
            ? null
            : $this->reason(self::ReasonPrerequisite, 'Prerequisite not met', 'Prerequisite not passed: '.$blocked->implode('; ').'.', $blocked->all());
    }

    /** @return Collection<int, array{code: string, label: string, details: string, subjects: list<string>}> */
    private function returneeReasons(StudentProfile $student): Collection
    {
        $lastEnrollment = Enrollment::query()
            ->with('term.academicYear')
            ->where('student_profile_id', $student->id)
            ->whereNotNull('officially_enrolled_at')
            ->get()
            ->sortByDesc(fn (Enrollment $enrollment): string => (string) $enrollment->term?->starts_on?->toDateString())
            ->first();

        if (! $lastEnrollment instanceof Enrollment || $lastEnrollment->term === null) {
            return collect();
        }

        $reactivation = StudentLifecycleChange::query()
            ->where('student_profile_id', $student->id)
            ->where('type', StudentLifecycleChange::TypeReactivation)
            ->where('state', StudentLifecycleChange::StateApplied)
            ->latest('effective_on')
            ->first();
        $reactivatedAfterLastEnrollment = $reactivation?->effective_on !== null
            && $reactivation->effective_on->toDateString() >= $lastEnrollment->term->starts_on->toDateString();

        $skipped = Term::query()
            ->with('academicYear')
            ->whereIn('type', [Term::TypeFirstSemester, Term::TypeSecondSemester])
            ->whereIn('state', [Term::StateActive, Term::StateClosed])
            ->whereDate('starts_on', '>', $lastEnrollment->term->ends_on ?? $lastEnrollment->term->starts_on)
            ->whereDate('ends_on', '<', today(config('app.display_timezone')))
            ->orderBy('starts_on')
            ->get();

        if (! $reactivatedAfterLastEnrollment && $skipped->isEmpty()) {
            return collect();
        }

        $details = 'Last enrolled: '.$this->termLabel($lastEnrollment->term).'.'
            .($skipped->isNotEmpty() ? ' Not enrolled: '.$skipped->map($this->termLabel(...))->implode(', ').'.' : '')
            .($reactivatedAfterLastEnrollment ? ' Returned on '.$reactivation->effective_on->format('M j, Y').'.' : '');

        return collect([$this->reason(self::ReasonReturnee, 'Returnee', $details, [])]);
    }

    /** @return Collection<int, array{code: string, label: string, details: string, subjects: list<string>}> */
    private function shifteeReasons(StudentProfile $student): Collection
    {
        $shift = StudentLifecycleChange::query()
            ->with(['targetProgram', 'targetCurriculumVersion'])
            ->where('student_profile_id', $student->id)
            ->where('type', StudentLifecycleChange::TypeProgramShift)
            ->where('state', StudentLifecycleChange::StateApplied)
            ->where('target_curriculum_version_id', $student->curriculum_version_id)
            ->latest('effective_on')
            ->first();

        if (! $shift instanceof StudentLifecycleChange) {
            return collect();
        }

        $enrolledSinceShift = Enrollment::query()
            ->where('student_profile_id', $student->id)
            ->whereNotNull('officially_enrolled_at')
            ->whereHas('term', fn ($query) => $query->whereDate('starts_on', '>=', $shift->effective_on ?? $shift->created_at))
            ->exists();
        if ($enrolledSinceShift) {
            return collect();
        }

        $credited = ProgramShiftCreditEntry::query()
            ->where('student_lifecycle_change_id', $shift->id)
            ->where('treatment', ProgramShiftCreditEntry::TreatmentAccepted)
            ->where('state', ProgramShiftCreditEntry::StateRecorded)
            ->count();
        $total = CurriculumEntry::query()->where('curriculum_version_id', $student->curriculum_version_id)->count();

        return collect([$this->reason(
            self::ReasonShiftee,
            'Shiftee',
            'Shifted to '.($shift->targetProgram?->name ?? 'a new program').'. Credited subjects: '.$credited.' / '.$total.'. Remaining: '.max(0, $total - $credited).'.',
            [],
        )]);
    }

    /**
     * @param  list<string>  $subjects
     * @return array{code: string, label: string, details: string, subjects: list<string>}
     */
    private function reason(string $code, string $label, string $details, array $subjects): array
    {
        return ['code' => $code, 'label' => $label, 'details' => $details, 'subjects' => array_values($subjects)];
    }

    /** @param array<string, mixed> $result */
    private function subjectCode(array $result): string
    {
        return (string) ($result['course_specification']?->course?->code ?? 'Subject');
    }

    /** @param array<string, mixed> $result */
    private function subjectLabel(array $result): string
    {
        $title = $result['course_specification']?->title;

        return $this->subjectCode($result).($title ? ' – '.$title : '');
    }

    private function termLabel(?Term $term): string
    {
        return $term === null ? 'an earlier term' : collect([$term->label, $term->academicYear?->label])->filter()->implode(' ');
    }
}
