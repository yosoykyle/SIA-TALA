<?php

namespace App\Actions\Enrollment;

use App\Actions\Scheduling\ScheduleReleaseNotificationService;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\EnrollmentGateResult;
use App\Models\EnrollmentSeatReservation;
use App\Models\StudentScheduleBinding;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class FinalizeOfficialEnrollment
{
    public function __construct(
        private readonly EnrollmentGateEvaluator $gateEvaluator,
        private readonly ScheduleReleaseNotificationService $releaseNotifications,
    ) {}

    /**
     * Record the Registrar-approved official-enrollment transition.
     *
     * Verifies all source-derived gates, records the Final Approval gate, converts the
     * capacity-holding seat reservations, keeps the official schedule bindings active,
     * and exposes the source records that COR (Module 9) and Student Hub (Module 12) read.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function execute(Enrollment $enrollment, User $actor, ?string $remark = null, ?CarbonImmutable $recordedAt = null): Enrollment
    {
        Gate::forUser($actor)->authorize('officiallyEnroll', $enrollment);

        $recordedAt ??= CarbonImmutable::now(config('app.timezone'));

        // PRD 7.3 step 12: recheck every enrollment gate before the official transition.
        $this->gateEvaluator->persist($enrollment, $recordedAt);

        $officialEnrollment = DB::transaction(function () use ($enrollment, $actor, $remark, $recordedAt): Enrollment {
            $lockedEnrollment = Enrollment::query()
                ->with(['studentProfile.user', 'term'])
                ->lockForUpdate()
                ->findOrFail($enrollment->id);

            // Idempotent: an already-official enrollment is returned untouched.
            if ($lockedEnrollment->status === 'officially_enrolled' && $lockedEnrollment->officially_enrolled_at !== null) {
                return $lockedEnrollment;
            }

            if (in_array($lockedEnrollment->status, ['cancelled', 'dropped', 'withdrawn'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'A cancelled, dropped, or withdrawn enrollment cannot be officially enrolled.',
                ]);
            }

            $this->assertSourceGatesClear($lockedEnrollment);
            $this->assertActiveScheduleBindings($lockedEnrollment);

            $convertedReservationIds = $this->convertSeatReservations($lockedEnrollment, $recordedAt);
            $this->recordFinalApprovalGate($lockedEnrollment, $actor, $recordedAt);

            $lockedEnrollment->forceFill([
                'status' => 'officially_enrolled',
                'officially_enrolled_at' => $recordedAt,
                'registered_at' => $lockedEnrollment->registered_at ?? $recordedAt,
                'status_reason' => 'Registrar recorded official enrollment; all enrollment gates cleared.',
            ])->save();

            $this->recordActivity($lockedEnrollment, $actor, $convertedReservationIds, $remark, $recordedAt);

            return $lockedEnrollment->refresh()->load([
                'studentProfile.user',
                'term',
                'courseEnrollments.scheduleBindings',
            ]);
        }, attempts: 3);

        $this->releaseNotifications->recordOfficialEnrollment($officialEnrollment);

        return $officialEnrollment;
    }

    /**
     * @throws ValidationException
     */
    private function assertSourceGatesClear(Enrollment $enrollment): void
    {
        $sourceGates = EnrollmentGateResult::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('gate_type', '!=', EnrollmentGateResult::GateFinalApproval)
            ->get();

        if ($sourceGates->isEmpty()) {
            throw ValidationException::withMessages([
                'gates' => 'Enrollment gates have not been evaluated yet. Refresh gate results before recording official enrollment.',
            ]);
        }

        $clearResults = [
            EnrollmentGateResult::ResultPassed,
            EnrollmentGateResult::ResultWaived,
            EnrollmentGateResult::ResultOverridden,
        ];

        $blocker = $sourceGates
            ->reject(fn (EnrollmentGateResult $gate): bool => in_array($gate->result, $clearResults, true))
            ->sortBy('sequence')
            ->first();

        if ($blocker instanceof EnrollmentGateResult) {
            throw ValidationException::withMessages([
                'gates' => sprintf(
                    'Official enrollment is blocked by the %s gate (%s office): %s',
                    str_replace('_', ' ', $blocker->gate_type),
                    str_replace('_', ' ', $blocker->responsible_office),
                    $blocker->blocker_message ?: 'This gate has not cleared.',
                ),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertActiveScheduleBindings(Enrollment $enrollment): void
    {
        $activeCourseEnrollments = CourseEnrollment::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('status', CourseEnrollment::StatusActive)
            ->get();

        if ($activeCourseEnrollments->isEmpty()) {
            throw ValidationException::withMessages([
                'schedule' => 'Official enrollment requires at least one active enrolled course.',
            ]);
        }

        $missingBinding = $activeCourseEnrollments->first(
            fn (CourseEnrollment $courseEnrollment): bool => StudentScheduleBinding::query()
                ->where('course_enrollment_id', $courseEnrollment->id)
                ->where('is_active', true)
                ->doesntExist(),
        );

        if ($missingBinding instanceof CourseEnrollment) {
            throw ValidationException::withMessages([
                'schedule' => 'Official enrollment requires an active published schedule binding for every enrolled course.',
            ]);
        }
    }

    /**
     * @return list<int>
     */
    private function convertSeatReservations(Enrollment $enrollment, CarbonImmutable $recordedAt): array
    {
        $reservations = EnrollmentSeatReservation::query()
            ->where('enrollment_id', $enrollment->id)
            ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
            ->lockForUpdate()
            ->get();

        $convertedIds = [];

        foreach ($reservations as $reservation) {
            $reservation->update([
                'status' => EnrollmentSeatReservation::StatusConverted,
                'converted_at' => $recordedAt,
                'lock_version' => ((int) $reservation->lock_version) + 1,
            ]);
            $convertedIds[] = (int) $reservation->id;
        }

        return $convertedIds;
    }

    private function recordFinalApprovalGate(Enrollment $enrollment, User $actor, CarbonImmutable $recordedAt): void
    {
        EnrollmentGateResult::query()->updateOrCreate(
            [
                'enrollment_id' => $enrollment->id,
                'gate_type' => EnrollmentGateResult::GateFinalApproval,
                'sequence' => 9,
            ],
            [
                'result' => EnrollmentGateResult::ResultPassed,
                'responsible_office' => EnrollmentGateResult::ResponsibleOfficeRegistrar,
                'blocker_code' => null,
                'blocker_message' => sprintf('Official enrollment recorded by user #%d.', $actor->id),
                'source_type' => Enrollment::class,
                'source_id' => $enrollment->id,
                'checked_at' => $recordedAt,
                'rule_version' => EnrollmentGateResult::RuleVersionTal87D,
            ],
        );
    }

    /**
     * @param  list<int>  $convertedReservationIds
     */
    private function recordActivity(
        Enrollment $enrollment,
        User $actor,
        array $convertedReservationIds,
        ?string $remark,
        CarbonImmutable $recordedAt,
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'student_enrollment',
            'description' => 'Registrar recorded official enrollment.',
            'subject_type' => Enrollment::class,
            'subject_id' => $enrollment->id,
            'event' => 'official_enrollment_recorded',
            'causer_type' => User::class,
            'causer_id' => $actor->id,
            'properties' => json_encode([
                'student_profile_id' => $enrollment->student_profile_id,
                'term_id' => $enrollment->term_id,
                'officially_enrolled_at' => $recordedAt->toDateTimeString(),
                'converted_reservation_ids' => $convertedReservationIds,
                'remark' => $remark,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $recordedAt->toDateTimeString(),
            'updated_at' => $recordedAt->toDateTimeString(),
        ]);
    }
}
