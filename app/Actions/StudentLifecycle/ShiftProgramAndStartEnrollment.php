<?php

namespace App\Actions\StudentLifecycle;

use App\Actions\Academics\AcademicEnrollmentEffect;
use App\Actions\Academics\IrregularStudentReasons;
use App\Actions\Calendar\CalendarPhaseGateService;
use App\Actions\Enrollment\RegistrationWindowApplicability;
use App\Actions\Enrollment\StartRegistrationCase;
use App\Actions\StudentLifecycle\Exceptions\StudentLifecycleRuleViolation;
use App\Models\AcademicDecision;
use App\Models\Enrollment;
use App\Models\GradeRosterRow;
use App\Models\Hold;
use App\Models\ProgramShiftCreditEntry;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftProgramAndStartEnrollment
{
    public function __construct(
        private readonly StudentLifecycleService $lifecycle,
        private readonly StartRegistrationCase $startRegistration,
        private readonly HoldEvaluationService $holds,
        private readonly AcademicEnrollmentEffect $academicEffect,
        private readonly CalendarPhaseGateService $calendar,
        private readonly RegistrationWindowApplicability $windowApplicability,
    ) {}

    /**
     * Records a new Program Shift and, when requested, atomically applies it and starts an
     * Individually Advised continuing-student Registration Case in one transaction.
     *
     * @param  array<string, mixed>  $data
     * @return array{change: StudentLifecycleChange, enrollment: ?Enrollment, applied: bool}
     */
    public function recordAndOptionallyEnroll(
        array $data,
        User $actor,
        bool $applyAndEnroll = true,
    ): array {
        $this->authorizeRegistrar($actor);

        $shiftData = [
            ...$data,
            'type' => StudentLifecycleChange::TypeProgramShift,
            'enrollment_id' => null,
        ];

        $studentProfileId = (int) ($shiftData['student_profile_id'] ?? 0);
        $termId = (int) ($shiftData['term_id'] ?? 0);
        $studentUserId = StudentProfile::query()->whereKey($studentProfileId)->value('user_id');

        if ($studentUserId === null) {
            StudentProfile::query()->findOrFail($studentProfileId);
        }

        return DB::transaction(function () use ($shiftData, $actor, $applyAndEnroll, $studentProfileId, $termId, $studentUserId): array {
            // Canonical lock order: User -> Term -> StudentProfile -> Enrollment -> StudentLifecycleChange -> GradeRosterRow
            User::query()->whereKey((int) $studentUserId)->lockForUpdate()->firstOrFail();
            $term = Term::query()->whereKey($termId)->lockForUpdate()->firstOrFail();
            $student = StudentProfile::query()->whereKey($studentProfileId)->lockForUpdate()->firstOrFail();
            $existingEnrollments = $this->lockSameTermEnrollments($student, $term);
            StudentLifecycleChange::query()
                ->where('student_profile_id', $student->id)
                ->where('term_id', $term->id)
                ->where('type', StudentLifecycleChange::TypeProgramShift)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $this->lockShiftSourceRows($student, is_array($shiftData['credit_entries'] ?? null) ? $shiftData['credit_entries'] : []);

            if (! $applyAndEnroll) {
                $change = $this->lifecycle->record($shiftData, $actor, suppressNotification: false);

                return [
                    'change' => $change,
                    'enrollment' => null,
                    'applied' => false,
                ];
            }

            $this->assertEnrollmentStartPreconditions($student, $term, $existingEnrollments);

            $change = $this->lifecycle->record($shiftData, $actor, suppressNotification: true);
            $applied = $change->state === StudentLifecycleChange::StateApplied
                ? $change
                : $this->lifecycle->applyProgramShift($change, $actor);

            Cache::forget(IrregularStudentReasons::ReviewCountCacheKey);

            $enrollment = $this->startRegistration->forContinuingStudent(
                $student->refresh(),
                $term,
                $actor,
                'RegistrarAssisted',
            );

            return [
                'change' => $applied,
                'enrollment' => $enrollment,
                'applied' => true,
            ];
        }, attempts: 3);
    }

    /**
     * Atomically applies an existing RECORDED_APPROVED Program Shift and starts an
     * Individually Advised continuing-student Registration Case in one transaction.
     *
     * @return array{change: StudentLifecycleChange, enrollment: ?Enrollment, applied: bool}
     */
    public function applyExistingAndEnroll(
        StudentLifecycleChange $change,
        User $actor,
    ): array {
        $this->authorizeRegistrar($actor);

        $snapshot = StudentLifecycleChange::query()
            ->select(['id', 'student_profile_id', 'term_id'])
            ->findOrFail($change->id);
        $studentUserId = StudentProfile::query()->whereKey($snapshot->student_profile_id)->value('user_id');

        if ($studentUserId === null) {
            StudentProfile::query()->findOrFail($snapshot->student_profile_id);
        }

        return DB::transaction(function () use ($snapshot, $studentUserId, $actor): array {
            // Canonical lock order: User -> Term -> StudentProfile -> Enrollment -> StudentLifecycleChange -> GradeRosterRow
            User::query()->whereKey((int) $studentUserId)->lockForUpdate()->firstOrFail();
            $term = Term::query()->whereKey($snapshot->term_id)->lockForUpdate()->firstOrFail();
            $student = StudentProfile::query()->whereKey($snapshot->student_profile_id)->lockForUpdate()->firstOrFail();
            $existingEnrollments = $this->lockSameTermEnrollments($student, $term);
            $lockedChange = StudentLifecycleChange::query()->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();

            if ($lockedChange->type !== StudentLifecycleChange::TypeProgramShift
                || $lockedChange->state !== StudentLifecycleChange::StateRecordedApproved) {
                throw new StudentLifecycleRuleViolation('Only a recorded-approved Program Shift may be applied.');
            }

            $creditRows = $lockedChange->programShiftCredits()
                ->get()
                ->map(fn (ProgramShiftCreditEntry $credit): array => [
                    'treatment' => $credit->treatment,
                    'source_grade_outcome_event_id' => $credit->source_grade_outcome_event_id,
                ])
                ->all();
            $this->lockShiftSourceRows($student, $creditRows);

            $this->assertEnrollmentStartPreconditions($student, $term, $existingEnrollments);

            $applied = $this->lifecycle->applyProgramShift($lockedChange, $actor);

            Cache::forget(IrregularStudentReasons::ReviewCountCacheKey);

            $enrollment = $this->startRegistration->forContinuingStudent(
                $student->refresh(),
                $term,
                $actor,
                'RegistrarAssisted',
            );

            return [
                'change' => $applied,
                'enrollment' => $enrollment,
                'applied' => true,
            ];
        }, attempts: 3);
    }

    /**
     * @param  Collection<int, Enrollment>  $existingEnrollments
     */
    private function assertEnrollmentStartPreconditions(
        StudentProfile $student,
        Term $term,
        Collection $existingEnrollments,
    ): void {
        if ($student->blocksEnrollmentByLifecycle()) {
            throw new StudentLifecycleRuleViolation('The Student lifecycle does not allow registration.');
        }

        foreach ($existingEnrollments as $existing) {
            if ($existing->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled
                || $existing->officially_enrolled_at !== null) {
                throw new StudentLifecycleRuleViolation('This student is already officially enrolled in the selected term.');
            }

            if ($existing->canonical_outcome === Enrollment::OutcomeInProgress) {
                throw new StudentLifecycleRuleViolation('This student already has an in-progress enrollment in the selected term. Resolve or cancel it before shifting and starting enrollment.');
            }

            throw new StudentLifecycleRuleViolation('This student already has a closed registration case in the selected term. Reopen or resolve that case separately before shifting and starting enrollment.');
        }

        if ($term->state === Term::StateClosed) {
            throw ValidationException::withMessages([
                'term' => 'This term is closed and no longer accepts new enrollments.',
            ]);
        }

        $this->calendar->assertEnrollmentWindowOpen((int) $term->id);
        $this->windowApplicability->assertPermitted($term, Enrollment::SelectionIndividuallyAdvised, false);

        $blockingHold = $this->holds->mostRestrictiveActiveHold(
            $student,
            [Hold::BlockingEnrollment],
            term: $term,
        );

        if ($blockingHold instanceof Hold) {
            throw ValidationException::withMessages([
                'hold' => $this->holds->formatBlockingMessage($blockingHold),
            ]);
        }

        $effect = $this->academicEffect->forStudent($student, $term);
        if (in_array($effect['effect'], [AcademicDecision::EffectBlocked, AcademicDecision::EffectPendingDecision], true)) {
            throw ValidationException::withMessages([
                'academic_effect' => $effect['reason'],
            ]);
        }
    }

    /** @return Collection<int, Enrollment> */
    private function lockSameTermEnrollments(StudentProfile $student, Term $term): Collection
    {
        return Enrollment::query()
            ->where('term_id', $term->id)
            ->where(fn ($query) => $query
                ->where('student_profile_id', $student->id)
                ->orWhere('credential_user_id', $student->user_id))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** @param list<array<string, mixed>> $entries */
    private function lockShiftSourceRows(StudentProfile $student, array $entries): void
    {
        $eventIds = collect($entries)
            ->where('treatment', ProgramShiftCreditEntry::TreatmentAccepted)
            ->pluck('source_grade_outcome_event_id')
            ->filter();

        if ($eventIds->isEmpty()) {
            return;
        }

        GradeRosterRow::query()
            ->whereHas('courseEnrollment.enrollment', fn ($query) => $query->where('student_profile_id', $student->id))
            ->whereHas('outcomeEvents', fn ($query) => $query->whereIn('id', $eventIds))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function authorizeRegistrar(User $actor): void
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only an authorized Registrar may record and apply a Program Shift with enrollment start.');
        }
    }
}
