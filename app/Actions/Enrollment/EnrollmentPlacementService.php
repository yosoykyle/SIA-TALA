<?php

namespace App\Actions\Enrollment;

use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\EnrollmentGateResult;
use App\Models\EnrollmentSeatReservation;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\StudentScheduleBinding;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EnrollmentPlacementService
{
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
     * @return Collection<int, array{section_id:int,label:string,remaining:int<0, max>,capacity:int<0, max>,reserved:int,official:int,schedule:string}>
     */
    public function placementSummaries(Enrollment $enrollment): Collection
    {
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
     * @return array{course_enrollment:CourseEnrollment,reservation:EnrollmentSeatReservation,bindings:int,already_confirmed:bool}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function confirm(Enrollment $enrollment, int $sectionId, User $actor): array
    {
        Gate::forUser($actor)->authorize('confirmPlacement', $enrollment);

        return DB::transaction(function () use ($enrollment, $sectionId, $actor): array {
            $lockedEnrollment = Enrollment::query()
                ->whereKey($enrollment->id)
                ->lockForUpdate()
                ->firstOrFail();

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
                if ((int) $reservation->course_enrollment_id !== (int) $courseEnrollment->id) {
                    $this->reject('section_id', 'This enrollment already has a different active placement reservation.');
                }

                if ((int) $reservation->section_id !== (int) $section->id) {
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

            $this->recordPassedGateResults($lockedEnrollment, $section);

            $lockedEnrollment->update([
                'status' => 'pending_payment',
                'registered_at' => $lockedEnrollment->registered_at ?? now(),
                'status_reason' => 'Placement confirmed; awaiting finance assessment and payment gate.',
            ]);

            return [
                'course_enrollment' => $courseEnrollment->refresh(),
                'reservation' => $reservation->refresh(),
                'bindings' => $bindings,
                'already_confirmed' => $samePlacement instanceof EnrollmentSeatReservation && $bindings === 0,
            ];
        }, attempts: 3);
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

    private function releaseReservation(EnrollmentSeatReservation $reservation, User $actor, string $reason): void
    {
        $reservation->update([
            'status' => EnrollmentSeatReservation::StatusReleased,
            'released_at' => now(),
            'registrar_user_id' => $actor->id,
            'lock_version' => ((int) $reservation->lock_version) + 1,
        ]);

        $reservation->courseEnrollment?->scheduleBindings()
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'effective_until' => now()->toDateString(),
                'released_by' => $actor->id,
                'released_at' => now(),
                'release_reason' => $reason,
            ]);
    }

    private function recordPassedGateResults(Enrollment $enrollment, Section $section): void
    {
        foreach ([
            EnrollmentGateResult::GatePlacement,
            EnrollmentGateResult::GateCapacity,
            EnrollmentGateResult::GateConflict,
        ] as $gateType) {
            EnrollmentGateResult::query()->updateOrCreate(
                [
                    'enrollment_id' => $enrollment->id,
                    'gate_type' => $gateType,
                    'sequence' => 1,
                ],
                [
                    'result' => EnrollmentGateResult::ResultPassed,
                    'responsible_office' => EnrollmentGateResult::ResponsibleOfficeRegistrar,
                    'blocker_code' => null,
                    'blocker_message' => null,
                    'source_type' => Section::class,
                    'source_id' => $section->id,
                    'checked_at' => now(),
                    'rule_version' => EnrollmentGateResult::RuleVersionTal67Mvp,
                ],
            );
        }
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
     * @throws ValidationException
     */
    private function reject(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
