<?php

namespace App\Actions\StudentLifecycle;

use App\Actions\Academics\AcademicRecordNotificationService;
use App\Actions\Academics\IrregularStudentReasons;
use App\Actions\Academics\OfficialCourseResultProjection;
use App\Actions\Calendar\CalendarPhaseGateService;
use App\Actions\StudentLifecycle\Exceptions\StudentLifecycleRuleViolation;
use App\Models\AcademicDecision;
use App\Models\CalendarEvent;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRosterRow;
use App\Models\Hold;
use App\Models\PriorAcademicCredit;
use App\Models\Program;
use App\Models\ProgramShiftCreditEntry;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StudentLifecycleService
{
    public function __construct(
        private readonly HoldEvaluationService $holds,
        private readonly LifecycleFinanceAction $finance,
        private readonly AcademicRecordNotificationService $notifications,
        private readonly OfficialCourseResultProjection $results,
        private readonly CalendarPhaseGateService $calendar,
    ) {}

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function preview(array $data, bool $checkWindow = true): array
    {
        $student = StudentProfile::query()->findOrFail((int) ($data['student_profile_id'] ?? 0));
        $term = Term::query()->findOrFail((int) ($data['term_id'] ?? 0));
        $type = (string) ($data['type'] ?? '');
        $shiftCredits = $this->validateCommon($data, $student, $term, $type, $checkWindow);
        $effectiveOn = Carbon::parse($data['effective_on'])->startOfDay();
        $availableOn = $effectiveOn->greaterThan($term->starts_on) ? $effectiveOn : $term->starts_on;
        $allowsPreTermShiftApplication = $type === StudentLifecycleChange::TypeProgramShift
            && $this->calendar->isCutoverActive((int) $term->id);
        $pending = $allowsPreTermShiftApplication
            ? false
            : ($type === StudentLifecycleChange::TypeProgramShift
                || $availableOn->toDateString() > today('Asia/Manila')->toDateString());
        $enrollment = $type === StudentLifecycleChange::TypeProgramShift
            ? null
            : $this->resolveEnrollment($student, $term, $data['enrollment_id'] ?? null, lock: false);
        $this->validateEnrollmentTerm($enrollment, $term);
        $courseEnrollments = $this->affectedCourseEnrollments($type, $enrollment, $data);
        if ($pending) {
            $courseEnrollments = $courseEnrollments->take(0);
        }
        if ($type !== StudentLifecycleChange::TypeSubjectDrop) {
            $protectedCourseIds = GradeRosterRow::query()->whereIn('course_enrollment_id', $courseEnrollments->modelKeys())
                ->whereNotNull('released_at')->pluck('course_enrollment_id');
            $courseEnrollments = $courseEnrollments->reject(fn (CourseEnrollment $course): bool => $protectedCourseIds->contains($course->id));
        }
        $courseEnrollments->loadMissing('termOffering.curriculumEntry.courseSpecification.course');
        $targetProgram = filled($data['target_program_id'] ?? null)
            ? Program::query()->findOrFail((int) $data['target_program_id'])
            : $student->program;
        $targetCurriculum = filled($data['target_curriculum_version_id'] ?? null)
            ? CurriculumVersion::query()->findOrFail((int) $data['target_curriculum_version_id'])
            : $student->curriculumVersion;
        $activeHolds = Hold::query()
            ->where('student_profile_id', $student->id)
            ->where('status', Hold::StatusActive)
            ->get()
            ->map(fn (Hold $hold): array => [
                'id' => $hold->id,
                'type' => str($hold->hold_type)->headline()->toString(),
                'effect' => str($hold->blocking_level)->headline()->toString(),
                'office' => $hold->studentFacingOfficeLabel(),
            ])
            ->values()
            ->all();

        return [
            'student_profile_id' => (int) $student->id,
            'program_shift_credits' => $shiftCredits,
            'term_id' => (int) $term->id,
            'type' => $type,
            'enrollment_id' => $enrollment?->id,
            'course_enrollment_ids' => $courseEnrollments->modelKeys(),
            'affected_subjects' => $courseEnrollments
                ->map(fn (CourseEnrollment $courseEnrollment): array => [
                    'id' => $courseEnrollment->id,
                    'code' => $courseEnrollment->termOffering?->course()?->code,
                    'title' => $courseEnrollment->termOffering?->courseSpecification()?->title,
                    'status_before' => $courseEnrollment->status,
                ])
                ->values()
                ->all(),
            'reservation_ids' => EnrollmentSeatReservation::query()->whereIn('course_enrollment_id', $courseEnrollments->modelKeys())->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())->pluck('id')->all(),
            'master_schedule_changes' => 0,
            'profile_status_before' => $student->lifecycle_status,
            'profile_status_after' => $pending ? $student->lifecycle_status : $this->profileStatusAfter($type, $student),
            'profile_status_on_application' => $this->profileStatusAfter($type, $student),
            'pending_application' => $pending,
            'application_available_on' => $allowsPreTermShiftApplication
                ? today('Asia/Manila')->toDateString()
                : $availableOn->toDateString(),
            'program_before' => ['id' => $student->program_id, 'name' => $student->program->name],
            'program_after' => ['id' => $targetProgram->id, 'name' => $targetProgram->name],
            'curriculum_version_before' => ['id' => $student->curriculum_version_id, 'name' => $student->curriculumVersion->name],
            'curriculum_version_after' => ['id' => $targetCurriculum->id, 'name' => $targetCurriculum->name],
            'active_holds' => $activeHolds,
            'active_hold_count' => count($activeHolds),
            'released_subject_count' => $enrollment instanceof Enrollment
                ? CourseEnrollment::query()
                    ->where('enrollment_id', $enrollment->id)
                    ->where('status', CourseEnrollment::StatusActive)
                    ->whereHas('gradeRosterRow', fn ($row) => $row->whereNotNull('released_at'))
                    ->count()
                : 0,
            'finance_effect' => $this->financeEffect($pending ? null : $enrollment, $type),
            'cor_available_after' => $pending ? $enrollment?->current_cor_version_id !== null : $this->corAvailableAfter($type),
            'cor_available_before' => $enrollment?->current_cor_version_id !== null,
        ];
    }

    /** @param array<string,mixed> $data */
    public function record(array $data, User $actor, bool $suppressNotification = false): StudentLifecycleChange
    {
        $this->authorizeRegistrar($actor);

        return DB::transaction(function () use ($data, $actor, $suppressNotification): StudentLifecycleChange {
            $student = StudentProfile::query()->lockForUpdate()->findOrFail((int) ($data['student_profile_id'] ?? 0));
            $term = Term::query()->lockForUpdate()->findOrFail((int) ($data['term_id'] ?? 0));
            $type = (string) ($data['type'] ?? '');
            $enrollment = $type === StudentLifecycleChange::TypeProgramShift
                ? null
                : $this->resolveEnrollment($student, $term, $data['enrollment_id'] ?? null, lock: true);
            $this->validateEnrollmentTerm($enrollment, $term);
            $this->assertNotSuperseded($student, Carbon::parse($data['effective_on'])->startOfDay(), $term);
            $existing = StudentLifecycleChange::query()
                ->where('student_profile_id', $student->id)
                ->where('type', $type)
                ->where('term_id', $term->id)
                ->when(filled($data['course_enrollment_id'] ?? null), fn ($query) => $query->where('course_enrollment_id', $data['course_enrollment_id']))
                ->whereIn('state', [StudentLifecycleChange::StateRecordedApproved, StudentLifecycleChange::StateApplied])
                ->lockForUpdate()
                ->first();

            if ($existing instanceof StudentLifecycleChange) {
                if ($existing->effective_on->toDateString() !== Carbon::parse($data['effective_on'])->toDateString() || trim($existing->reason) !== trim((string) ($data['reason'] ?? ''))) {
                    throw new StudentLifecycleRuleViolation('A different approved change already exists for this student and term. Open it, or cancel a pending approval before recording its replacement.');
                }

                return $existing;
            }

            if ($type === StudentLifecycleChange::TypeProgramShift) {
                $this->lockShiftSourceRows($student, $data['credit_entries'] ?? []);
            }
            $preview = $this->preview($data);

            $change = StudentLifecycleChange::query()->create([
                'student_profile_id' => $student->id,
                'term_id' => $term->id,
                'expected_return_term_id' => $data['expected_return_term_id'] ?? null,
                'target_program_id' => $data['target_program_id'] ?? null,
                'target_curriculum_version_id' => $data['target_curriculum_version_id'] ?? null,
                'type' => $type,
                'enrollment_id' => $type === StudentLifecycleChange::TypeProgramShift ? null : $enrollment?->id,
                'course_enrollment_id' => $data['course_enrollment_id'] ?? null,
                'requested_on' => $data['requested_on'] ?? null,
                'effective_on' => $data['effective_on'],
                'decided_on' => filled($data['decided_on'] ?? null) ? $data['decided_on'] : null,
                'authority' => filled($data['authority'] ?? null) ? trim((string) $data['authority']) : null,
                'late_exception_authority' => $data['late_exception_authority'] ?? null,
                'late_exception_reason' => $data['late_exception_reason'] ?? null,
                'private_source_reference' => $data['private_source_reference'] ?? null,
                'reason' => $data['reason'],
                'impact_snapshot' => $preview,
                'recorded_by' => $actor->id,
                'state' => $type === StudentLifecycleChange::TypeProgramShift || $preview['pending_application']
                    ? StudentLifecycleChange::StateRecordedApproved
                    : StudentLifecycleChange::StateApplied,
            ]);

            if ($type === StudentLifecycleChange::TypeProgramShift) {
                $this->recordShiftCredits($change, $preview['program_shift_credits']);
            } elseif (! $preview['pending_application']) {
                $this->applyImmediateEffects($change, $student, $enrollment, $actor);
                $this->finance->execute($change, $actor);
            }

            $this->recordAudit($change, $actor, 'student_lifecycle_change_recorded');
            $this->notifications->recordLifecycleAfterCommit($change, $suppressNotification);

            return $change->refresh();
        }, attempts: 3);
    }

    public function applyProgramShift(StudentLifecycleChange $change, User $actor): StudentLifecycleChange
    {
        $this->authorizeRegistrar($actor);

        return DB::transaction(function () use ($change, $actor): StudentLifecycleChange {
            $locked = StudentLifecycleChange::query()->lockForUpdate()->findOrFail($change->id);
            if ($locked->state === StudentLifecycleChange::StateApplied) {
                return $locked;
            }
            if ($locked->type !== StudentLifecycleChange::TypeProgramShift || $locked->state !== StudentLifecycleChange::StateRecordedApproved) {
                throw new StudentLifecycleRuleViolation('Only a recorded-approved Program Shift may be applied.');
            }

            $term = Term::query()->lockForUpdate()->findOrFail($locked->term_id);
            $canApplyForPreTermEnrollment = $this->calendar->isCutoverActive((int) $term->id);
            if (! $canApplyForPreTermEnrollment && $term->starts_on->toDateString() > today('Asia/Manila')->toDateString()) {
                throw new StudentLifecycleRuleViolation('Program Shift cannot be applied before its effective term.');
            }
            if (! $canApplyForPreTermEnrollment && $locked->effective_on->toDateString() > today('Asia/Manila')->toDateString()) {
                throw new StudentLifecycleRuleViolation('Program Shift cannot be applied before its recorded effective date.');
            }
            if ($locked->target_program_id === null || $locked->target_curriculum_version_id === null) {
                throw new StudentLifecycleRuleViolation('Program Shift target program and curriculum are required.');
            }
            $targetCurriculum = CurriculumVersion::query()->findOrFail($locked->target_curriculum_version_id);
            if ((int) $targetCurriculum->program_id !== (int) $locked->target_program_id) {
                throw new StudentLifecycleRuleViolation('Program Shift target curriculum must belong to the target program.');
            }
            if ($targetCurriculum->state !== CurriculumVersion::StateActive) {
                throw new StudentLifecycleRuleViolation('Program Shift target curriculum must be active.');
            }
            $credits = $locked->programShiftCredits()->get();
            $creditRows = [];
            foreach ($credits as $credit) {
                if ($credit instanceof ProgramShiftCreditEntry) {
                    $creditRows[] = [
                        'curriculum_entry_id' => $credit->curriculum_entry_id,
                        'source_course_id' => $credit->source_course_id,
                        'source_grade_outcome_event_id' => $credit->source_grade_outcome_event_id,
                        'source_prior_academic_credit_id' => $credit->source_prior_academic_credit_id,
                        'treatment' => $credit->treatment,
                        'numeric_grade' => $credit->numeric_grade,
                    ];
                }
            }
            $student = StudentProfile::query()->lockForUpdate()->findOrFail($locked->student_profile_id);
            if ($student->blocksEnrollmentByLifecycle()) {
                throw new StudentLifecycleRuleViolation('Student lifecycle status does not allow a Program Shift.');
            }
            $hasOfficialEnrollment = Enrollment::query()
                ->where('student_profile_id', $student->id)
                ->where('term_id', $term->id)
                ->where(fn ($query) => $query
                    ->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled)
                    ->orWhereNotNull('officially_enrolled_at'))
                ->exists();
            if ($hasOfficialEnrollment) {
                throw new StudentLifecycleRuleViolation('Program Shift cannot be applied for a term where the student is already officially enrolled.');
            }
            $this->lockShiftSourceRows($student, $creditRows);
            $validatedCredits = $this->validateProgramShiftCreditEntries($student, $targetCurriculum, $creditRows);
            foreach ($validatedCredits as $index => $entry) {
                if ($entry['treatment'] === ProgramShiftCreditEntry::TreatmentAccepted) {
                    $recordedGrade = $creditRows[$index]['numeric_grade'];
                    $currentGrade = $entry['numeric_grade'];
                    $gradeMismatch = ($recordedGrade === null) !== ($currentGrade === null)
                        || ($recordedGrade !== null && $currentGrade !== null && (float) $currentGrade !== (float) $recordedGrade);

                    if ((int) $entry['source_course_id'] !== (int) $creditRows[$index]['source_course_id'] || $gradeMismatch) {
                        throw new StudentLifecycleRuleViolation('The released passing result no longer matches the recorded credit evaluation. Review the approval before applying it.');
                    }
                }
            }
            $this->assertCurrentApprovalSource($locked, $student);
            $attributes = [
                'program_id' => $locked->target_program_id,
                'curriculum_version_id' => $locked->target_curriculum_version_id,
            ];
            if (! $this->hasActiveProgramIndependentRestriction($student)) {
                $attributes['academic_standing'] = StudentProfile::StandingRegular;
            }
            $student->update($attributes);
            $locked->update(['state' => StudentLifecycleChange::StateApplied]);
            Cache::forget(IrregularStudentReasons::ReviewCountCacheKey);
            $this->recordAudit($locked, $actor, 'program_shift_applied');
            $this->finance->execute($locked, $actor);
            $this->notifications->recordLifecycleAfterCommit($locked);

            return $locked->refresh();
        }, attempts: 3);
    }

    public function cancelProgramShift(StudentLifecycleChange $change, User $actor): StudentLifecycleChange
    {
        $this->authorizeRegistrar($actor);

        return DB::transaction(function () use ($change, $actor): StudentLifecycleChange {
            $locked = StudentLifecycleChange::query()->lockForUpdate()->findOrFail($change->id);
            if ($locked->state === StudentLifecycleChange::StateCancelled) {
                return $locked;
            }
            if ($locked->type !== StudentLifecycleChange::TypeProgramShift || $locked->state !== StudentLifecycleChange::StateRecordedApproved) {
                throw new StudentLifecycleRuleViolation('Only a recorded-approved Program Shift may be cancelled before application.');
            }

            $locked->update(['state' => StudentLifecycleChange::StateCancelled]);
            $this->recordAudit($locked, $actor, 'program_shift_cancelled');

            return $locked->refresh();
        }, attempts: 3);
    }

    public function apply(StudentLifecycleChange $change, User $actor): StudentLifecycleChange
    {
        if ($change->type === StudentLifecycleChange::TypeProgramShift) {
            return $this->applyProgramShift($change, $actor);
        }
        $this->authorizeRegistrar($actor);

        return DB::transaction(function () use ($change, $actor): StudentLifecycleChange {
            $locked = StudentLifecycleChange::query()->lockForUpdate()->findOrFail($change->id);
            if ($locked->state === StudentLifecycleChange::StateApplied) {
                return $locked;
            }
            if (! $locked->isPendingChange()) {
                throw new StudentLifecycleRuleViolation('Only an approval awaiting application may be applied.');
            }
            $student = StudentProfile::query()->lockForUpdate()->findOrFail($locked->student_profile_id);
            if ($locked->isFutureDated()) {
                throw new StudentLifecycleRuleViolation('This change cannot be applied before its effective date and term.');
            }
            $this->assertCurrentApprovalSource($locked, $student);
            $enrollment = $this->resolveEnrollment($student, $locked->term, $locked->enrollment_id, lock: true);
            if ($enrollment?->id !== $locked->enrollment_id) {
                throw new StudentLifecycleRuleViolation('The enrollment source changed. Cancel this approval and record the corrected change.');
            }
            $effects = $this->preview([...$locked->attributesToArray(), 'effective_on' => $locked->effective_on->toDateString()], checkWindow: false);
            $this->applyImmediateEffects($locked, $student, $enrollment, $actor);
            $locked->update(['state' => StudentLifecycleChange::StateApplied]);
            $this->finance->execute($locked, $actor);
            $this->recordAudit($locked, $actor, 'student_lifecycle_change_applied', $effects);
            $this->notifications->recordLifecycleAfterCommit($locked);

            return $locked->refresh();
        }, attempts: 3);
    }

    public function cancel(StudentLifecycleChange $change, User $actor): StudentLifecycleChange
    {
        if ($change->type === StudentLifecycleChange::TypeProgramShift) {
            return $this->cancelProgramShift($change, $actor);
        }
        $this->authorizeRegistrar($actor);

        return DB::transaction(function () use ($change, $actor): StudentLifecycleChange {
            $locked = StudentLifecycleChange::query()->lockForUpdate()->findOrFail($change->id);
            if ($locked->state === StudentLifecycleChange::StateCancelled) {
                return $locked;
            }
            if (! $locked->isPendingChange()) {
                throw new StudentLifecycleRuleViolation('Only an approval awaiting application may be cancelled.');
            }
            $locked->update(['state' => StudentLifecycleChange::StateCancelled]);
            $this->recordAudit($locked, $actor, 'student_lifecycle_change_cancelled');

            return $locked->refresh();
        }, attempts: 3);
    }

    private function assertCurrentApprovalSource(StudentLifecycleChange $change, StudentProfile $student): void
    {
        foreach (['profile_status_before' => 'lifecycle_status', 'program_before.id' => 'program_id', 'curriculum_version_before.id' => 'curriculum_version_id'] as $source => $attribute) {
            $original = data_get($change->impact_snapshot, $source);
            if ($original !== null && (string) $original !== (string) $student->{$attribute}) {
                throw new StudentLifecycleRuleViolation('The student record changed after this approval. Cancel it and record the corrected change.');
            }
        }
        $this->assertNotSuperseded($student, $change->effective_on, $change->term);
        if (StudentLifecycleChange::query()->where('student_profile_id', $student->id)
            ->where('state', StudentLifecycleChange::StateApplied)->where('id', '>', $change->id)
            ->whereDate('effective_on', '>=', $change->effective_on)->exists()) {
            throw new StudentLifecycleRuleViolation('A newer lifecycle change is already applied. Cancel this stale approval and record the corrected change.');
        }
    }

    private function assertNotSuperseded(StudentProfile $student, Carbon $effectiveOn, ?Term $term = null): void
    {
        $schoolDate = today('Asia/Manila')->toDateString();

        $superseded = StudentLifecycleChange::query()
            ->where('student_profile_id', $student->id)
            ->where('state', StudentLifecycleChange::StateApplied)
            ->whereDate('effective_on', '>', $effectiveOn)
            ->where(function ($query) use ($schoolDate, $term): void {
                $query->where('type', '!=', StudentLifecycleChange::TypeProgramShift)
                    ->orWhere(function ($shiftQuery) use ($schoolDate, $term): void {
                        $shiftQuery->where('type', StudentLifecycleChange::TypeProgramShift)
                            ->where(function ($scopeQuery) use ($schoolDate, $term): void {
                                $scopeQuery->whereHas('term', fn ($termQuery) => $termQuery->whereDate('starts_on', '<=', $schoolDate));
                                if ($term instanceof Term) {
                                    $scopeQuery->orWhere('term_id', $term->id);
                                }
                            });
                    });
            })
            ->exists();

        if ($superseded) {
            throw new StudentLifecycleRuleViolation('A later lifecycle change is already applied. Record a current authorized correction instead of replacing its effects.');
        }
    }

    /**
     * @param  array<string,mixed>  $data
     * @return list<array<string,mixed>>
     */
    private function validateCommon(array $data, StudentProfile $student, Term $term, string $type, bool $checkWindow = true): array
    {
        if (! array_key_exists($type, StudentLifecycleChange::typeOptions())) {
            throw new StudentLifecycleRuleViolation('Unsupported lifecycle change type.');
        }
        foreach (['effective_on', 'reason'] as $required) {
            if (blank($data[$required] ?? null)) {
                throw new StudentLifecycleRuleViolation($required === 'reason'
                    ? 'Enter the reason for this approved change.'
                    : 'Choose when this change takes effect.');
            }
        }
        if ($checkWindow) {
            $this->validateWindow($type, $term, $data);
        }
        $effectiveOn = Carbon::parse($data['effective_on'])->startOfDay();
        $this->assertNotSuperseded($student, $effectiveOn, $term);

        if ($type === StudentLifecycleChange::TypeSubjectDrop) {
            if (blank($data['course_enrollment_id'] ?? null)) {
                throw new StudentLifecycleRuleViolation('Subject Drop requires one course enrollment.');
            }
            $course = CourseEnrollment::query()->find((int) $data['course_enrollment_id']);
            if ($course instanceof CourseEnrollment) {
                $this->assertNoFinalReleasedOutcome($course);
            }
        }
        if ($type === StudentLifecycleChange::TypeLeaveOfAbsence && blank($data['expected_return_term_id'] ?? null)) {
            throw new StudentLifecycleRuleViolation('Leave of Absence requires an expected return term.');
        }
        if ($type === StudentLifecycleChange::TypeLeaveOfAbsence) {
            $returnTerm = Term::query()->find((int) $data['expected_return_term_id']);
            if (! $returnTerm || $returnTerm->starts_on->lessThanOrEqualTo($effectiveOn) || $returnTerm->starts_on->lessThanOrEqualTo($term->starts_on)) {
                throw new StudentLifecycleRuleViolation('Expected return term must start after the leave effective date and selected term start.');
            }
        }
        if ($type === StudentLifecycleChange::TypeProgramShift) {
            if (blank($data['target_program_id'] ?? null) || blank($data['target_curriculum_version_id'] ?? null)) {
                throw new StudentLifecycleRuleViolation('Program Shift requires a future target program and curriculum.');
            }
            if ($student->blocksEnrollmentByLifecycle()) {
                throw new StudentLifecycleRuleViolation('Student lifecycle status does not allow a Program Shift.');
            }
            if ((int) $data['target_program_id'] === (int) $student->program_id
                && (int) $data['target_curriculum_version_id'] === (int) $student->curriculum_version_id) {
                throw new StudentLifecycleRuleViolation('Program Shift must target a different program or curriculum version.');
            }
            $hasOfficialEnrollment = Enrollment::query()
                ->where('student_profile_id', $student->id)
                ->where('term_id', $term->id)
                ->where(fn ($query) => $query
                    ->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled)
                    ->orWhereNotNull('officially_enrolled_at'))
                ->exists();
            if ($hasOfficialEnrollment) {
                throw new StudentLifecycleRuleViolation('Program Shift cannot target a term where the student is already officially enrolled.');
            }
            $enrollmentWindow = $this->calendar->isCutoverActive((int) $term->id)
                ? $this->calendar->enrollmentWindow((int) $term->id)
                : null;
            if ($enrollmentWindow === null && $term->starts_on->toDateString() <= today('Asia/Manila')->toDateString()) {
                throw new StudentLifecycleRuleViolation('Program Shift must target a future term.');
            }
            $earliestEffectiveOn = $enrollmentWindow?->opens_on?->lt($term->starts_on)
                ? $enrollmentWindow->opens_on
                : $term->starts_on;
            if (Carbon::parse($data['effective_on'])->lt($earliestEffectiveOn)) {
                throw new StudentLifecycleRuleViolation('Program Shift effective date must be within the future effective term.');
            }
            $targetCurriculum = CurriculumVersion::query()->findOrFail((int) $data['target_curriculum_version_id']);
            if ((int) $targetCurriculum->program_id !== (int) $data['target_program_id']) {
                throw new StudentLifecycleRuleViolation('Program Shift target curriculum must belong to the target program.');
            }
            if ($targetCurriculum->state !== CurriculumVersion::StateActive) {
                throw new StudentLifecycleRuleViolation('Program Shift target curriculum must be active.');
            }

            return $this->validateProgramShiftCreditEntries($student, $targetCurriculum, $data['credit_entries'] ?? []);
        }
        if ($type === StudentLifecycleChange::TypeReactivation) {
            if (! in_array($student->lifecycle_status, [StudentProfile::LifecycleArchived, StudentProfile::LifecycleInactive, StudentProfile::LifecycleLeaveOfAbsence, StudentProfile::LifecycleWithdrawn], true)) {
                throw new StudentLifecycleRuleViolation('Student is not in an eligible reactivation state.');
            }
            if ($this->holds->hasActiveBlockingHold($student, [Hold::BlockingReactivation])) {
                throw new StudentLifecycleRuleViolation('An effective reactivation hold remains unresolved.');
            }
        }

        return [];
    }

    public function isWindowOpen(Term $term, string $type): bool
    {
        if ($type === StudentLifecycleChange::TypeProgramShift && $this->calendar->isCutoverActive((int) $term->id)) {
            return true;
        }

        $key = match ($type) {
            StudentLifecycleChange::TypeSubjectDrop => 'subject_drop',
            StudentLifecycleChange::TypeWithdrawal => 'withdrawal',
            StudentLifecycleChange::TypeLeaveOfAbsence => 'leave_of_absence',
            StudentLifecycleChange::TypeProgramShift => 'program_shift',
            StudentLifecycleChange::TypeTransferOut => 'transfer_out',
            StudentLifecycleChange::TypeReactivation => 'reactivation',
            StudentLifecycleChange::TypeInactivation => 'inactivation',
            default => null,
        };

        return $key !== null && CalendarEvent::query()
            ->where('term_id', $term->id)
            ->where('event_type', CalendarEvent::TypeWindow)
            ->where('process_key', $key)
            ->where('state', CalendarEvent::StateActive)
            ->where('start_at', '<=', now())
            ->where('end_at', '>=', now())
            ->exists();
    }

    /** @param array<string,mixed> $data */
    private function validateWindow(string $type, Term $term, array $data): void
    {
        if (! $this->isWindowOpen($term, $type) && (blank($data['late_exception_authority'] ?? null) || blank($data['late_exception_reason'] ?? null))) {
            throw new StudentLifecycleRuleViolation('Lifecycle action is outside its configured window and requires a recorded late exception.');
        }
    }

    public function resolveEnrollment(StudentProfile $student, Term $term, mixed $enrollmentId = null, bool $lock = false): ?Enrollment
    {
        $query = Enrollment::query()->where('student_profile_id', $student->id);
        if ($lock) {
            $query->lockForUpdate();
        }
        if (filled($enrollmentId)) {
            $enrollment = $query->find((int) $enrollmentId);
            if (! $enrollment instanceof Enrollment) {
                throw new StudentLifecycleRuleViolation('Choose an enrollment belonging to the selected student.');
            }
            $this->validateEnrollmentTerm($enrollment, $term);

            return $enrollment;
        }

        return $query->where('term_id', $term->id)->first();

    }

    private function validateEnrollmentTerm(?Enrollment $enrollment, Term $term): void
    {
        if ($enrollment instanceof Enrollment && (int) $enrollment->term_id !== (int) $term->id) {
            throw new StudentLifecycleRuleViolation('Choose an enrollment from the selected term.');
        }
    }

    private function affectedCourseEnrollments(string $type, ?Enrollment $enrollment, array $data)
    {
        if (! $enrollment instanceof Enrollment) {
            return CourseEnrollment::query()->whereRaw('1 = 0')->get();
        }
        $query = CourseEnrollment::query()->where('enrollment_id', $enrollment->id)->where('status', CourseEnrollment::StatusActive);
        if ($type === StudentLifecycleChange::TypeSubjectDrop) {
            $query->whereKey((int) $data['course_enrollment_id']);
        } else {
            $query->whereDoesntHave('gradeRosterRow', fn ($row) => $row->whereNotNull('released_at'));
        }

        return in_array($type, [StudentLifecycleChange::TypeSubjectDrop, StudentLifecycleChange::TypeWithdrawal, StudentLifecycleChange::TypeLeaveOfAbsence, StudentLifecycleChange::TypeTransferOut], true)
            ? $query->lockForUpdate()->get()
            : CourseEnrollment::query()->whereRaw('1 = 0')->get();
    }

    private function applyImmediateEffects(StudentLifecycleChange $change, StudentProfile $student, ?Enrollment $enrollment, User $actor): void
    {
        $type = $change->type;
        if ($type === StudentLifecycleChange::TypeReactivation) {
            $student->update(['lifecycle_status' => StudentProfile::LifecycleActive, 'archived_at' => null]);

            return;
        }

        if ($type === StudentLifecycleChange::TypeInactivation) {
            $student->update(['lifecycle_status' => StudentProfile::LifecycleInactive]);

            return;
        }

        $courses = $this->affectedCourseEnrollments($type, $enrollment, ['course_enrollment_id' => $change->course_enrollment_id]);
        if ($type === StudentLifecycleChange::TypeSubjectDrop) {
            if ($courses->count() !== 1) {
                throw new StudentLifecycleRuleViolation('Subject Drop requires one active official course enrollment.');
            }
            if (CourseEnrollment::query()->where('enrollment_id', $enrollment?->id)->where('status', CourseEnrollment::StatusActive)->count() <= 1) {
                throw new StudentLifecycleRuleViolation('Subject Drop cannot remove the last active subject.');
            }
            $this->assertNoFinalReleasedOutcome($courses->first());
        }

        foreach ($courses as $course) {
            $this->releaseCourse($course, $change, $actor);
        }

        if (in_array($type, [StudentLifecycleChange::TypeWithdrawal, StudentLifecycleChange::TypeLeaveOfAbsence, StudentLifecycleChange::TypeTransferOut], true)) {
            $enrollment?->update(['status' => 'withdrawn', 'withdrawn_at' => now(), 'status_reason' => $change->reason]);
            $student->update(['lifecycle_status' => $this->profileStatusAfter($type, $student)]);
        }
    }

    private function releaseCourse(CourseEnrollment $course, StudentLifecycleChange $change, User $actor): void
    {
        $row = GradeRosterRow::query()->where('course_enrollment_id', $course->id)->lockForUpdate()->first();
        if ($change->type !== StudentLifecycleChange::TypeSubjectDrop && $row?->released_at !== null) {
            return;
        }

        $status = $change->type === StudentLifecycleChange::TypeSubjectDrop ? CourseEnrollment::StatusDropped : CourseEnrollment::StatusWithdrawn;
        $course->update([
            'status' => $status,
            $status === CourseEnrollment::StatusDropped ? 'dropped_at' : 'withdrawn_at' => now(),
            'status_reason' => $change->reason,
        ]);
        EnrollmentSeatReservation::query()->where('course_enrollment_id', $course->id)->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())->update([
            'status' => EnrollmentSeatReservation::StatusReleased, 'released_at' => now(),
        ]);
        if ($row instanceof GradeRosterRow) {
            $code = $change->type === StudentLifecycleChange::TypeSubjectDrop ? 'DRP' : 'W';
            $row->update(['current_outcome_code' => $code, 'current_outcome_category' => GradeRosterRow::CategoryWithdrawn, 'released_at' => now()]);
            GradeOutcomeEvent::query()->create([
                'grade_roster_row_id' => $row->id, 'event_type' => GradeOutcomeEvent::TypeLifecycleOutcome,
                'result_code' => $code,
                'previous_value' => null, 'new_value' => null, 'previous_category' => null,
                'new_category' => GradeRosterRow::CategoryWithdrawn, 'authority' => $change->authority,
                'reason' => $change->reason, 'evidence_reference' => $change->private_source_reference,
                'recorded_by' => $actor->id, 'released_at' => now(),
                'source_key' => "lifecycle-change:{$change->id}:course-enrollment:{$course->id}",
            ]);
        }
    }

    private function assertNoFinalReleasedOutcome(CourseEnrollment $course): void
    {
        $row = GradeRosterRow::query()->where('course_enrollment_id', $course->id)->lockForUpdate()->first();
        if ($row instanceof GradeRosterRow && $row->released_at !== null && ! in_array($row->current_outcome_code, [null, 'INC'], true)) {
            throw new StudentLifecycleRuleViolation('Subject Drop is unavailable after a final released Grade Outcome.');
        }
    }

    private function profileStatusAfter(string $type, StudentProfile $student): string
    {
        return match ($type) {
            StudentLifecycleChange::TypeWithdrawal => StudentProfile::LifecycleWithdrawn,
            StudentLifecycleChange::TypeLeaveOfAbsence => StudentProfile::LifecycleLeaveOfAbsence,
            StudentLifecycleChange::TypeTransferOut => StudentProfile::LifecycleTransferredOut,
            StudentLifecycleChange::TypeReactivation => StudentProfile::LifecycleActive,
            StudentLifecycleChange::TypeInactivation => StudentProfile::LifecycleInactive,
            default => $student->lifecycle_status,
        };
    }

    private function corAvailableAfter(string $type): bool
    {
        return ! in_array($type, [
            StudentLifecycleChange::TypeLeaveOfAbsence,
            StudentLifecycleChange::TypeWithdrawal,
            StudentLifecycleChange::TypeTransferOut,
            StudentLifecycleChange::TypeInactivation,
        ], true);
    }

    /** @return array{mode: string, message: string} */
    private function financeEffect(?Enrollment $enrollment, string $type): array
    {
        if (! $enrollment instanceof Enrollment || $type === StudentLifecycleChange::TypeProgramShift) {
            return [
                'mode' => 'none',
                'message' => 'No enrollment-linked Accounting review is required.',
            ];
        }

        return [
            'mode' => 'accounting_review_required',
            'message' => 'Accounting review will be opened. No amount, refund, credit, penalty, forfeiture, balance change, or hold is inferred.',
        ];
    }

    /** @param list<array<string,mixed>> $entries */
    private function recordShiftCredits(StudentLifecycleChange $change, array $entries): void
    {
        foreach ($entries as $entry) {
            ProgramShiftCreditEntry::query()->create([
                'student_lifecycle_change_id' => $change->id,
                'curriculum_entry_id' => $entry['curriculum_entry_id'],
                'source_course_id' => $entry['source_course_id'] ?? null,
                'source_grade_outcome_event_id' => $entry['source_grade_outcome_event_id'] ?? null,
                'source_prior_academic_credit_id' => $entry['source_prior_academic_credit_id'] ?? null,
                'treatment' => $entry['treatment'],
                'state' => ProgramShiftCreditEntry::StateRecorded,
                'numeric_grade' => $entry['numeric_grade'] ?? null,
            ]);
        }
    }

    /** @return Collection<int, array<string, mixed>> */
    public function programShiftSources(StudentProfile $student): Collection
    {
        return $this->results->forStudent($student)
            ->filter(function (array $result): bool {
                if ($result['event'] instanceof GradeOutcomeEvent) {
                    return is_numeric($result['result'])
                        && (float) $result['result'] >= 1.00
                        && (float) $result['result'] <= 4.00;
                }

                $priorCredit = $result['prior_credit'] ?? null;

                return $priorCredit instanceof PriorAcademicCredit
                    && $priorCredit->state === PriorAcademicCredit::StateActive
                    && $priorCredit->isPassing();
            })
            ->values();
    }

    /**
     * Identifies target curriculum entries that are already satisfied by an identical SIA-passed course
     * or matched by an active course equivalency to a released passing SIA grade outcome event.
     *
     * @return array{
     *     already_satisfied: list<array{
     *         curriculum_entry_id: int,
     *         course_id: int,
     *         course_code: string,
     *         course_title: string,
     *         year_level: string|int|null,
     *         term_label: ?string,
     *         credit_units: float,
     *         source_grade_outcome_event_id: int,
     *         source_course_id: int,
     *         source_course_code: string,
     *         source_course_title: string,
     *         numeric_grade: string,
     *         term_label_taken: ?string,
     *     }>,
     *     equivalent_matches: list<array{
     *         curriculum_entry_id: int,
     *         course_id: int,
     *         course_code: string,
     *         course_title: string,
     *         year_level: string|int|null,
     *         term_label: ?string,
     *         credit_units: float,
     *         source_grade_outcome_event_id: int,
     *         source_course_id: int,
     *         source_course_code: string,
     *         source_course_title: string,
     *         numeric_grade: string,
     *         term_label_taken: ?string,
     *     }>,
     *     available_sources: list<array{
     *         source_grade_outcome_event_id: int,
     *         source_course_id: int,
     *         source_course_code: string,
     *         source_course_title: string,
     *         numeric_grade: string,
     *         term_label_taken: ?string,
     *     }>,
     * }
     */
    public function matchingSiaCreditSuggestions(StudentProfile $student, CurriculumVersion $targetCurriculum): array
    {
        $entries = CurriculumEntry::query()
            ->with([
                'courseSpecification.course',
                'courseSpecification.requirements' => fn ($query) => $query
                    ->where('state', CourseRequirement::StateActive)
                    ->where('rule_type', CourseRequirement::TypeEquivalency),
            ])
            ->where('curriculum_version_id', $targetCurriculum->id)
            ->orderBy('year_level')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        $sources = $this->programShiftSources($student)
            ->filter(fn (array $result): bool => $result['course_specification'] !== null
                && $result['course_specification']->course_id !== null
                && ($result['event'] instanceof GradeOutcomeEvent
                    || ($result['prior_credit'] ?? null) instanceof PriorAcademicCredit))
            ->values();

        $sourceSpecIds = $sources
            ->pluck('course_specification.id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $sourceEquivalenciesBySpec = $sourceSpecIds->isNotEmpty()
            ? CourseRequirement::query()
                ->whereIn('course_specification_id', $sourceSpecIds)
                ->where('state', CourseRequirement::StateActive)
                ->where('rule_type', CourseRequirement::TypeEquivalency)
                ->get()
                ->groupBy('course_specification_id')
                ->map(fn (Collection $rows): Collection => $rows
                    ->pluck('related_course_id')
                    ->filter()
                    ->map(fn (mixed $id): int => (int) $id)
                    ->unique()
                    ->values())
            : collect();

        $sourceKey = static fn (array $source): string => $source['event'] instanceof GradeOutcomeEvent
            ? 'event:'.$source['event']->id
            : 'prior:'.(int) ($source['prior_credit']?->id ?? 0);

        $latestByCourseId = $sources->keyBy(fn (array $result): int => (int) $result['course_specification']->course_id);
        $usedSourceKeys = [];
        $matchedEntryIds = [];
        $alreadySatisfied = [];
        $equivalentMatches = [];

        $buildSuggestion = static function (CurriculumEntry $entry, array $source): array {
            $targetSpec = $entry->courseSpecification;
            $sourceSpec = $source['course_specification'];
            $termTaken = $source['term'];
            $event = $source['event'] instanceof GradeOutcomeEvent ? $source['event'] : null;
            $priorCredit = ($source['prior_credit'] ?? null) instanceof PriorAcademicCredit ? $source['prior_credit'] : null;

            return [
                'curriculum_entry_id' => (int) $entry->id,
                'course_id' => (int) ($targetSpec?->course_id ?? 0),
                'course_code' => (string) ($targetSpec?->course?->code ?? ''),
                'course_title' => (string) ($targetSpec?->title ?? ''),
                'year_level' => $entry->year_level,
                'term_label' => $entry->term_label,
                'credit_units' => (float) ($targetSpec?->credit_units ?? 0.0),
                'source_grade_outcome_event_id' => $event !== null ? (int) $event->id : null,
                'source_prior_academic_credit_id' => $priorCredit !== null ? (int) $priorCredit->id : null,
                'source_course_id' => (int) ($sourceSpec?->course_id ?? 0),
                'source_course_code' => (string) ($sourceSpec?->course?->code ?? ''),
                'source_course_title' => (string) ($sourceSpec?->title ?? ''),
                'numeric_grade' => (string) $source['result'],
                'term_label_taken' => $termTaken instanceof Term
                    ? collect([$termTaken->label, $termTaken->academicYear?->label])->filter()->implode(' ')
                    : (collect([$source['period_label'] ?? null, $source['academic_year_label'] ?? null])->filter()->implode(' ') ?: null),
            ];
        };

        // Pass 1: Identical SIA-passed canonical courses
        foreach ($entries as $entry) {
            $targetCourseId = (int) ($entry->courseSpecification?->course_id ?? 0);
            if ($targetCourseId <= 0) {
                continue;
            }

            $directSource = $latestByCourseId->get($targetCourseId);
            if ($directSource === null) {
                continue;
            }

            $key = $sourceKey($directSource);
            if (in_array($key, $usedSourceKeys, true)) {
                continue;
            }

            $usedSourceKeys[] = $key;
            $matchedEntryIds[] = (int) $entry->id;
            $alreadySatisfied[] = $buildSuggestion($entry, $directSource);
        }

        // Pass 2: Active equivalency SIA-passed courses (forward or reverse)
        foreach ($entries as $entry) {
            if (in_array((int) $entry->id, $matchedEntryIds, true)) {
                continue;
            }

            $targetSpec = $entry->courseSpecification;
            $targetCourseId = (int) ($targetSpec?->course_id ?? 0);
            if ($targetSpec === null || $targetCourseId <= 0) {
                continue;
            }

            $targetEquivalentIds = $targetSpec->requirements
                ->where('state', CourseRequirement::StateActive)
                ->where('rule_type', CourseRequirement::TypeEquivalency)
                ->pluck('related_course_id')
                ->filter()
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values();

            $equivalentSource = $latestByCourseId->first(function (array $source) use ($targetCourseId, $targetEquivalentIds, $sourceEquivalenciesBySpec, $usedSourceKeys, $sourceKey): bool {
                if (in_array($sourceKey($source), $usedSourceKeys, true)) {
                    return false;
                }

                $sourceCourseId = (int) ($source['course_specification']?->course_id ?? 0);
                if ($sourceCourseId <= 0) {
                    return false;
                }

                if ($targetEquivalentIds->contains($sourceCourseId)) {
                    return true;
                }

                $sourceSpecId = (int) ($source['course_specification']?->id ?? 0);

                return $sourceEquivalenciesBySpec->get($sourceSpecId, collect())->contains($targetCourseId);
            });

            if ($equivalentSource === null) {
                continue;
            }

            $usedSourceKeys[] = $sourceKey($equivalentSource);
            $matchedEntryIds[] = (int) $entry->id;
            $equivalentMatches[] = $buildSuggestion($entry, $equivalentSource);
        }

        $availableSources = $sources->map(function (array $source): array {
            $sourceSpec = $source['course_specification'];
            $termTaken = $source['term'];
            $event = $source['event'] instanceof GradeOutcomeEvent ? $source['event'] : null;
            $priorCredit = ($source['prior_credit'] ?? null) instanceof PriorAcademicCredit ? $source['prior_credit'] : null;

            return [
                'source_grade_outcome_event_id' => $event !== null ? (int) $event->id : null,
                'source_prior_academic_credit_id' => $priorCredit !== null ? (int) $priorCredit->id : null,
                'source_course_id' => (int) ($sourceSpec?->course_id ?? 0),
                'source_course_code' => (string) ($sourceSpec?->course?->code ?? ''),
                'source_course_title' => (string) ($sourceSpec?->title ?? ''),
                'numeric_grade' => (string) $source['result'],
                'term_label_taken' => $termTaken instanceof Term
                    ? collect([$termTaken->label, $termTaken->academicYear?->label])->filter()->implode(' ')
                    : (collect([$source['period_label'] ?? null, $source['academic_year_label'] ?? null])->filter()->implode(' ') ?: null),
            ];
        })->values()->all();

        return [
            'already_satisfied' => $alreadySatisfied,
            'equivalent_matches' => $equivalentMatches,
            'available_sources' => $availableSources,
        ];
    }

    /** @param list<array<string,mixed>> $entries */
    private function lockShiftSourceRows(StudentProfile $student, array $entries): void
    {
        $eventIds = collect($entries)->where('treatment', ProgramShiftCreditEntry::TreatmentAccepted)
            ->pluck('source_grade_outcome_event_id')->filter();
        if ($eventIds->isEmpty()) {
            return;
        }
        GradeRosterRow::query()
            ->whereHas('courseEnrollment.enrollment', fn ($query) => $query->where('student_profile_id', $student->id))
            ->whereHas('outcomeEvents', fn ($query) => $query->whereIn('id', $eventIds))
            ->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return list<array<string,mixed>>
     */
    private function validateProgramShiftCreditEntries(StudentProfile $student, CurriculumVersion $targetCurriculum, array $entries): array
    {
        $allSources = collect($entries)->contains('treatment', ProgramShiftCreditEntry::TreatmentAccepted)
            ? $this->programShiftSources($student)
            : collect();
        $eventSources = $allSources
            ->filter(fn (array $result): bool => $result['event'] instanceof GradeOutcomeEvent)
            ->keyBy('event.id');
        $priorCreditSources = $allSources
            ->filter(fn (array $result): bool => ($result['prior_credit'] ?? null) instanceof PriorAcademicCredit)
            ->keyBy('prior_credit.id');

        $seen = [];
        $seenSourceKeys = [];
        $validated = [];
        foreach ($entries as $entry) {
            $curriculumEntryId = (int) ($entry['curriculum_entry_id'] ?? 0);
            if ($curriculumEntryId < 1) {
                throw new StudentLifecycleRuleViolation('Every Program Shift credit checklist row requires a target curriculum entry.');
            }
            if (in_array($curriculumEntryId, $seen, true)) {
                throw new StudentLifecycleRuleViolation('Program Shift credit checklist cannot contain duplicate target curriculum entries.');
            }
            $seen[] = $curriculumEntryId;

            $curriculumEntry = CurriculumEntry::query()
                ->where('curriculum_version_id', $targetCurriculum->id)
                ->find($curriculumEntryId);

            if (! $curriculumEntry instanceof CurriculumEntry) {
                throw new StudentLifecycleRuleViolation('Program Shift credit checklist entries must belong to the selected target curriculum.');
            }

            $treatment = (string) ($entry['treatment'] ?? '');
            if (! in_array($treatment, [
                ProgramShiftCreditEntry::TreatmentAccepted,
                ProgramShiftCreditEntry::TreatmentDeficient,
                ProgramShiftCreditEntry::TreatmentRejected,
            ], true)) {
                throw new StudentLifecycleRuleViolation('Program Shift credit checklist contains an unsupported treatment.');
            }

            $source = null;
            if ($treatment === ProgramShiftCreditEntry::TreatmentAccepted) {
                $eventId = (int) ($entry['source_grade_outcome_event_id'] ?? 0);
                $priorCreditId = (int) ($entry['source_prior_academic_credit_id'] ?? 0);

                if ($eventId > 0) {
                    $source = $eventSources->get($eventId);
                } elseif ($priorCreditId > 0) {
                    $source = $priorCreditSources->get($priorCreditId);
                }

                if ($source === null || $source['course_specification'] === null) {
                    throw new StudentLifecycleRuleViolation('Accepted credit requires this student’s current released passing result or prior academic credit. Choose an available result or review the credit evaluation.');
                }

                $sourceKey = $source['event'] instanceof GradeOutcomeEvent
                    ? 'event:'.$source['event']->id
                    : 'prior:'.$source['prior_credit']->id;

                if (in_array($sourceKey, $seenSourceKeys, true)) {
                    throw new StudentLifecycleRuleViolation('The same released passing result cannot be credited to multiple target subjects.');
                }
                $seenSourceKeys[] = $sourceKey;
            }

            $numericGrade = null;
            if ($source !== null && is_numeric($source['result'] ?? null)) {
                $numericGrade = (string) $source['result'];
            }

            $validated[] = [
                'curriculum_entry_id' => $curriculumEntryId,
                'treatment' => $treatment,
                'source_grade_outcome_event_id' => $source['event']->id ?? null,
                'source_prior_academic_credit_id' => $source['prior_credit']->id ?? null,
                'source_course_id' => $source['course_specification']->course_id ?? null,
                'numeric_grade' => $numericGrade,
            ];
        }

        return $validated;
    }

    private function hasActiveProgramIndependentRestriction(StudentProfile $student): bool
    {
        $schoolDate = today('Asia/Manila')->toDateString();

        return AcademicDecision::query()
            ->where('student_profile_id', $student->id)
            ->whereNull('term_id')
            ->where('state', AcademicDecision::StateActive)
            ->whereDate('effective_from', '<=', $schoolDate)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $schoolDate))
            ->whereIn('effect', [AcademicDecision::EffectBlocked, AcademicDecision::EffectPendingDecision])
            ->exists();
    }

    private function authorizeRegistrar(User $actor): void
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only an authorized Registrar may record lifecycle results.');
        }
    }

    private function recordAudit(StudentLifecycleChange $change, User $actor, string $event, array $applicationEffects = []): void
    {
        DB::table('activity_log')->insert([
            'log_name' => 'student_lifecycle', 'description' => str($event)->headline(),
            'subject_type' => StudentLifecycleChange::class, 'subject_id' => $change->id,
            'event' => $event, 'causer_type' => User::class, 'causer_id' => $actor->id,
            'properties' => json_encode(['type' => $change->type, 'impact_snapshot' => $change->impact_snapshot, 'application_effects' => $applicationEffects]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
