<?php

namespace App\Actions\Enrollment;

use App\Actions\Academics\AcademicEnrollmentEffect;
use App\Actions\Finance\EnrollmentFinanceClearanceService;
use App\Models\AcademicDecision;
use App\Models\ApplicantIntake;
use App\Models\Assessment;
use App\Models\ChecklistItem;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\EnrollmentException;
use App\Models\EnrollmentGateResult;
use App\Models\EnrollmentSeatReservation;
use App\Models\Hold;
use App\Models\LedgerEntry;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\StudentScheduleBinding;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EnrollmentGateEvaluator
{
    public function __construct(
        private readonly AcademicEnrollmentEffect $academicEnrollmentEffect,
        private readonly EnrollmentFinanceClearanceService $financeClearance,
        private readonly StudentUnitLoadService $unitLoad,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function calculate(Enrollment $enrollment, ?CarbonImmutable $checkedAt = null): array
    {
        $checkedAt ??= CarbonImmutable::now();
        $enrollment->loadMissing([
            'studentProfile.user',
            'studentProfile.applicantIntake',
            'term',
            'courseEnrollments.termOffering.curriculumEntry.courseSpecification.course',
        ]);

        $sourceGates = [
            $this->identityGate($enrollment, $checkedAt),
            $this->statusGate($enrollment, $checkedAt),
            $this->documentGate($enrollment, $checkedAt),
            $this->financeGate($enrollment, $checkedAt),
            $this->academicGate($enrollment, $checkedAt),
            $this->capacityGate($enrollment, $checkedAt),
            $this->placementGate($enrollment, $checkedAt),
            $this->conflictGate($enrollment, $checkedAt),
        ];

        return [
            ...$sourceGates,
            $this->finalApprovalGate($enrollment, $sourceGates, $checkedAt),
        ];
    }

    /**
     * @return list<EnrollmentGateResult>
     */
    public function persist(Enrollment $enrollment, ?CarbonImmutable $checkedAt = null): array
    {
        return DB::transaction(function () use ($enrollment, $checkedAt): array {
            $lockedEnrollment = Enrollment::query()
                ->with(['studentProfile.user', 'studentProfile.applicantIntake', 'term'])
                ->lockForUpdate()
                ->findOrFail($enrollment->id);
            $rows = $this->calculate($lockedEnrollment, $checkedAt);
            $results = [];

            foreach ($rows as $row) {
                $results[] = EnrollmentGateResult::query()->updateOrCreate(
                    [
                        'enrollment_id' => $lockedEnrollment->id,
                        'gate_type' => $row['gate_type'],
                        'sequence' => $row['sequence'],
                    ],
                    [
                        'result' => $row['result'],
                        'responsible_office' => $row['responsible_office'],
                        'blocker_code' => $row['blocker_code'],
                        'blocker_message' => $row['blocker_message'],
                        'source_type' => $row['source_type'],
                        'source_id' => $row['source_id'],
                        'checked_at' => $row['checked_at'],
                        'rule_version' => EnrollmentGateResult::RuleVersionTal87C,
                    ],
                );
            }

            $this->updateEnrollmentStatus($lockedEnrollment, $rows);

            return $results;
        }, attempts: 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function identityGate(Enrollment $enrollment, CarbonImmutable $checkedAt): array
    {
        $profile = $enrollment->studentProfile;

        if (! $profile instanceof StudentProfile) {
            return $this->failed(EnrollmentGateResult::GateIdentity, 1, EnrollmentGateResult::ResponsibleOfficeRegistrar, 'missing_student_profile', 'No student profile is attached to this enrollment.', null, $checkedAt);
        }

        if ($profile->merged_into_id !== null || $profile->archived_at !== null) {
            return $this->failed(EnrollmentGateResult::GateIdentity, 1, EnrollmentGateResult::ResponsibleOfficeRegistrar, 'archived_or_merged_profile', 'The student profile is archived or merged into another record.', $profile, $checkedAt);
        }

        if (blank($profile->student_number)) {
            return $this->failed(EnrollmentGateResult::GateIdentity, 1, EnrollmentGateResult::ResponsibleOfficeRegistrar, 'missing_student_number', 'A student number is required before enrollment gate clearance.', $profile, $checkedAt);
        }

        if (! $profile->user instanceof User) {
            return $this->failed(EnrollmentGateResult::GateIdentity, 1, EnrollmentGateResult::ResponsibleOfficeRegistrar, 'missing_user_account', 'A linked active user account is required before enrollment gate clearance.', $profile, $checkedAt);
        }

        if ($profile->user->status !== User::StatusActive) {
            return $this->failed(EnrollmentGateResult::GateIdentity, 1, EnrollmentGateResult::ResponsibleOfficeRegistrar, 'inactive_user_account', 'The linked student user account must be active before enrollment gate clearance.', $profile->user, $checkedAt);
        }

        $applicantIntake = $profile->applicantIntake;

        if ($applicantIntake instanceof ApplicantIntake && $applicantIntake->handed_over_at === null) {
            return $this->failed(EnrollmentGateResult::GateIdentity, 1, EnrollmentGateResult::ResponsibleOfficeRegistrar, 'applicant_handover_incomplete', 'Applicant intake handover must be completed before enrollment clearance.', $applicantIntake, $checkedAt);
        }

        return $this->passed(EnrollmentGateResult::GateIdentity, 1, EnrollmentGateResult::ResponsibleOfficeRegistrar, $profile, $checkedAt);
    }

    /**
     * @return array<string, mixed>
     */
    private function statusGate(Enrollment $enrollment, CarbonImmutable $checkedAt): array
    {
        $profile = $enrollment->studentProfile;

        if (! $profile instanceof StudentProfile) {
            return $this->failed(EnrollmentGateResult::GateAdmissionOrStudentStatus, 2, EnrollmentGateResult::ResponsibleOfficeRegistrar, 'missing_student_profile', 'No student profile is attached to this enrollment.', null, $checkedAt);
        }

        if ($profile->blocksEnrollmentByLifecycle()) {
            return $this->failed(EnrollmentGateResult::GateAdmissionOrStudentStatus, 2, EnrollmentGateResult::ResponsibleOfficeRegistrar, 'lifecycle_status', 'Student lifecycle status blocks enrollment: '.StudentProfile::lifecycleStatusLabel($profile->lifecycle_status).'.', $profile, $checkedAt);
        }

        $hold = $this->activeBlockingHold($enrollment, [Hold::TypeBehavioral, Hold::TypeDisciplinary, Hold::TypeEnrollment], $checkedAt);

        if ($hold instanceof Hold) {
            return $this->failed(EnrollmentGateResult::GateAdmissionOrStudentStatus, 2, EnrollmentGateResult::ResponsibleOfficeRegistrar, 'active_status_hold', $hold->studentFacingMessage() ?? 'An active Registrar status hold blocks enrollment.', $hold, $checkedAt);
        }

        return $this->passed(EnrollmentGateResult::GateAdmissionOrStudentStatus, 2, EnrollmentGateResult::ResponsibleOfficeRegistrar, $profile, $checkedAt);
    }

    /**
     * @return array<string, mixed>
     */
    private function documentGate(Enrollment $enrollment, CarbonImmutable $checkedAt): array
    {
        $profile = $enrollment->studentProfile;

        if (! $profile instanceof StudentProfile) {
            return $this->failed(EnrollmentGateResult::GateDocument, 3, EnrollmentGateResult::ResponsibleOfficeRegistrar, 'missing_student_profile', 'No student profile is attached to this enrollment.', null, $checkedAt);
        }

        $checklistItem = ChecklistItem::query()
            ->where('student_profile_id', $profile->id)
            ->where('owner_type', ChecklistItem::OwnerStudent)
            ->where('blocking_level', ChecklistItem::BlockingEnrollment)
            ->oldest('deadline')
            ->oldest('id')
            ->get()
            ->first(fn (ChecklistItem $item): bool => ! $item->isResolved());

        if ($checklistItem instanceof ChecklistItem) {
            return $this->failed(EnrollmentGateResult::GateDocument, 3, EnrollmentGateResult::ResponsibleOfficeRegistrar, 'blocking_document_unresolved', 'A blocking enrollment document remains unresolved: '.$checklistItem->requirement_type.'.', $checklistItem, $checkedAt);
        }

        $hold = $this->activeBlockingHold($enrollment, [Hold::TypeDocumentary], $checkedAt);

        if ($hold instanceof Hold) {
            return $this->failed(EnrollmentGateResult::GateDocument, 3, EnrollmentGateResult::ResponsibleOfficeRegistrar, 'active_document_hold', $hold->studentFacingMessage() ?? 'An active documentary hold blocks enrollment.', $hold, $checkedAt);
        }

        return $this->passed(EnrollmentGateResult::GateDocument, 3, EnrollmentGateResult::ResponsibleOfficeRegistrar, $profile, $checkedAt);
    }

    /**
     * @return array<string, mixed>
     */
    private function financeGate(Enrollment $enrollment, CarbonImmutable $checkedAt): array
    {
        $profile = $enrollment->studentProfile;

        if (! $profile instanceof StudentProfile) {
            return $this->failed(EnrollmentGateResult::GateFinance, 4, EnrollmentGateResult::ResponsibleOfficeAccounting, 'missing_student_profile', 'No student profile is attached to this enrollment.', null, $checkedAt);
        }

        $assessment = Assessment::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('state', Assessment::StateActive)
            ->latest('version')
            ->latest('id')
            ->first();

        if (! $assessment instanceof Assessment) {
            return $this->failed(EnrollmentGateResult::GateFinance, 4, EnrollmentGateResult::ResponsibleOfficeAccounting, 'missing_active_assessment', 'An active assessment is required before finance gate clearance.', $enrollment, $checkedAt);
        }

        $readiness = $this->financeClearance->readiness($enrollment, $profile, $this->currentBalance($enrollment), $checkedAt);

        if ($readiness['finance_cleared'] === true) {
            return $this->passed(EnrollmentGateResult::GateFinance, 4, EnrollmentGateResult::ResponsibleOfficeAccounting, $assessment, $checkedAt);
        }

        return $this->failed(EnrollmentGateResult::GateFinance, 4, EnrollmentGateResult::ResponsibleOfficeAccounting, 'finance_not_ready', $this->financeFailureMessage($readiness), $assessment, $checkedAt);
    }

    /**
     * @return array<string, mixed>
     */
    private function academicGate(Enrollment $enrollment, CarbonImmutable $checkedAt): array
    {
        $profile = $enrollment->studentProfile;

        if (! $profile instanceof StudentProfile) {
            return $this->failed(EnrollmentGateResult::GateAcademicProgression, 5, EnrollmentGateResult::ResponsibleOfficeAcademicHead, 'missing_student_profile', 'No student profile is attached to this enrollment.', null, $checkedAt);
        }

        $hold = $this->activeBlockingHold($enrollment, [Hold::TypeAcademicDeficit, Hold::TypePrerequisite], $checkedAt);

        if ($hold instanceof Hold) {
            return $this->failed(EnrollmentGateResult::GateAcademicProgression, 5, EnrollmentGateResult::ResponsibleOfficeAcademicHead, 'active_academic_hold', $hold->studentFacingMessage() ?? 'An active academic hold blocks enrollment.', $hold, $checkedAt);
        }

        $activeCourseEnrollments = $this->activeCourseEnrollments($enrollment);

        if ($activeCourseEnrollments->isEmpty()) {
            return $this->failed(EnrollmentGateResult::GateAcademicProgression, 5, EnrollmentGateResult::ResponsibleOfficeAcademicHead, 'no_selected_courses', 'At least one selected active course is required for academic gate clearance.', $enrollment, $checkedAt);
        }

        $effect = $this->academicEnrollmentEffect->forStudent($profile, $enrollment->term);

        if ($effect['effect'] === AcademicDecision::EffectBlocked) {
            return $this->failed(EnrollmentGateResult::GateAcademicProgression, 5, EnrollmentGateResult::ResponsibleOfficeAcademicHead, 'academic_decision_block', $effect['reason'], $enrollment, $checkedAt);
        }

        if ($effect['effect'] === AcademicDecision::EffectPendingDecision) {
            return $this->failed(EnrollmentGateResult::GateAcademicProgression, 5, EnrollmentGateResult::ResponsibleOfficeAcademicHead, 'academic_decision_pending', $effect['reason'], $enrollment, $checkedAt);
        }

        $load = $this->unitLoad->evaluate($enrollment, $this->activeUnitLoad($activeCourseEnrollments));

        if ($load['unit_load_passes'] !== true) {
            return $this->failed(EnrollmentGateResult::GateAcademicProgression, 5, EnrollmentGateResult::ResponsibleOfficeAcademicHead, 'unit_load_exception_required', 'Requested unit load exceeds the configured student cap without an active approved unit-load exception.', $enrollment, $checkedAt);
        }

        return $this->passed(EnrollmentGateResult::GateAcademicProgression, 5, EnrollmentGateResult::ResponsibleOfficeAcademicHead, $enrollment, $checkedAt);
    }

    /**
     * @return array<string, mixed>
     */
    private function capacityGate(Enrollment $enrollment, CarbonImmutable $checkedAt): array
    {
        foreach ($this->activeCourseEnrollments($enrollment) as $courseEnrollment) {
            $reservation = $this->activeReservation($courseEnrollment, $checkedAt);

            if (! $reservation instanceof EnrollmentSeatReservation || ! $reservation->section instanceof Section) {
                return $this->failed(EnrollmentGateResult::GateCapacity, 6, EnrollmentGateResult::ResponsibleOfficeRegistrar, 'missing_course_reservation', 'Every selected course must have an active Registrar-confirmed seat reservation.', $courseEnrollment, $checkedAt);
            }

            if ($this->remainingCapacity($reservation->section, $checkedAt) < 0) {
                return $this->failed(EnrollmentGateResult::GateCapacity, 6, EnrollmentGateResult::ResponsibleOfficeRegistrar, 'section_capacity_exceeded', 'The reserved section has exceeded its capacity.', $reservation->section, $checkedAt);
            }
        }

        return $this->passed(EnrollmentGateResult::GateCapacity, 6, EnrollmentGateResult::ResponsibleOfficeRegistrar, $enrollment, $checkedAt);
    }

    /**
     * @return array<string, mixed>
     */
    private function placementGate(Enrollment $enrollment, CarbonImmutable $checkedAt): array
    {
        foreach ($this->activeCourseEnrollments($enrollment) as $courseEnrollment) {
            $reservation = $this->activeReservation($courseEnrollment, $checkedAt);
            $section = $reservation?->section;

            if (! $reservation instanceof EnrollmentSeatReservation || ! $section instanceof Section) {
                return $this->failed(EnrollmentGateResult::GatePlacement, 7, EnrollmentGateResult::ResponsibleOfficeRegistrar, 'missing_course_placement', 'Every selected course must have a Registrar-confirmed section placement.', $courseEnrollment, $checkedAt);
            }

            if ((int) $section->term_offering_id !== (int) $courseEnrollment->term_offering_id) {
                return $this->failed(EnrollmentGateResult::GatePlacement, 7, EnrollmentGateResult::ResponsibleOfficeRegistrar, 'placement_offering_mismatch', 'The reserved section does not match the selected term offering.', $reservation, $checkedAt);
            }

            $publishedMeetingIds = $this->publishedMeetingIds($section);

            if ($publishedMeetingIds->isEmpty()) {
                return $this->failed(EnrollmentGateResult::GatePlacement, 7, EnrollmentGateResult::ResponsibleOfficeRegistrar, 'missing_published_meetings', 'The reserved section does not have published official meetings.', $section, $checkedAt);
            }

            $bindingMeetingIds = $courseEnrollment->scheduleBindings()
                ->where('is_active', true)
                ->pluck('section_meeting_id')
                ->map(fn (mixed $id): int => (int) $id);

            if ($publishedMeetingIds->diff($bindingMeetingIds)->isNotEmpty()) {
                return $this->failed(EnrollmentGateResult::GatePlacement, 7, EnrollmentGateResult::ResponsibleOfficeRegistrar, 'schedule_binding_incomplete', 'Published section meetings must be bound to the student schedule.', $courseEnrollment, $checkedAt);
            }
        }

        return $this->passed(EnrollmentGateResult::GatePlacement, 7, EnrollmentGateResult::ResponsibleOfficeRegistrar, $enrollment, $checkedAt);
    }

    /**
     * @return array<string, mixed>
     */
    private function conflictGate(Enrollment $enrollment, CarbonImmutable $checkedAt): array
    {
        $bindings = StudentScheduleBinding::query()
            ->with(['sectionMeeting', 'courseEnrollment'])
            ->where('is_active', true)
            ->whereHas('courseEnrollment', fn (Builder $query) => $query->where('enrollment_id', $enrollment->id))
            ->get();

        foreach ($bindings as $left) {
            foreach ($bindings as $right) {
                if ((int) $left->id >= (int) $right->id) {
                    continue;
                }

                if ($this->meetingsConflict($left->sectionMeeting, $right->sectionMeeting)) {
                    if ($this->activeConflictException($enrollment, [$left->courseEnrollment?->term_offering_id, $right->courseEnrollment?->term_offering_id], $checkedAt)) {
                        return $this->row(EnrollmentGateResult::GateConflict, 8, EnrollmentGateResult::ResultOverridden, EnrollmentGateResult::ResponsibleOfficeAcademicHead, 'conflict_exception_recorded', 'Schedule conflict cleared by a scoped approved conflict exception.', $enrollment, $checkedAt);
                    }

                    return $this->failed(EnrollmentGateResult::GateConflict, 8, EnrollmentGateResult::ResponsibleOfficeRegistrar, 'schedule_conflict', 'Two selected course meetings overlap in the student schedule.', $left->sectionMeeting, $checkedAt);
                }
            }
        }

        return $this->passed(EnrollmentGateResult::GateConflict, 8, EnrollmentGateResult::ResponsibleOfficeRegistrar, $enrollment, $checkedAt);
    }

    /**
     * @param  list<array<string, mixed>>  $sourceGates
     * @return array<string, mixed>
     */
    /**
     * @param  list<array<string, mixed>>  $sourceGates
     * @return array<string, mixed>
     */
    private function finalApprovalGate(Enrollment $enrollment, array $sourceGates, CarbonImmutable $checkedAt): array
    {
        if ($enrollment->status === 'officially_enrolled' && $enrollment->officially_enrolled_at !== null) {
            return $this->row(EnrollmentGateResult::GateFinalApproval, 9, EnrollmentGateResult::ResultPassed, EnrollmentGateResult::ResponsibleOfficeRegistrar, null, 'Registrar recorded official enrollment; final approval gate is cleared.', $enrollment, $checkedAt);
        }

        $sourceGatesClear = collect($sourceGates)
            ->every(fn (array $gate): bool => $this->isClearResult((string) $gate['result']));

        if ($sourceGatesClear) {
            return $this->row(EnrollmentGateResult::GateFinalApproval, 9, EnrollmentGateResult::ResultPendingReview, EnrollmentGateResult::ResponsibleOfficeRegistrar, 'final_approval_pending', 'Source-derived gates are clear; final Registrar approval remains pending for TAL-87D.', null, $checkedAt);
        }

        return $this->row(EnrollmentGateResult::GateFinalApproval, 9, EnrollmentGateResult::ResultNotApplicable, EnrollmentGateResult::ResponsibleOfficeRegistrar, 'source_gates_unresolved', 'Final approval is not applicable until source-derived gates clear.', null, $checkedAt);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function updateEnrollmentStatus(Enrollment $enrollment, array $rows): void
    {
        if (in_array($enrollment->status, ['officially_enrolled', 'cancelled', 'dropped', 'withdrawn'], true)) {
            return;
        }

        $sourceRows = collect($rows)->reject(fn (array $row): bool => $row['gate_type'] === EnrollmentGateResult::GateFinalApproval);
        $failedRows = $sourceRows->filter(fn (array $row): bool => $this->isUnresolved((string) $row['result']));
        $nextStatus = 'pending_review';
        $reason = 'Enrollment gates require staff review.';

        if ($failedRows->isEmpty()) {
            $nextStatus = 'ready_for_official_enrollment';
            $reason = 'Source-derived gates are clear; awaiting TAL-87D final Registrar approval.';
        } elseif ($failedRows->contains(fn (array $row): bool => in_array($row['gate_type'], [EnrollmentGateResult::GateCapacity, EnrollmentGateResult::GatePlacement], true))) {
            $nextStatus = 'capacity_pending';
            $reason = 'Capacity or placement gate remains unresolved.';
        } elseif ($failedRows->every(fn (array $row): bool => $row['gate_type'] === EnrollmentGateResult::GateFinance)) {
            $nextStatus = 'pending_payment';
            $reason = 'Finance readiness gate remains unresolved.';
        }

        $enrollment->forceFill([
            'status' => $nextStatus,
            'status_reason' => $reason,
        ])->save();
    }

    /**
     * @param  list<string>  $holdTypes
     */
    private function activeBlockingHold(Enrollment $enrollment, array $holdTypes, CarbonImmutable $checkedAt): ?Hold
    {
        $profile = $enrollment->studentProfile;

        if (! $profile instanceof StudentProfile) {
            return null;
        }

        return Hold::query()
            ->where('student_profile_id', $profile->id)
            ->where('status', Hold::StatusActive)
            ->where('blocking_level', Hold::BlockingEnrollment)
            ->whereIn('hold_type', $holdTypes)
            ->where(fn (Builder $query) => $query->whereNull('enrollment_id')->orWhere('enrollment_id', $enrollment->id))
            ->where(fn (Builder $query) => $query->whereNull('term_id')->orWhere('term_id', $enrollment->term_id))
            ->where(fn (Builder $query) => $query->whereNull('effective_at')->orWhere('effective_at', '<=', $checkedAt))
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $checkedAt))
            ->oldest('id')
            ->first();
    }

    /**
     * @return Collection<int, CourseEnrollment>
     */
    private function activeCourseEnrollments(Enrollment $enrollment): Collection
    {
        return CourseEnrollment::query()
            ->with(['termOffering.curriculumEntry.courseSpecification.course'])
            ->where('enrollment_id', $enrollment->id)
            ->where('status', CourseEnrollment::StatusActive)
            ->oldest('id')
            ->get()
            ->toBase();
    }

    private function activeReservation(CourseEnrollment $courseEnrollment, CarbonImmutable $checkedAt): ?EnrollmentSeatReservation
    {
        return EnrollmentSeatReservation::query()
            ->with('section')
            ->where('course_enrollment_id', $courseEnrollment->id)
            ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
            ->where(fn (Builder $query) => $query->whereNull('deadline')->orWhere('deadline', '>', $checkedAt))
            ->latest('reserved_at')
            ->latest('id')
            ->first();
    }

    private function remainingCapacity(Section $section, CarbonImmutable $checkedAt): int
    {
        $held = EnrollmentSeatReservation::query()
            ->where('section_id', $section->id)
            ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
            ->where(fn (Builder $query) => $query->whereNull('deadline')->orWhere('deadline', '>', $checkedAt))
            ->count();

        return (int) $section->capacity - $held;
    }

    /**
     * @return Collection<int, int>
     */
    private function publishedMeetingIds(Section $section): Collection
    {
        $groupIds = $section->deliveryGroups()->pluck('id');

        if ($groupIds->isEmpty()) {
            return collect();
        }

        return SectionMeeting::query()
            ->activeOfficial()
            ->whereHas('schedulingDemand', fn (Builder $query) => $query
                ->whereIn('section_delivery_group_id', $groupIds)
                ->where('term_offering_id', $section->term_offering_id))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);
    }

    /**
     * @param  list<int|null>  $termOfferingIds
     */
    private function activeConflictException(Enrollment $enrollment, array $termOfferingIds, CarbonImmutable $checkedAt): bool
    {
        $ids = collect($termOfferingIds)
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->values();

        return EnrollmentException::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('exception_type', EnrollmentException::TypeConflict)
            ->where('state', EnrollmentException::StateActive)
            ->whereIn('target_term_offering_id', $ids)
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $checkedAt))
            ->exists();
    }

    private function activeUnitLoad(Collection $activeCourseEnrollments): float
    {
        return $activeCourseEnrollments
            ->sum(fn (CourseEnrollment $courseEnrollment): float => (float) $courseEnrollment->units_snapshot);
    }

    private function currentBalance(Enrollment $enrollment): string
    {
        $charges = (float) LedgerEntry::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('state', 'posted')
            ->whereIn('direction', [
                LedgerEntry::DirectionCharge,
                LedgerEntry::DirectionPenalty,
            ])
            ->sum('amount');
        $credits = (float) LedgerEntry::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('state', 'posted')
            ->whereIn('direction', [
                LedgerEntry::DirectionDiscount,
                LedgerEntry::DirectionScholarship,
                LedgerEntry::DirectionWaiver,
                LedgerEntry::DirectionPayment,
            ])
            ->sum('amount');

        return number_format(max(0, $charges - $credits), 2, '.', '');
    }

    /**
     * @param  array<string, mixed>  $readiness
     */
    private function financeFailureMessage(array $readiness): string
    {
        return sprintf(
            'Finance gate requires posted ledger payment or active approved accommodation. Required: %s; posted payments: %s; current balance: %s.',
            $readiness['minimum_required_payment'],
            $readiness['total_confirmed_payments'],
            $readiness['current_balance'],
        );
    }

    private function meetingsConflict(?SectionMeeting $left, ?SectionMeeting $right): bool
    {
        if (! $left instanceof SectionMeeting || ! $right instanceof SectionMeeting) {
            return false;
        }

        return (int) $left->day_of_week === (int) $right->day_of_week
            && (string) $left->starts_at < (string) $right->ends_at
            && (string) $right->starts_at < (string) $left->ends_at;
    }

    private function isUnresolved(string $result): bool
    {
        return ! $this->isClearResult($result);
    }

    private function isClearResult(string $result): bool
    {
        return in_array($result, [
            EnrollmentGateResult::ResultPassed,
            EnrollmentGateResult::ResultWaived,
            EnrollmentGateResult::ResultOverridden,
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function passed(string $gateType, int $sequence, string $responsibleOffice, ?Model $source, CarbonImmutable $checkedAt): array
    {
        return $this->row($gateType, $sequence, EnrollmentGateResult::ResultPassed, $responsibleOffice, null, null, $source, $checkedAt);
    }

    /**
     * @return array<string, mixed>
     */
    private function failed(string $gateType, int $sequence, string $responsibleOffice, string $blockerCode, string $blockerMessage, ?Model $source, CarbonImmutable $checkedAt): array
    {
        return $this->row($gateType, $sequence, EnrollmentGateResult::ResultFailed, $responsibleOffice, $blockerCode, $blockerMessage, $source, $checkedAt);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        string $gateType,
        int $sequence,
        string $result,
        string $responsibleOffice,
        ?string $blockerCode,
        ?string $blockerMessage,
        ?Model $source,
        CarbonImmutable $checkedAt,
    ): array {
        return [
            'gate_type' => $gateType,
            'sequence' => $sequence,
            'result' => $result,
            'responsible_office' => $responsibleOffice,
            'blocker_code' => $blockerCode,
            'blocker_message' => $blockerMessage,
            'source_type' => $source !== null ? $source::class : null,
            'source_id' => $source?->getKey(),
            'checked_at' => $checkedAt,
        ];
    }
}
