<?php

namespace App\Actions\StudentLifecycle;

use App\Actions\Academics\AcademicRecordNotificationService;
use App\Actions\Academics\OfficialCourseResultProjection;
use App\Actions\StudentLifecycle\Exceptions\StudentLifecycleRuleViolation;
use App\Models\CalendarEvent;
use App\Models\CourseEnrollment;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRosterRow;
use App\Models\Hold;
use App\Models\Program;
use App\Models\ProgramShiftCreditEntry;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\StudentScheduleBinding;
use App\Models\Term;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StudentLifecycleService
{
    public function __construct(
        private readonly HoldEvaluationService $holds,
        private readonly LifecycleFinanceAction $finance,
        private readonly AcademicRecordNotificationService $notifications,
        private readonly OfficialCourseResultProjection $results,
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
        $pending = $type === StudentLifecycleChange::TypeProgramShift || $availableOn->toDateString() > today('Asia/Manila')->toDateString();
        $enrollment = $this->resolveEnrollment($student, $term, $data['enrollment_id'] ?? null, lock: false);
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
            'binding_ids' => StudentScheduleBinding::query()->whereIn('course_enrollment_id', $courseEnrollments->modelKeys())->where('is_active', true)->pluck('id')->all(),
            'reservation_ids' => EnrollmentSeatReservation::query()->whereIn('course_enrollment_id', $courseEnrollments->modelKeys())->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())->pluck('id')->all(),
            'master_schedule_changes' => 0,
            'profile_status_before' => $student->lifecycle_status,
            'profile_status_after' => $pending ? $student->lifecycle_status : $this->profileStatusAfter($type, $student),
            'profile_status_on_application' => $this->profileStatusAfter($type, $student),
            'pending_application' => $pending,
            'application_available_on' => $availableOn->toDateString(),
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
    public function record(array $data, User $actor): StudentLifecycleChange
    {
        $this->authorizeRegistrar($actor);

        return DB::transaction(function () use ($data, $actor): StudentLifecycleChange {
            $student = StudentProfile::query()->lockForUpdate()->findOrFail((int) ($data['student_profile_id'] ?? 0));
            $term = Term::query()->lockForUpdate()->findOrFail((int) ($data['term_id'] ?? 0));
            $type = (string) ($data['type'] ?? '');
            $enrollment = $this->resolveEnrollment($student, $term, $data['enrollment_id'] ?? null, lock: true);
            $this->validateEnrollmentTerm($enrollment, $term);
            $this->assertNotSuperseded($student, Carbon::parse($data['effective_on'])->startOfDay());
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
                'enrollment_id' => $enrollment?->id,
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
                'state' => $preview['pending_application']
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
            $this->notifications->recordLifecycleAfterCommit($change);

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
            if ($term->starts_on->toDateString() > today('Asia/Manila')->toDateString()) {
                throw new StudentLifecycleRuleViolation('Program Shift cannot be applied before its effective term.');
            }
            if ($locked->effective_on->toDateString() > today('Asia/Manila')->toDateString()) {
                throw new StudentLifecycleRuleViolation('Program Shift cannot be applied before its recorded effective date.');
            }
            if ($locked->target_program_id === null || $locked->target_curriculum_version_id === null) {
                throw new StudentLifecycleRuleViolation('Program Shift target program and curriculum are required.');
            }
            $targetCurriculum = CurriculumVersion::query()->findOrFail($locked->target_curriculum_version_id);
            if ((int) $targetCurriculum->program_id !== (int) $locked->target_program_id) {
                throw new StudentLifecycleRuleViolation('Program Shift target curriculum must belong to the target program.');
            }
            $credits = $locked->programShiftCredits()->get();
            $creditRows = [];
            foreach ($credits as $credit) {
                if ($credit instanceof ProgramShiftCreditEntry) {
                    $creditRows[] = [
                        'curriculum_entry_id' => $credit->curriculum_entry_id,
                        'source_course_id' => $credit->source_course_id,
                        'source_grade_outcome_event_id' => $credit->source_grade_outcome_event_id,
                        'treatment' => $credit->treatment,
                        'numeric_grade' => $credit->numeric_grade,
                    ];
                }
            }
            $student = StudentProfile::query()->lockForUpdate()->findOrFail($locked->student_profile_id);
            $this->lockShiftSourceRows($student, $creditRows);
            $validatedCredits = $this->validateProgramShiftCreditEntries($student, $targetCurriculum, $creditRows);
            foreach ($validatedCredits as $index => $entry) {
                if ($entry['treatment'] === ProgramShiftCreditEntry::TreatmentAccepted
                    && ((int) $entry['source_course_id'] !== (int) $creditRows[$index]['source_course_id']
                        || (float) $entry['numeric_grade'] !== (float) $creditRows[$index]['numeric_grade'])) {
                    throw new StudentLifecycleRuleViolation('The released passing result no longer matches the recorded credit evaluation. Review the approval before applying it.');
                }
            }
            $this->assertCurrentApprovalSource($locked, $student);
            $student->update([
                'program_id' => $locked->target_program_id,
                'curriculum_version_id' => $locked->target_curriculum_version_id,
            ]);
            $locked->update(['state' => StudentLifecycleChange::StateApplied]);
            $this->recordAudit($locked, $actor, 'program_shift_applied');
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
        $this->assertNotSuperseded($student, $change->effective_on);
        if (StudentLifecycleChange::query()->where('student_profile_id', $student->id)
            ->where('state', StudentLifecycleChange::StateApplied)->where('id', '>', $change->id)
            ->whereDate('effective_on', '>=', $change->effective_on)->exists()) {
            throw new StudentLifecycleRuleViolation('A newer lifecycle change is already applied. Cancel this stale approval and record the corrected change.');
        }
    }

    private function assertNotSuperseded(StudentProfile $student, Carbon $effectiveOn): void
    {
        if (StudentLifecycleChange::query()->where('student_profile_id', $student->id)
            ->where('state', StudentLifecycleChange::StateApplied)->whereDate('effective_on', '>', $effectiveOn)->exists()) {
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
        $this->assertNotSuperseded($student, $effectiveOn);

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
            if ($term->starts_on->toDateString() <= today('Asia/Manila')->toDateString()) {
                throw new StudentLifecycleRuleViolation('Program Shift must target a future term.');
            }
            if (Carbon::parse($data['effective_on'])->lt($term->starts_on)) {
                throw new StudentLifecycleRuleViolation('Program Shift effective date must be within the future effective term.');
            }
            $targetCurriculum = CurriculumVersion::query()->findOrFail((int) $data['target_curriculum_version_id']);
            if ((int) $targetCurriculum->program_id !== (int) $data['target_program_id']) {
                throw new StudentLifecycleRuleViolation('Program Shift target curriculum must belong to the target program.');
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
        StudentScheduleBinding::query()->where('course_enrollment_id', $course->id)->where('is_active', true)->update([
            'is_active' => false, 'effective_until' => $change->effective_on, 'released_by' => $actor->id,
            'released_at' => now(), 'release_reason' => $change->reason,
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
            ->filter(fn (array $result): bool => $result['event'] instanceof GradeOutcomeEvent
                && is_numeric($result['result'])
                && (float) $result['result'] >= 1.00
                && (float) $result['result'] <= 4.00)
            ->values();
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
        $sources = collect($entries)->contains('treatment', ProgramShiftCreditEntry::TreatmentAccepted)
            ? $this->programShiftSources($student)->keyBy('event.id')
            : collect();

        $seen = [];
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
                $source = $sources->get((int) ($entry['source_grade_outcome_event_id'] ?? 0));
                if ($source === null || $source['course_specification'] === null) {
                    throw new StudentLifecycleRuleViolation('Accepted credit requires this student’s current released passing result. Choose an available result or review the credit evaluation.');
                }
            }
            $validated[] = [
                'curriculum_entry_id' => $curriculumEntryId,
                'treatment' => $treatment,
                'source_grade_outcome_event_id' => $source['event']->id ?? null,
                'source_course_id' => $source['course_specification']->course_id ?? null,
                'numeric_grade' => $source['result'] ?? null,
            ];
        }

        return $validated;
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
