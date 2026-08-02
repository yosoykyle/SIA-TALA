<?php

namespace App\Actions\Enrollment;

use App\Actions\Calendar\CalendarPhaseGateService;
use App\Actions\Calendar\Exceptions\CalendarGateViolation;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\EnrollmentGateResult;
use App\Models\EnrollmentSeatReservation;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\StudentScheduleBinding;
use App\Models\TermOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EnrollmentPlacementService
{
    /**
     * @var list<string>
     */
    private const TerminalStatuses = [
        'officially_enrolled',
        'cancelled',
        'dropped',
        'withdrawn',
    ];

    public function __construct(
        private readonly CalendarPhaseGateService $calendarPhaseGate,
        private readonly EnrollmentGateEvaluator $gateEvaluator,
        private readonly SubjectSuggestionService $subjectSuggestions,
        private readonly StudentUnitLoadService $unitLoad,
    ) {}

    /**
     * @return array<int, string>
     */
    public function placementOptions(Enrollment $enrollment): array
    {
        return $this->placementSummaries($enrollment)
            ->mapWithKeys(fn (array $summary): array => [
                $summary['section_id'] => $summary['label'],
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function replacementOptions(Enrollment $enrollment): array
    {
        $activeReservations = EnrollmentSeatReservation::query()
            ->with('courseEnrollment')
            ->where('enrollment_id', $enrollment->id)
            ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
            ->get();
        $replaceableOfferingIds = $activeReservations
            ->pluck('courseEnrollment.term_offering_id')
            ->filter()
            ->map(fn (mixed $offeringId): int => (int) $offeringId)
            ->unique();
        $currentSectionIds = $activeReservations
            ->pluck('section_id')
            ->map(fn (mixed $sectionId): int => (int) $sectionId);

        return $this->placementSummaries($enrollment)
            ->filter(fn (array $summary): bool => $replaceableOfferingIds->contains($summary['term_offering_id'])
                && ! $currentSectionIds->contains($summary['section_id']))
            ->mapWithKeys(fn (array $summary): array => [
                $summary['section_id'] => $summary['label'],
            ])
            ->all();
    }

    /**
     * @return Collection<int, array{section_id:int,term_offering_id:int<0, max>,label:string,remaining:int<0, max>,capacity:int<0, max>,reserved:int,official:int,schedule:string}>
     */
    public function placementSummaries(Enrollment $enrollment): Collection
    {
        $eligibleOfferingIds = $this->eligibleOfferingIds($enrollment);

        return Section::query()
            ->with([
                'termOffering.curriculumEntry.courseSpecification.course',
                'deliveryGroups.schedulingDemands.sectionMeetings' => fn ($query) => $query
                    ->where('state', SectionMeeting::StateActive)
                    ->whereHas('scheduleRun', fn (Builder $query) => $query->where('status', ScheduleGenerationRun::StatusPublished))
                    ->orderBy('day_of_week')
                    ->orderBy('starts_at'),
            ])
            ->whereHas('termOffering', fn (Builder $query) => $query->where('term_id', $enrollment->term_id))
            ->whereIn('term_offering_id', $eligibleOfferingIds)
            ->where('state', Section::StateOpen)
            ->whereHas('deliveryGroups.schedulingDemands.sectionMeetings', fn (Builder $query) => $query
                ->where('state', SectionMeeting::StateActive)
                ->whereHas('scheduleRun', fn (Builder $query) => $query->where('status', ScheduleGenerationRun::StatusPublished)))
            ->orderBy('code')
            ->get()
            ->map(function (Section $section): array {
                $capacity = (int) $section->capacity;
                $reserved = $this->activeReservationCount($section);
                $official = $this->activeBindingSeatCount($section);
                $remaining = max(0, $capacity - $reserved - $official);
                $offering = $section->termOffering;
                $course = $offering?->curriculumEntry?->courseSpecification?->course;
                $title = $offering?->curriculumEntry?->courseSpecification?->title;
                $schedule = $this->officialMeetingsForSection($section)
                    ->map(fn (SectionMeeting $meeting): string => $this->meetingLabel($meeting))
                    ->implode('; ');

                return [
                    'section_id' => (int) $section->id,
                    'term_offering_id' => (int) $section->term_offering_id,
                    'label' => collect([
                        $course?->code,
                        $title,
                        $section->code,
                        "Remaining {$remaining}/{$capacity}",
                        $schedule,
                    ])->filter()->implode(' - '),
                    'remaining' => $remaining,
                    'capacity' => $capacity,
                    'reserved' => $reserved,
                    'official' => $official,
                    'schedule' => $schedule,
                ];
            });
    }

    /**
     * @return array{remaining:int<0, max>,capacity:int<0, max>,reserved:int,official:int,schedule:string}
     */
    public function sectionOperationalSummary(Section $section): array
    {
        $capacity = (int) $section->capacity;
        $reserved = $this->activeReservationCount($section);
        $official = $this->activeBindingSeatCount($section);

        return [
            'remaining' => max(0, $capacity - $reserved - $official),
            'capacity' => $capacity,
            'reserved' => $reserved,
            'official' => $official,
            'schedule' => $this->officialMeetingsForSection($section)
                ->map(fn (SectionMeeting $meeting): string => $this->meetingLabel($meeting))
                ->implode('; '),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function regularCohortOptions(Enrollment $enrollment): array
    {
        $eligibleOfferingIds = $this->eligibleOfferingIds($enrollment)
            ->sort()
            ->values()
            ->all();
        $cohortOfferingIds = [];

        if ($eligibleOfferingIds === []) {
            return [];
        }

        $eligibleOfferings = TermOffering::query()
            ->whereKey($eligibleOfferingIds)
            ->with('curriculumEntry:id,year_level')
            ->get();
        $targetYearLevel = $eligibleOfferings
            ->pluck('curriculumEntry.year_level')
            ->filter(fn (mixed $yearLevel): bool => filled($yearLevel))
            ->sort()
            ->first();
        $targetOfferingIds = $eligibleOfferings
            ->filter(fn (TermOffering $offering): bool => $targetYearLevel === null
                || $offering->curriculumEntry?->year_level === $targetYearLevel)
            ->modelKeys();
        sort($targetOfferingIds);

        $sectionIds = $this->placementSummaries($enrollment)
            ->whereIn('term_offering_id', $targetOfferingIds)
            ->pluck('section_id');

        $sections = Section::query()
            ->whereIn('id', $sectionIds)
            ->with('deliveryGroups')
            ->get();

        foreach ($sections as $section) {
            foreach ($section->deliveryGroups as $deliveryGroup) {
                $code = trim((string) $deliveryGroup->name);

                if ($code === '') {
                    continue;
                }

                $cohortOfferingIds[$code][(int) $section->term_offering_id] = true;
            }
        }

        ksort($cohortOfferingIds);
        $options = [];

        foreach ($cohortOfferingIds as $code => $offeringIds) {
            $publishedOfferingIds = array_map('intval', array_keys($offeringIds));
            sort($publishedOfferingIds);

            if ($publishedOfferingIds !== $targetOfferingIds) {
                continue;
            }

            $count = count($publishedOfferingIds);
            $options[$code] = "{$code} ({$count} published subjects)";
        }

        return $options;
    }

    public function recommendedRegularCohortCode(Enrollment $enrollment): ?string
    {
        $code = array_key_first($this->regularCohortOptions($enrollment));

        return is_string($code) ? $code : null;
    }

    public function placementIsMutable(Enrollment $enrollment): bool
    {
        return ! in_array($enrollment->status, self::TerminalStatuses, true);
    }

    public function sectionSelectionBlocker(Enrollment $enrollment, Section $section): ?string
    {
        $summary = $this->sectionOperationalSummary($section);

        if ($summary['remaining'] < 1) {
            return 'No remaining capacity';
        }

        $targetMeetings = $this->officialMeetingsForSection($section);
        $activeMeetings = StudentScheduleBinding::query()
            ->where('is_active', true)
            ->whereHas('courseEnrollment.enrollment', fn (Builder $query) => $query
                ->where('student_profile_id', $enrollment->student_profile_id)
                ->where('term_id', $enrollment->term_id))
            ->whereHas('courseEnrollment', fn (Builder $query) => $query
                ->where('term_offering_id', '!=', $section->term_offering_id))
            ->with('sectionMeeting')
            ->get()
            ->pluck('sectionMeeting')
            ->filter(fn (mixed $meeting): bool => $meeting instanceof SectionMeeting);

        if ($this->meetingsOverlap($targetMeetings, $activeMeetings)) {
            return 'Conflicts with your confirmed schedule';
        }

        return null;
    }

    /**
     * @return array{course_enrollment:CourseEnrollment,reservation:EnrollmentSeatReservation,bindings:int,already_confirmed:bool}
     *
     * @throws AuthorizationException
     * @throws CalendarGateViolation
     * @throws ValidationException
     */
    public function confirm(Enrollment $enrollment, int $sectionId, User $actor): array
    {
        Gate::forUser($actor)->authorize('confirmPlacement', $enrollment);

        $enrollment->loadMissing('studentProfile');
        $studentProfile = $enrollment->studentProfile;

        if (! $studentProfile instanceof StudentProfile || $studentProfile->blocksEnrollmentByLifecycle()) {
            $message = $this->lifecycleBlockerMessage($studentProfile);

            EnrollmentGateResult::query()->updateOrCreate(
                [
                    'enrollment_id' => $enrollment->id,
                    'gate_type' => EnrollmentGateResult::GatePlacement,
                    'sequence' => 1,
                ],
                [
                    'result' => EnrollmentGateResult::ResultFailed,
                    'responsible_office' => EnrollmentGateResult::ResponsibleOfficeRegistrar,
                    'blocker_code' => 'lifecycle_status',
                    'blocker_message' => $message,
                    'source_type' => StudentProfile::class,
                    'source_id' => $studentProfile?->id,
                    'checked_at' => now(),
                    'rule_version' => EnrollmentGateResult::RuleVersionTal67Mvp,
                ],
            );

            $this->reject('student_profile_id', $message);
        }

        $summary = DB::transaction(function () use ($enrollment, $sectionId, $actor): array {
            $lockedEnrollment = Enrollment::query()
                ->with('studentProfile')
                ->whereKey($enrollment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPlacementMutable($lockedEnrollment);
            $studentProfile = $lockedEnrollment->studentProfile;

            if (! $studentProfile instanceof StudentProfile || $studentProfile->blocksEnrollmentByLifecycle()) {
                $this->reject('student_profile_id', $this->lifecycleBlockerMessage($studentProfile));
            }

            $section = Section::query()
                ->with('termOffering.curriculumEntry.courseSpecification')
                ->whereKey($sectionId)
                ->lockForUpdate()
                ->first();

            if (! $section instanceof Section) {
                $this->reject('section_id', 'Select a valid published section placement.');
            }

            if ((int) $section->termOffering?->term_id !== (int) $lockedEnrollment->term_id) {
                $this->reject('section_id', 'Selected section does not belong to the enrollment term.');
            }

            $meetings = $this->lockOfficialMeetingsForSection($section);

            if ($meetings->isEmpty()) {
                $this->reject('section_id', 'Selected section has no active published schedule meetings.');
            }

            $courseEnrollment = CourseEnrollment::query()
                ->where('enrollment_id', $lockedEnrollment->id)
                ->where('term_offering_id', $section->term_offering_id)
                ->lockForUpdate()
                ->first();

            if (! $courseEnrollment instanceof CourseEnrollment) {
                $courseEnrollment = CourseEnrollment::query()->create([
                    'enrollment_id' => $lockedEnrollment->id,
                    'term_offering_id' => $section->term_offering_id,
                    'status' => CourseEnrollment::StatusActive,
                    'units_snapshot' => $this->unitsSnapshot($section->termOffering),
                    'added_at' => now(),
                ]);
            } elseif ($courseEnrollment->status !== CourseEnrollment::StatusActive) {
                $courseEnrollment->update([
                    'status' => CourseEnrollment::StatusActive,
                    'status_reason' => null,
                    'dropped_at' => null,
                    'withdrawn_at' => null,
                ]);
            }

            $existingReservations = EnrollmentSeatReservation::query()
                ->where('enrollment_id', $lockedEnrollment->id)
                ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
                ->lockForUpdate()
                ->get();

            $samePlacement = $existingReservations->first(
                fn (EnrollmentSeatReservation $reservation): bool => (int) $reservation->course_enrollment_id === (int) $courseEnrollment->id
                    && (int) $reservation->section_id === (int) $section->id,
            );

            foreach ($existingReservations as $reservation) {
                if ((int) $reservation->course_enrollment_id === (int) $courseEnrollment->id
                    && (int) $reservation->section_id !== (int) $section->id) {
                    $this->releaseReservation($reservation, $actor, 'Replaced by Registrar-confirmed TAL-67 placement.');
                }
            }

            $this->deactivateOtherBindingsForCourse($courseEnrollment, $meetings->pluck('id')->map(fn (int|string $id): int => (int) $id)->all(), $actor);
            $this->assertCapacity($section, $courseEnrollment, $samePlacement);
            $this->assertNoStudentConflict($lockedEnrollment, $courseEnrollment, $meetings);

            $reservation = $samePlacement instanceof EnrollmentSeatReservation
                ? $samePlacement
                : EnrollmentSeatReservation::query()->create([
                    'enrollment_id' => $lockedEnrollment->id,
                    'course_enrollment_id' => $courseEnrollment->id,
                    'section_id' => $section->id,
                    'status' => EnrollmentSeatReservation::StatusPending,
                    'reserved_at' => now(),
                    'registrar_user_id' => $actor->id,
                    'lock_version' => 1,
                ]);

            $bindings = 0;

            foreach ($meetings as $meeting) {
                $binding = StudentScheduleBinding::query()->updateOrCreate(
                    [
                        'course_enrollment_id' => $courseEnrollment->id,
                        'section_meeting_id' => $meeting->id,
                    ],
                    [
                        'is_active' => true,
                        'effective_from' => now()->toDateString(),
                        'effective_until' => null,
                        'source' => StudentScheduleBinding::SourceRegistrarPlacement,
                        'released_by' => null,
                        'released_at' => null,
                        'release_reason' => null,
                    ],
                );

                if ($binding->wasRecentlyCreated || $binding->wasChanged()) {
                    $bindings++;
                }
            }

            $lockedEnrollment->update([
                'registered_at' => $lockedEnrollment->registered_at ?? now(),
            ]);

            return [
                'course_enrollment' => $courseEnrollment->refresh(),
                'reservation' => $reservation->refresh(),
                'bindings' => $bindings,
                'already_confirmed' => $samePlacement instanceof EnrollmentSeatReservation && $bindings === 0,
            ];
        }, attempts: 3);

        $this->gateEvaluator->persist($enrollment->refresh());

        return $summary;
    }

    /**
     * Replace one confirmed irregular placement during the canonical enrollment window.
     *
     * @return array{course_enrollment:CourseEnrollment,reservation:EnrollmentSeatReservation,bindings:int,already_confirmed:bool}
     *
     * @throws AuthorizationException
     * @throws CalendarGateViolation
     * @throws ValidationException
     */
    public function replace(Enrollment $enrollment, int $sectionId, User $actor): array
    {
        Gate::forUser($actor)->authorize('confirmPlacement', $enrollment);
        $deadline = $this->calendarPhaseGate->enrollmentDeadline($enrollment->term_id);

        return DB::transaction(function () use ($enrollment, $sectionId, $actor, $deadline): array {
            $lockedEnrollment = Enrollment::query()
                ->whereKey($enrollment->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertPlacementMutable($lockedEnrollment);

            if ($lockedEnrollment->student_type !== 'irregular') {
                $this->reject('student_type', 'Section replacement is available only for irregular enrollments.');
            }

            $section = Section::query()
                ->whereKey($sectionId)
                ->lockForUpdate()
                ->first();

            if (! $section instanceof Section) {
                $this->reject('section_id', 'Select a valid replacement section.');
            }

            $hasSameSubjectReservation = EnrollmentSeatReservation::query()
                ->where('enrollment_id', $lockedEnrollment->id)
                ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
                ->whereHas('courseEnrollment', fn (Builder $query) => $query
                    ->where('term_offering_id', $section->term_offering_id))
                ->lockForUpdate()
                ->exists();

            if (! $hasSameSubjectReservation) {
                $this->reject(
                    'section_id',
                    'Select an alternative section for a subject that already has a Registrar-confirmed placement.',
                );
            }

            $summary = $this->confirm($enrollment, $sectionId, $actor);
            $summary['reservation']->update(['deadline' => $deadline]);

            $this->gateEvaluator->persist($enrollment->refresh());

            return $summary;
        }, attempts: 3);
    }

    /**
     * Confirm every Student-proposed irregular course placement as one transaction.
     *
     * @return array{courses:int,bindings:int,already_confirmed:bool}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function confirmComplete(Enrollment $enrollment, User $actor): array
    {
        Gate::forUser($actor)->authorize('confirmPlacement', $enrollment);

        return DB::transaction(function () use ($enrollment, $actor): array {
            $lockedEnrollment = Enrollment::query()
                ->whereKey($enrollment->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertPlacementMutable($lockedEnrollment);

            if ($lockedEnrollment->student_type !== 'irregular') {
                $this->reject('student_type', 'Student-proposed section confirmation is available only for irregular enrollments.');
            }

            $sectionIds = CourseEnrollment::query()
                ->where('enrollment_id', $lockedEnrollment->id)
                ->where('status', CourseEnrollment::StatusActive)
                ->whereNotNull('proposed_section_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('proposed_section_id')
                ->map(fn (mixed $sectionId): int => (int) $sectionId)
                ->all();

            if ($sectionIds === []) {
                $this->reject('section_ids', 'No Student-proposed sections are ready for confirmation.');
            }

            return $this->confirmSectionIds($lockedEnrollment, $sectionIds, $actor);
        }, attempts: 3);
    }

    /**
     * Confirm every published subject section for one regular logical cohort.
     *
     * @return array{courses:int,bindings:int,already_confirmed:bool}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function confirmRegularCohort(Enrollment $enrollment, string $cohortCode, User $actor): array
    {
        Gate::forUser($actor)->authorize('confirmPlacement', $enrollment);
        $enrollment->loadMissing('studentProfile');
        $this->assertPlacementMutable($enrollment);

        if ($enrollment->student_type === 'irregular') {
            $this->reject('cohort_code', 'Logical cohort placement is available only for cohort-based enrollments.');
        }

        $curriculumVersionId = $enrollment->studentProfile?->curriculum_version_id;
        $cohortCode = trim($cohortCode);
        $eligibleOfferingIds = $this->eligibleOfferingIds($enrollment);

        if (! array_key_exists($cohortCode, $this->regularCohortOptions($enrollment))) {
            $this->reject('cohort_code', 'Select a compatible logical cohort with a complete published schedule.');
        }

        $sectionIds = Section::query()
            ->where('state', Section::StateOpen)
            ->whereIn('term_offering_id', $eligibleOfferingIds)
            ->whereHas('termOffering', fn (Builder $query) => $query
                ->where('term_id', $enrollment->term_id)
                ->where('state', TermOffering::StateScheduled)
                ->whereHas('curriculumEntry', fn (Builder $query) => $query
                    ->where('curriculum_version_id', $curriculumVersionId)))
            ->whereHas('deliveryGroups', fn (Builder $query) => $query
                ->where('name', $cohortCode))
            ->whereHas('deliveryGroups.schedulingDemands.sectionMeetings', fn (Builder $query) => $query
                ->where('state', SectionMeeting::StateActive)
                ->whereHas('scheduleRun', fn (Builder $query) => $query
                    ->where('status', ScheduleGenerationRun::StatusPublished)))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $sectionId): int => (int) $sectionId)
            ->all();

        if ($sectionIds === []) {
            $this->reject('cohort_code', 'Select a compatible logical cohort with a complete published schedule.');
        }

        return $this->confirmSectionIds($enrollment, $sectionIds, $actor);
    }

    /**
     * @param  list<int>  $sectionIds
     * @return array{courses:int,bindings:int,already_confirmed:bool}
     */
    private function confirmSectionIds(Enrollment $enrollment, array $sectionIds, User $actor): array
    {
        $deadline = $this->calendarPhaseGate->enrollmentDeadline($enrollment->term_id);

        $requestedUnits = Section::query()
            ->with('termOffering.curriculumEntry.courseSpecification')
            ->whereIn('id', $sectionIds)
            ->get()
            ->sum(fn (Section $section): float => (float) $this->unitsSnapshot($section->termOffering));
        $load = $this->unitLoad->evaluate($enrollment, $requestedUnits);

        if ($load['unit_load_passes'] !== true) {
            $this->reject(
                'unit_load',
                "The selected {$load['requested_total']}-unit load exceeds the allowed {$load['approved_limit']} units without an active approved exception.",
            );
        }

        return DB::transaction(function () use ($enrollment, $actor, $deadline, $sectionIds): array {
            $bindings = 0;
            $alreadyConfirmed = true;

            foreach ($sectionIds as $sectionId) {
                $summary = $this->confirm($enrollment, $sectionId, $actor);
                $summary['reservation']->update(['deadline' => $deadline]);
                $summary['course_enrollment']->update([
                    'proposed_section_id' => null,
                    'proposed_at' => null,
                    'status_reason' => 'Registrar-confirmed section placement.',
                ]);
                $bindings += $summary['bindings'];
                $alreadyConfirmed = $alreadyConfirmed && $summary['already_confirmed'];
            }

            $this->gateEvaluator->persist($enrollment->refresh());

            return [
                'courses' => count($sectionIds),
                'bindings' => $bindings,
                'already_confirmed' => $alreadyConfirmed,
            ];
        }, attempts: 3);
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function cancel(Enrollment $enrollment, User $actor, string $reason): Enrollment
    {
        Gate::forUser($actor)->authorize('confirmPlacement', $enrollment);
        $reason = trim($reason);

        if ($reason === '') {
            $this->reject('reason', 'A cancellation reason is required.');
        }

        return DB::transaction(function () use ($enrollment, $actor, $reason): Enrollment {
            $lockedEnrollment = Enrollment::query()
                ->whereKey($enrollment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($lockedEnrollment->status, self::TerminalStatuses, true)) {
                $this->reject('status', 'This enrollment can no longer be cancelled from placement review.');
            }

            $lockedEnrollment->seatReservations()
                ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
                ->lockForUpdate()
                ->get()
                ->each(fn (EnrollmentSeatReservation $reservation) => $this->releaseReservation(
                    $reservation,
                    $actor,
                    $reason,
                ));

            $lockedEnrollment->courseEnrollments()
                ->whereNotNull('proposed_section_id')
                ->update([
                    'proposed_section_id' => null,
                    'proposed_at' => null,
                    'status_reason' => 'Enrollment request cancelled before official enrollment.',
                ]);

            $lockedEnrollment->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'status_reason' => $reason,
            ]);

            return $lockedEnrollment->refresh();
        }, attempts: 3);
    }

    public function releaseExpired(?CarbonImmutable $evaluatedAt = null): int
    {
        $evaluatedAt ??= CarbonImmutable::now();
        $reservationIds = EnrollmentSeatReservation::query()
            ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
            ->whereNotNull('deadline')
            ->where('deadline', '<=', $evaluatedAt)
            ->pluck('id');
        $released = 0;
        $enrollmentIds = collect();

        foreach ($reservationIds as $reservationId) {
            DB::transaction(function () use ($reservationId, $evaluatedAt, &$released, $enrollmentIds): void {
                $reservation = EnrollmentSeatReservation::query()
                    ->whereKey($reservationId)
                    ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
                    ->whereNotNull('deadline')
                    ->where('deadline', '<=', $evaluatedAt)
                    ->lockForUpdate()
                    ->first();

                if (! $reservation instanceof EnrollmentSeatReservation) {
                    return;
                }

                $this->releaseReservation(
                    $reservation,
                    null,
                    'Released automatically because the enrollment reservation deadline expired.',
                );
                $enrollmentIds->push((int) $reservation->enrollment_id);
                $released++;
            }, attempts: 3);
        }

        $enrollmentIds->unique()->each(function (int $enrollmentId) use ($evaluatedAt): void {
            $enrollment = Enrollment::query()->find($enrollmentId);

            if ($enrollment instanceof Enrollment) {
                $this->gateEvaluator->persist($enrollment, $evaluatedAt);
            }
        });

        return $released;
    }

    /**
     * @return Collection<int, SectionMeeting>
     */
    private function officialMeetingsForSection(Section $section): Collection
    {
        return SectionMeeting::query()
            ->activeOfficial()
            ->whereHas('schedulingDemand', function (Builder $query) use ($section): void {
                $query
                    ->where('term_offering_id', $section->term_offering_id)
                    ->whereHas('sectionDeliveryGroup', fn (Builder $query) => $query->where('section_id', $section->id));
            })
            ->with(['faculty', 'room'])
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @return Collection<int, SectionMeeting>
     */
    private function lockOfficialMeetingsForSection(Section $section): Collection
    {
        return SectionMeeting::query()
            ->where('state', SectionMeeting::StateActive)
            ->whereHas('scheduleRun', fn (Builder $query) => $query->where('status', ScheduleGenerationRun::StatusPublished))
            ->whereHas('schedulingDemand', function (Builder $query) use ($section): void {
                $query
                    ->where('term_offering_id', $section->term_offering_id)
                    ->whereHas('sectionDeliveryGroup', fn (Builder $query) => $query->where('section_id', $section->id));
            })
            ->lockForUpdate()
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->get();
    }

    private function assertCapacity(
        Section $section,
        CourseEnrollment $courseEnrollment,
        ?EnrollmentSeatReservation $samePlacement,
    ): void {
        $reserved = EnrollmentSeatReservation::query()
            ->where('section_id', $section->id)
            ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
            ->when($samePlacement instanceof EnrollmentSeatReservation, fn (Builder $query) => $query->whereKeyNot($samePlacement->id))
            ->lockForUpdate()
            ->count();

        $official = $this->activeBindingSeatCount($section, $courseEnrollment);
        $used = $reserved + $official;

        if ($used >= (int) $section->capacity) {
            $this->reject('capacity', 'Selected section has no remaining capacity.');
        }
    }

    private function activeReservationCount(Section $section): int
    {
        return EnrollmentSeatReservation::query()
            ->where('section_id', $section->id)
            ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
            ->count();
    }

    /**
     * @return Collection<int, int>
     */
    private function eligibleOfferingIds(Enrollment $enrollment): Collection
    {
        $suggestions = $this->subjectSuggestions->suggestForEnrollment($enrollment);

        return collect([
            ...($suggestions['suggested'] ?? []),
            ...($suggestions['back_subjects'] ?? []),
        ])
            ->pluck('term_offering_id')
            ->merge($enrollment->courseEnrollments()
                ->where('status', CourseEnrollment::StatusActive)
                ->pluck('term_offering_id'))
            ->filter(fn (mixed $offeringId): bool => is_numeric($offeringId))
            ->map(fn (mixed $offeringId): int => (int) $offeringId)
            ->unique()
            ->values();
    }

    private function activeBindingSeatCount(Section $section, ?CourseEnrollment $excludedCourseEnrollment = null): int
    {
        return StudentScheduleBinding::query()
            ->where('is_active', true)
            ->whereHas('sectionMeeting.schedulingDemand', function (Builder $query) use ($section): void {
                $query
                    ->where('term_offering_id', $section->term_offering_id)
                    ->whereHas('sectionDeliveryGroup', fn (Builder $query) => $query->where('section_id', $section->id));
            })
            ->whereHas('courseEnrollment', fn (Builder $query) => $query->where('term_offering_id', $section->term_offering_id))
            ->whereDoesntHave('courseEnrollment.seatReservations', function (Builder $query) use ($section): void {
                $query
                    ->where('section_id', $section->id)
                    ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses());
            })
            ->when($excludedCourseEnrollment instanceof CourseEnrollment, fn (Builder $query) => $query->where('course_enrollment_id', '!=', $excludedCourseEnrollment->id))
            ->distinct()
            ->count('course_enrollment_id');
    }

    /**
     * @param  Collection<int, SectionMeeting>  $meetings
     */
    private function assertNoStudentConflict(Enrollment $enrollment, CourseEnrollment $courseEnrollment, Collection $meetings): void
    {
        $existingBindings = StudentScheduleBinding::query()
            ->where('is_active', true)
            ->where('course_enrollment_id', '!=', $courseEnrollment->id)
            ->whereHas('courseEnrollment.enrollment', function (Builder $query) use ($enrollment): void {
                $query
                    ->where('student_profile_id', $enrollment->student_profile_id)
                    ->where('term_id', $enrollment->term_id);
            })
            ->with('sectionMeeting')
            ->lockForUpdate()
            ->get();

        foreach ($meetings as $newMeeting) {
            foreach ($existingBindings as $binding) {
                $existingMeeting = $binding->sectionMeeting;

                if (! $existingMeeting instanceof SectionMeeting) {
                    continue;
                }

                if ((int) $existingMeeting->day_of_week !== (int) $newMeeting->day_of_week) {
                    continue;
                }

                if ((string) $existingMeeting->starts_at < (string) $newMeeting->ends_at
                    && (string) $existingMeeting->ends_at > (string) $newMeeting->starts_at) {
                    $this->reject('conflict', 'Selected section overlaps an active schedule binding for this student.');
                }
            }
        }
    }

    /**
     * @param  list<int>  $meetingIdsToKeep
     */
    private function deactivateOtherBindingsForCourse(CourseEnrollment $courseEnrollment, array $meetingIdsToKeep, User $actor): void
    {
        StudentScheduleBinding::query()
            ->where('course_enrollment_id', $courseEnrollment->id)
            ->where('is_active', true)
            ->whereNotIn('section_meeting_id', $meetingIdsToKeep)
            ->lockForUpdate()
            ->get()
            ->each(function (StudentScheduleBinding $binding) use ($actor): void {
                $binding->update([
                    'is_active' => false,
                    'effective_until' => now()->toDateString(),
                    'released_by' => $actor->id,
                    'released_at' => now(),
                    'release_reason' => 'Released by replacement Registrar placement confirmation.',
                ]);
            });
    }

    private function releaseReservation(EnrollmentSeatReservation $reservation, ?User $actor, string $reason): void
    {
        $values = [
            'status' => EnrollmentSeatReservation::StatusReleased,
            'released_at' => now(),
            'lock_version' => ((int) $reservation->lock_version) + 1,
        ];

        if ($actor instanceof User) {
            $values['registrar_user_id'] = $actor->id;
        }

        $reservation->update($values);

        $reservation->courseEnrollment?->scheduleBindings()
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'effective_until' => now()->toDateString(),
                'released_by' => $actor?->id,
                'released_at' => now(),
                'release_reason' => $reason,
            ]);
    }

    private function lifecycleBlockerMessage(?StudentProfile $studentProfile): string
    {
        return sprintf(
            'Enrollment placement is unavailable while the student lifecycle status is %s.',
            StudentProfile::lifecycleStatusLabel($studentProfile?->lifecycle_status),
        );
    }

    private function unitsSnapshot(?TermOffering $termOffering): ?string
    {
        $units = $termOffering?->curriculumEntry?->courseSpecification?->credit_units;

        return $units !== null ? (string) $units : null;
    }

    private function meetingLabel(SectionMeeting $meeting): string
    {
        $day = SectionMeeting::dayOptions()[$meeting->day_of_week] ?? 'Day '.$meeting->day_of_week;
        $room = $meeting->room?->code !== null ? " {$meeting->room->code}" : '';

        return "{$day} {$meeting->starts_at}-{$meeting->ends_at}{$room}";
    }

    /**
     * @param  Collection<int, SectionMeeting>  $first
     * @param  Collection<int, SectionMeeting>  $second
     */
    private function meetingsOverlap(Collection $first, Collection $second): bool
    {
        foreach ($first as $firstMeeting) {
            foreach ($second as $secondMeeting) {
                if ((int) $firstMeeting->day_of_week !== (int) $secondMeeting->day_of_week) {
                    continue;
                }

                if ((string) $firstMeeting->starts_at < (string) $secondMeeting->ends_at
                    && (string) $firstMeeting->ends_at > (string) $secondMeeting->starts_at) {
                    return true;
                }
            }
        }

        return false;
    }

    private function assertPlacementMutable(Enrollment $enrollment): void
    {
        if (! $this->placementIsMutable($enrollment)) {
            $this->reject('status', 'This enrollment is already in a terminal state and cannot be placed or replaced.');
        }
    }

    /**
     * @throws ValidationException
     */
    private function reject(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
