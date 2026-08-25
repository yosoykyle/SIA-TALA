<?php

namespace App\Actions\Enrollment;

use App\Actions\Calendar\CalendarPhaseGateService;
use App\Actions\Calendar\Exceptions\CalendarGateViolation;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EnrollmentProposalService
{
    public function __construct(
        private readonly CalendarPhaseGateService $calendarPhaseGate,
        private readonly SubjectSuggestionService $subjectSuggestions,
        private readonly EnrollmentGateEvaluator $gateEvaluator,
        private readonly StudentUnitLoadService $unitLoad,
        private readonly EnrollmentPlacementService $placement,
    ) {}

    /**
     * @param  list<int>  $sectionIds
     * @return Collection<int, CourseEnrollment>
     *
     * @throws AuthorizationException
     * @throws CalendarGateViolation
     * @throws ValidationException
     */
    public function replace(Enrollment $enrollment, array $sectionIds, User $actor): Collection
    {
        $enrollment->loadMissing('studentProfile.user');
        $profile = $enrollment->studentProfile;

        if (! $profile instanceof StudentProfile
            || (int) $profile->user_id !== (int) $actor->id
            || ! $actor->hasRole('student')) {
            throw new AuthorizationException('Only the owning Student may update this enrollment proposal.');
        }

        if ($enrollment->student_type !== 'irregular') {
            throw ValidationException::withMessages([
                'section_ids' => 'Only an irregular enrollment accepts Student-selected section proposals.',
            ]);
        }

        if ($profile->blocksEnrollmentByLifecycle()) {
            throw ValidationException::withMessages([
                'student_profile_id' => 'The Student lifecycle status does not allow enrollment changes.',
            ]);
        }

        $sectionIds = collect($sectionIds)
            ->map(fn (mixed $sectionId): int => (int) $sectionId)
            ->filter(fn (int $sectionId): bool => $sectionId > 0)
            ->unique()
            ->values()
            ->all();

        if ($sectionIds === []) {
            throw ValidationException::withMessages([
                'section_ids' => 'Select at least one eligible published section.',
            ]);
        }

        $this->calendarPhaseGate->assertEnrollmentEditWindowOpen($enrollment->term_id);
        $eligibleOfferingIds = $this->eligibleOfferingIds($enrollment);

        $proposals = DB::transaction(function () use ($enrollment, $sectionIds, $eligibleOfferingIds): Collection {
            $lockedEnrollment = Enrollment::query()
                ->with('studentProfile')
                ->whereKey($enrollment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($lockedEnrollment->status, ['officially_enrolled', 'cancelled', 'dropped', 'withdrawn'], true)) {
                throw ValidationException::withMessages([
                    'section_ids' => 'This enrollment no longer accepts section proposals.',
                ]);
            }

            if ($lockedEnrollment->seatReservations()
                ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
                ->lockForUpdate()
                ->exists()) {
                throw ValidationException::withMessages([
                    'section_ids' => 'Registrar-confirmed placement already exists. Contact the Registrar for replacement.',
                ]);
            }

            $sections = Section::query()
                ->with('termOffering.curriculumEntry.courseSpecification')
                ->whereIn('id', $sectionIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($sections->count() !== count($sectionIds)) {
                throw ValidationException::withMessages([
                    'section_ids' => 'One or more selected sections do not exist.',
                ]);
            }

            $selectedOfferingIds = [];

            foreach ($sectionIds as $sectionId) {
                /** @var Section $section */
                $section = $sections->get($sectionId);
                $offering = $section->termOffering;

                if (! $offering instanceof TermOffering
                    || (int) $offering->term_id !== (int) $lockedEnrollment->term_id
                    || $offering->state !== TermOffering::StateScheduled
                    || $section->state !== Section::StateOpen
                    || ! $eligibleOfferingIds->contains((int) $offering->id)
                    || ! $this->hasPublishedMeetings($section)) {
                    throw ValidationException::withMessages([
                        'section_ids' => 'Every proposal must be an eligible open section with an active published schedule in this enrollment term.',
                    ]);
                }

                if (in_array((int) $offering->id, $selectedOfferingIds, true)) {
                    throw ValidationException::withMessages([
                        'section_ids' => 'Select only one section for each subject offering.',
                    ]);
                }

                if ($this->placement->sectionOperationalSummary($section)['remaining'] < 1) {
                    throw ValidationException::withMessages([
                        'capacity' => "Section {$section->code} has no remaining capacity. Select another published section.",
                    ]);
                }

                $selectedOfferingIds[] = (int) $offering->id;
            }

            $requestedUnits = $sections->sum(
                fn (Section $section): float => (float) $section->termOffering?->courseSpecification()?->credit_units,
            );
            $load = $this->unitLoad->evaluate($lockedEnrollment, $requestedUnits);

            if ($load['unit_load_passes'] !== true) {
                throw ValidationException::withMessages([
                    'section_ids' => (string) $load['blocker'],
                ]);
            }

            $this->assertNoScheduleConflicts($lockedEnrollment, $sections->values());

            CourseEnrollment::query()
                ->where('enrollment_id', $lockedEnrollment->id)
                ->where('status', CourseEnrollment::StatusActive)
                ->whereNotIn('term_offering_id', $selectedOfferingIds)
                ->whereDoesntHave('seatReservations', fn (Builder $query) => $query
                    ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses()))
                ->lockForUpdate()
                ->get()
                ->each(fn (CourseEnrollment $courseEnrollment) => $courseEnrollment->update([
                    'status' => CourseEnrollment::StatusDropped,
                    'proposed_section_id' => null,
                    'proposed_at' => null,
                    'dropped_at' => now(),
                    'status_reason' => 'Removed from the Student section proposal.',
                ]));

            $records = collect();

            foreach ($sectionIds as $sectionId) {
                /** @var Section $section */
                $section = $sections->get($sectionId);
                $offering = $section->termOffering;
                $courseEnrollment = CourseEnrollment::query()->updateOrCreate(
                    [
                        'enrollment_id' => $lockedEnrollment->id,
                        'term_offering_id' => $offering->id,
                    ],
                    [
                        'proposed_section_id' => $section->id,
                        'proposed_at' => now(),
                        'status' => CourseEnrollment::StatusActive,
                        'units_snapshot' => $offering->courseSpecification()?->credit_units,
                        'added_at' => now(),
                        'dropped_at' => null,
                        'withdrawn_at' => null,
                        'status_reason' => 'Student-proposed section awaiting Registrar confirmation.',
                    ],
                );
                $records->push($courseEnrollment);
            }

            return $records;
        }, attempts: 3);

        $this->gateEvaluator->persist($enrollment->refresh());

        return $proposals;
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
            ->filter(fn (mixed $offeringId): bool => is_numeric($offeringId))
            ->map(fn (mixed $offeringId): int => (int) $offeringId)
            ->unique()
            ->values();
    }

    private function hasPublishedMeetings(Section $section): bool
    {
        return SectionMeeting::query()
            ->where('state', SectionMeeting::StateActive)
            ->whereHas('scheduleRun', fn (Builder $query) => $query
                ->where('status', ScheduleGenerationRun::StatusPublished))
            ->whereHas('schedulingDemand', fn (Builder $query) => $query
                ->where('term_offering_id', $section->term_offering_id)
                ->whereHas('sectionDeliveryGroup', fn (Builder $query) => $query
                    ->where('section_id', $section->id)))
            ->exists();
    }

    /**
     * @param  Collection<int, Section>  $sections
     */
    private function assertNoScheduleConflicts(Enrollment $enrollment, Collection $sections): void
    {
        $meetingsBySection = $sections->mapWithKeys(fn (Section $section): array => [
            $section->id => $this->publishedMeetingsForSection($section),
        ]);

        foreach ($sections->values() as $index => $section) {
            foreach ($sections->slice($index + 1) as $otherSection) {
                if ($this->meetingsOverlap(
                    $meetingsBySection->get($section->id, collect()),
                    $meetingsBySection->get($otherSection->id, collect()),
                )) {
                    throw ValidationException::withMessages([
                        'conflict' => "Selected sections {$section->code} and {$otherSection->code} overlap.",
                    ]);
                }
            }
        }

        $activeMeetings = $enrollment->courseEnrollments()
            ->where('status', CourseEnrollment::StatusActive)
            ->whereHas('scheduleBindings', fn (Builder $query) => $query->where('is_active', true))
            ->with(['scheduleBindings' => fn ($query) => $query
                ->where('is_active', true)
                ->with('sectionMeeting')])
            ->get()
            ->flatMap(fn (CourseEnrollment $courseEnrollment): Collection => $courseEnrollment->scheduleBindings
                ->pluck('sectionMeeting')
                ->filter(fn (mixed $meeting): bool => $meeting instanceof SectionMeeting));

        foreach ($sections as $section) {
            if ($this->meetingsOverlap(
                $meetingsBySection->get($section->id, collect()),
                $activeMeetings,
            )) {
                throw ValidationException::withMessages([
                    'conflict' => "Selected section {$section->code} overlaps the Student's confirmed schedule.",
                ]);
            }
        }
    }

    /**
     * @return Collection<int, SectionMeeting>
     */
    private function publishedMeetingsForSection(Section $section): Collection
    {
        return SectionMeeting::query()
            ->where('state', SectionMeeting::StateActive)
            ->whereHas('scheduleRun', fn (Builder $query) => $query
                ->where('status', ScheduleGenerationRun::StatusPublished))
            ->whereHas('schedulingDemand', fn (Builder $query) => $query
                ->where('term_offering_id', $section->term_offering_id)
                ->whereHas('sectionDeliveryGroup', fn (Builder $query) => $query
                    ->where('section_id', $section->id)))
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->get();
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
}
