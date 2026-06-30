<?php

namespace App\Actions\StudentLifecycle;

use App\Models\CalendarEvent;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRosterRow;
use App\Models\Hold;
use App\Models\ProgramShiftCreditEntry;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\StudentScheduleBinding;
use App\Models\Term;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StudentLifecycleService
{
    public function __construct(
        private readonly HoldEvaluationService $holds,
        private readonly LifecycleFinanceAction $finance,
    ) {}

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function preview(array $data): array
    {
        $student = StudentProfile::query()->findOrFail((int) ($data['student_profile_id'] ?? 0));
        $term = Term::query()->findOrFail((int) ($data['term_id'] ?? 0));
        $type = (string) ($data['type'] ?? '');
        $this->validateCommon($data, $student, $term, $type);
        $enrollment = filled($data['enrollment_id'] ?? null)
            ? Enrollment::query()->where('student_profile_id', $student->id)->findOrFail((int) $data['enrollment_id'])
            : null;
        $courseEnrollments = $this->affectedCourseEnrollments($type, $enrollment, $data);

        return [
            'student_profile_id' => (int) $student->id,
            'term_id' => (int) $term->id,
            'type' => $type,
            'enrollment_id' => $enrollment?->id,
            'course_enrollment_ids' => $courseEnrollments->modelKeys(),
            'binding_ids' => StudentScheduleBinding::query()->whereIn('course_enrollment_id', $courseEnrollments->modelKeys())->where('is_active', true)->pluck('id')->all(),
            'reservation_ids' => EnrollmentSeatReservation::query()->whereIn('course_enrollment_id', $courseEnrollments->modelKeys())->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())->pluck('id')->all(),
            'master_schedule_changes' => 0,
            'profile_status_after' => $this->profileStatusAfter($type, $student),
            'curriculum_version_after' => $data['target_curriculum_version_id'] ?? $student->curriculum_version_id,
            'finance_adjustment' => (float) ($data['finance_adjustment'] ?? 0),
            'cor_available_after' => ! in_array($type, [StudentLifecycleChange::TypeWithdrawal, StudentLifecycleChange::TypeTransferOut], true),
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
            $enrollment = filled($data['enrollment_id'] ?? null)
                ? Enrollment::query()->lockForUpdate()->where('student_profile_id', $student->id)->findOrFail((int) $data['enrollment_id'])
                : null;
            $existing = StudentLifecycleChange::query()
                ->where('student_profile_id', $student->id)
                ->where('type', $type)
                ->where('term_id', $term->id)
                ->when(filled($data['course_enrollment_id'] ?? null), fn ($query) => $query->where('course_enrollment_id', $data['course_enrollment_id']))
                ->whereIn('state', [StudentLifecycleChange::StateRecordedApproved, StudentLifecycleChange::StateApplied])
                ->lockForUpdate()
                ->first();

            if ($existing instanceof StudentLifecycleChange) {
                return $existing;
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
                'decided_on' => $data['decided_on'],
                'authority' => $data['authority'],
                'late_exception_authority' => $data['late_exception_authority'] ?? null,
                'late_exception_reason' => $data['late_exception_reason'] ?? null,
                'private_source_reference' => $data['private_source_reference'] ?? null,
                'reason' => $data['reason'],
                'impact_snapshot' => $preview,
                'recorded_by' => $actor->id,
                'state' => $type === StudentLifecycleChange::TypeProgramShift
                    ? StudentLifecycleChange::StateRecordedApproved
                    : StudentLifecycleChange::StateApplied,
            ]);

            if ($type === StudentLifecycleChange::TypeProgramShift) {
                $this->recordShiftCredits($change, $data['credit_entries'] ?? []);
            } else {
                $this->applyImmediateEffects($change, $student, $enrollment, $actor);
            }

            $this->finance->execute($change, (float) ($data['finance_adjustment'] ?? 0), $actor);
            $this->recordAudit($change, $actor, 'student_lifecycle_change_recorded');

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
                throw new RuntimeException('Only a recorded-approved Program Shift may be applied.');
            }

            $term = Term::query()->lockForUpdate()->findOrFail($locked->term_id);
            if ($term->starts_on->isFuture()) {
                throw new RuntimeException('Program Shift cannot be applied before its effective term.');
            }
            if ($locked->target_program_id === null || $locked->target_curriculum_version_id === null) {
                throw new RuntimeException('Program Shift target program and curriculum are required.');
            }
            if (! $locked->programShiftCredits()->exists()) {
                throw new RuntimeException('Program Shift credit checklist is required.');
            }

            $student = StudentProfile::query()->lockForUpdate()->findOrFail($locked->student_profile_id);
            $student->update([
                'program_id' => $locked->target_program_id,
                'curriculum_version_id' => $locked->target_curriculum_version_id,
            ]);
            $locked->update(['state' => StudentLifecycleChange::StateApplied]);
            $this->recordAudit($locked, $actor, 'program_shift_applied');

            return $locked->refresh();
        }, attempts: 3);
    }

    /** @param array<string,mixed> $data */
    private function validateCommon(array $data, StudentProfile $student, Term $term, string $type): void
    {
        if (! array_key_exists($type, StudentLifecycleChange::typeOptions())) {
            throw new RuntimeException('Unsupported lifecycle change type.');
        }
        foreach (['effective_on', 'decided_on', 'authority', 'reason'] as $required) {
            if (blank($data[$required] ?? null)) {
                throw new RuntimeException("Lifecycle field [$required] is required.");
            }
        }
        $this->validateWindow($type, $term, $data);

        if ($type === StudentLifecycleChange::TypeSubjectDrop && blank($data['course_enrollment_id'] ?? null)) {
            throw new RuntimeException('Subject Drop requires one course enrollment.');
        }
        if ($type === StudentLifecycleChange::TypeLeaveOfAbsence && blank($data['expected_return_term_id'] ?? null)) {
            throw new RuntimeException('Leave of Absence requires an expected return term.');
        }
        if ($type === StudentLifecycleChange::TypeProgramShift) {
            if (blank($data['target_program_id'] ?? null) || blank($data['target_curriculum_version_id'] ?? null) || empty($data['credit_entries'] ?? [])) {
                throw new RuntimeException('Program Shift requires a future target curriculum and credit checklist.');
            }
            if (! $term->starts_on->isFuture()) {
                throw new RuntimeException('Program Shift must target a future term.');
            }
        }
        if ($type === StudentLifecycleChange::TypeReactivation) {
            if (! in_array($student->lifecycle_status, [StudentProfile::LifecycleArchived, StudentProfile::LifecycleInactive, StudentProfile::LifecycleLeaveOfAbsence, StudentProfile::LifecycleWithdrawn], true)) {
                throw new RuntimeException('Student is not in an eligible reactivation state.');
            }
            if ($this->holds->hasActiveBlockingHold($student, [Hold::BlockingReactivation])) {
                throw new RuntimeException('An effective reactivation hold remains unresolved.');
            }
        }
    }

    /** @param array<string,mixed> $data */
    private function validateWindow(string $type, Term $term, array $data): void
    {
        $key = match ($type) {
            StudentLifecycleChange::TypeSubjectDrop => 'subject_drop',
            StudentLifecycleChange::TypeWithdrawal => 'withdrawal',
            StudentLifecycleChange::TypeLeaveOfAbsence => 'leave_of_absence',
            StudentLifecycleChange::TypeProgramShift => 'program_shift',
            StudentLifecycleChange::TypeTransferOut => 'transfer_out',
            StudentLifecycleChange::TypeReactivation => 'reactivation',
            default => throw new RuntimeException('Unsupported lifecycle change type.'),
        };
        $insideWindow = CalendarEvent::query()
            ->where('term_id', $term->id)
            ->where('event_type', CalendarEvent::TypeWindow)
            ->where('process_key', $key)
            ->where('state', CalendarEvent::StateActive)
            ->where('start_at', '<=', now())
            ->where('end_at', '>=', now())
            ->exists();

        if (! $insideWindow && (blank($data['late_exception_authority'] ?? null) || blank($data['late_exception_reason'] ?? null))) {
            throw new RuntimeException('Lifecycle action is outside its configured window and requires a recorded late exception.');
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

        if ($type === StudentLifecycleChange::TypeLeaveOfAbsence && $change->effective_on->isFuture()) {
            Hold::query()->firstOrCreate(
                ['student_profile_id' => $student->id, 'term_id' => $change->term_id, 'source_type' => StudentLifecycleChange::class, 'source_id' => $change->id],
                ['hold_type' => Hold::TypeEnrollment, 'blocking_level' => Hold::BlockingEnrollment, 'status' => Hold::StatusActive,
                    'reason' => 'Approved future Leave of Absence.', 'student_message' => 'Enrollment is unavailable during your approved leave.',
                    'created_by' => $actor->id, 'effective_at' => $change->term->starts_on, 'resolution_requirement' => 'Reach the approved return term.'],
            );

            return;
        }

        $courses = $this->affectedCourseEnrollments($type, $enrollment, ['course_enrollment_id' => $change->course_enrollment_id]);
        if ($type === StudentLifecycleChange::TypeSubjectDrop) {
            if ($courses->count() !== 1) {
                throw new RuntimeException('Subject Drop requires one active official course enrollment.');
            }
            if (CourseEnrollment::query()->where('enrollment_id', $enrollment?->id)->where('status', CourseEnrollment::StatusActive)->count() <= 1) {
                throw new RuntimeException('Subject Drop cannot remove the last active subject.');
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
        $row = GradeRosterRow::query()->where('course_enrollment_id', $course->id)->lockForUpdate()->first();
        if ($row instanceof GradeRosterRow) {
            $code = $change->type === StudentLifecycleChange::TypeSubjectDrop ? 'DRP' : 'W';
            $row->update(['current_outcome_code' => $code, 'current_outcome_category' => GradeRosterRow::CategoryWithdrawn, 'released_at' => now()]);
            GradeOutcomeEvent::query()->create([
                'grade_roster_row_id' => $row->id, 'event_type' => GradeOutcomeEvent::TypeLifecycleOutcome,
                'previous_value' => null, 'new_value' => null, 'previous_category' => null,
                'new_category' => GradeRosterRow::CategoryWithdrawn, 'authority' => $change->authority,
                'reason' => $change->reason, 'evidence_reference' => $change->private_source_reference,
                'recorded_by' => $actor->id,
            ]);
        }
    }

    private function assertNoFinalReleasedOutcome(CourseEnrollment $course): void
    {
        $row = GradeRosterRow::query()->where('course_enrollment_id', $course->id)->lockForUpdate()->first();
        if ($row instanceof GradeRosterRow && $row->released_at !== null && ! in_array($row->current_outcome_code, [null, 'P', 'INC'], true)) {
            throw new RuntimeException('Subject Drop is unavailable after a final released Grade Outcome.');
        }
    }

    private function profileStatusAfter(string $type, StudentProfile $student): string
    {
        return match ($type) {
            StudentLifecycleChange::TypeWithdrawal => StudentProfile::LifecycleWithdrawn,
            StudentLifecycleChange::TypeLeaveOfAbsence => StudentProfile::LifecycleLeaveOfAbsence,
            StudentLifecycleChange::TypeTransferOut => StudentProfile::LifecycleTransferredOut,
            StudentLifecycleChange::TypeReactivation => StudentProfile::LifecycleActive,
            default => $student->lifecycle_status,
        };
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

    private function authorizeRegistrar(User $actor): void
    {
        if (! $actor->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleSystemSuperAdmin])) {
            throw new AuthorizationException('Only an authorized Registrar may record lifecycle results.');
        }
    }

    private function recordAudit(StudentLifecycleChange $change, User $actor, string $event): void
    {
        DB::table('activity_log')->insert([
            'log_name' => 'student_lifecycle', 'description' => str($event)->headline(),
            'subject_type' => StudentLifecycleChange::class, 'subject_id' => $change->id,
            'event' => $event, 'causer_type' => User::class, 'causer_id' => $actor->id,
            'properties' => json_encode(['type' => $change->type, 'impact_snapshot' => $change->impact_snapshot]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
