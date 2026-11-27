<?php

namespace App\Actions\Scheduling;

use App\Actions\Enrollment\RecordRegistrationSourceImpactReview;
use App\Actions\Grades\ManageTeachingAssignment;
use App\Actions\Grades\SynchronizeOfficialGradeRoster;
use App\Filament\Resources\ScheduleGenerationRuns\Schemas\PublishedScheduleRevisionForm;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\GradeRoster;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationCaseEvent;
use App\Models\RegistrationProposalVersion;
use App\Models\ScheduleGenerationRun;
use App\Models\ScheduleRevisionEvent;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TimetableRevision;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class PublishedScheduleRevisionService
{
    public function __construct(
        private ScheduleRevisionImpactService $impactService,
        private ScheduleRevisionNotificationService $notificationService,
        private RevisePublishedTimetable $reviseTimetable,
        private TimetableRevisionImpactGuard $impactGuard,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $changes
     * @return EloquentCollection<int, ScheduleRevisionEvent>
     */
    public function revise(
        ScheduleGenerationRun $run,
        User $actor,
        string $changeType,
        array $changes,
        string $reason,
        ?string $authorityReference = null,
        ?string $reassignmentScope = null,
        bool $addCoFaculty = false,
        bool $expandTeacherAvailability = false,
    ): EloquentCollection {
        Gate::forUser($actor)->authorize('revise', SectionMeeting::class);
        $this->assertTermOpen($run);
        $reason = $this->reason($reason);
        [$sanitizedChanges, $resolvedScope, $resolvedAddCoFaculty, $resolvedExpandAvailability] = $this->extractReassignmentOptions(
            $changeType,
            $changes,
            $reassignmentScope,
            $addCoFaculty,
            $expandTeacherAvailability,
        );

        if ($resolvedExpandAvailability && ! $actor->hasRole(User::StaffRoleRegistrar)) {
            abort(403);
        }

        $preview = $this->impactService->preview(
            $run,
            $changeType,
            $sanitizedChanges,
            simulateAvailabilityExpansion: $resolvedExpandAvailability,
        );
        $this->assertPasses($preview);
        $currentVersion = $this->canonicalVersionForRun($run, $actor, CarbonImmutable::now(config('app.timezone')));
        $reassignmentMetadata = $this->reassignmentMetadata(
            $changeType,
            $resolvedScope,
            $resolvedAddCoFaculty,
            $resolvedExpandAvailability,
        );
        $canonicalChanges = $this->canonicalChanges($currentVersion, $preview, $reassignmentMetadata);
        $authority = $this->authorityReference($authorityReference);

        if ($resolvedExpandAvailability) {
            return $this->reviseWithAtomicAvailabilityExpansion(
                run: $run,
                currentVersion: $currentVersion,
                actor: $actor,
                changeType: $changeType,
                sanitizedChanges: $sanitizedChanges,
                canonicalChanges: $canonicalChanges,
                reason: $reason,
                authorityReference: $authorityReference,
                authority: $authority,
                resolvedScope: $resolvedScope,
                resolvedAddCoFaculty: $resolvedAddCoFaculty,
                reassignmentMetadata: $reassignmentMetadata,
            );
        }

        $preparedRevision = $this->impactGuard->prepare($currentVersion, $actor, $canonicalChanges, $authority, $reason);
        DB::transaction(fn (): TimetableRevision => $this->impactGuard->assertReady($preparedRevision, $currentVersion, $canonicalChanges));

        return DB::transaction(function () use (
            $run,
            $actor,
            $changeType,
            $sanitizedChanges,
            $reason,
            $authorityReference,
            $authority,
            $preparedRevision,
            $resolvedScope,
            $resolvedAddCoFaculty,
            $reassignmentMetadata,
        ): EloquentCollection {
            $lockedRun = $this->lockedPublishedRun($run);
            Gate::forUser($actor)->authorize('revise', SectionMeeting::class);
            $impact = $this->impactService->lockForRevision($lockedRun, $changeType, $sanitizedChanges);
            $this->assertPasses($impact);
            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $events = $this->applyMeetingChanges($lockedRun, $actor, $reason, $timestamp, $impact, $authorityReference, $preparedRevision, $reassignmentMetadata);

            if ($changeType === ScheduleRevisionEvent::ChangeFacultyReassignment) {
                $this->applyFacultyReassignmentScope(
                    actor: $actor,
                    impact: $impact,
                    scope: $resolvedScope ?? PublishedScheduleRevisionForm::ScopeTemporarySubstitute,
                    addCoFaculty: $resolvedAddCoFaculty,
                    teachingAuthority: $authority ?? $reason,
                );
            }

            $this->recordActivity(
                run: $lockedRun,
                actor: $actor,
                reason: $reason,
                timestamp: $timestamp,
                impact: $impact,
                events: $events,
                event: 'published_schedule_revised',
                description: 'Published timetable successor created.',
                reassignmentScope: $resolvedScope,
                addCoFaculty: $resolvedAddCoFaculty,
            );
            DB::afterCommit(fn () => $this->notificationService->recordAndQueue($events));

            return $events;
        }, attempts: 5);
    }

    /** @return EloquentCollection<int, ScheduleRevisionEvent> */
    public function cancelSection(
        ScheduleGenerationRun $run,
        Section $section,
        User $actor,
        string $reason,
        ?string $authorityReference = null,
    ): EloquentCollection {
        Gate::forUser($actor)->authorize('revise', SectionMeeting::class);
        $this->assertTermOpen($run);
        $reason = $this->reason($reason);
        $preview = $this->impactService->previewCancellation($run, $section);
        $this->assertHardRulesPass($preview);
        $currentVersion = $this->canonicalVersionForRun($run, $actor, CarbonImmutable::now(config('app.timezone')));
        $canonicalChanges = $this->canonicalChanges($currentVersion, $preview);
        $authority = $this->authorityReference($authorityReference);
        $preparedRevision = $this->impactGuard->prepare($currentVersion, $actor, $canonicalChanges, $authority, $reason, true);
        DB::transaction(fn (): TimetableRevision => $this->impactGuard->assertReady($preparedRevision, $currentVersion, $canonicalChanges));

        return DB::transaction(function () use ($run, $section, $actor, $reason, $authorityReference, $preparedRevision): EloquentCollection {
            $lockedRun = $this->lockedPublishedRun($run);
            Gate::forUser($actor)->authorize('revise', SectionMeeting::class);
            $lockedSection = Section::query()
                ->whereKey($section->id)
                ->lockForUpdate()
                ->firstOrFail();
            $groups = SectionDeliveryGroup::query()
                ->where('section_id', $lockedSection->id)
                ->whereIn('state', [
                    SectionDeliveryGroup::StatePlanned,
                    SectionDeliveryGroup::StateReady,
                ])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $impact = $this->impactService->lockForCancellation($lockedRun, $lockedSection);
            $this->assertPasses($impact);
            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $events = $this->applyMeetingChanges($lockedRun, $actor, $reason, $timestamp, $impact, $authorityReference, $preparedRevision);

            foreach ($groups as $group) {
                $group->forceFill(['state' => SectionDeliveryGroup::StateCancelled])->save();
            }

            $lockedSection->forceFill(['state' => Section::StateCancelled])->save();

            $this->recordActivity(
                run: $lockedRun,
                actor: $actor,
                reason: $reason,
                timestamp: $timestamp,
                impact: $impact,
                events: $events,
                event: 'published_section_cancelled',
                description: 'Published schedule Section cancelled.',
                sectionId: (int) $lockedSection->id,
            );
            DB::afterCommit(fn () => $this->notificationService->recordAndQueue($events));

            return $events;
        }, attempts: 5);
    }

    /** @return EloquentCollection<int, ScheduleRevisionEvent> */
    public function publishDraftRevision(
        ScheduleGenerationRun $run,
        TimetableRevision $revision,
        User $actor,
    ): EloquentCollection {
        Gate::forUser($actor)->authorize('revise', SectionMeeting::class);
        $this->assertTermOpen($run);
        $revision->loadMissing('sourceVersion.meetings');
        $sourceVersion = $revision->sourceVersion;

        if ($revision->state !== TimetableRevision::StateDraft
            || ! $sourceVersion instanceof PublishedTimetableVersion
            || (int) $sourceVersion->schedule_run_id !== (int) $run->id) {
            throw ValidationException::withMessages([
                'revision' => 'Select a pending Draft timetable revision belonging to this published schedule run.',
            ]);
        }

        $changesSnapshot = is_array($revision->changes_snapshot) ? $revision->changes_snapshot : [];
        if ($changesSnapshot === []) {
            throw ValidationException::withMessages([
                'revision' => 'The pending Draft timetable revision contains no meeting changes.',
            ]);
        }

        [$draftReassignmentScope, $draftAddCoFaculty, $draftExpandAvailability] = $this->draftReassignmentOptions($changesSnapshot);
        $changesSnapshot = $this->withoutReassignmentMetadata($changesSnapshot);

        if ($revision->change_type === 'SectionCancellation') {
            $sectionId = $sourceVersion->meetings
                ->whereIn('id', array_keys($changesSnapshot))
                ->pluck('section_id')
                ->filter()
                ->first();

            if ($sectionId === null) {
                throw ValidationException::withMessages([
                    'revision' => 'The pending Draft cancellation is missing its target class section.',
                ]);
            }

            return $this->cancelSection(
                $run,
                Section::query()->findOrFail((int) $sectionId),
                $actor,
                (string) $revision->reason,
                $revision->authority_reference,
            );
        }

        $sectionMeetings = SectionMeeting::query()
            ->where('schedule_run_id', $run->id)
            ->where('state', SectionMeeting::StateActive)
            ->get()
            ->keyBy(fn (SectionMeeting $meeting): string => ((int) $meeting->scheduling_demand_id).':'.((int) $meeting->meeting_sequence));

        $detectedTypes = [];
        $changes = [];

        foreach ($changesSnapshot as $publishedMeetingId => $change) {
            $canonical = $sourceVersion->meetings->firstWhere('id', (int) $publishedMeetingId);
            if (! $canonical instanceof PublishedTimetableMeeting || ! is_array($change)) {
                throw ValidationException::withMessages([
                    'revision' => 'The pending Draft timetable revision does not match the current published meetings.',
                ]);
            }

            $sectionMeeting = $sectionMeetings->get(((int) $canonical->scheduling_demand_id).':'.((int) $canonical->meeting_sequence));
            if (! $sectionMeeting instanceof SectionMeeting) {
                throw ValidationException::withMessages([
                    'revision' => 'The pending Draft timetable revision references a meeting outside this published run.',
                ]);
            }

            $facultyChanged = array_key_exists('faculty_user_id', $change) && (int) $change['faculty_user_id'] !== (int) $canonical->faculty_user_id;
            $modalityChanged = array_key_exists('modality', $change) && (string) $change['modality'] !== (string) $canonical->modality;
            $timeChanged = (array_key_exists('day_of_week', $change) && (int) $change['day_of_week'] !== (int) $canonical->day_of_week)
                || (array_key_exists('starts_at', $change) && (string) $change['starts_at'] !== (string) $canonical->starts_at)
                || (array_key_exists('ends_at', $change) && (string) $change['ends_at'] !== (string) $canonical->ends_at);
            $roomChanged = array_key_exists('room_id', $change)
                && ($change['room_id'] === null ? null : (int) $change['room_id']) !== ($canonical->room_id === null ? null : (int) $canonical->room_id);

            $rowType = match (true) {
                $facultyChanged && ! $modalityChanged && ! $timeChanged && ! $roomChanged => ScheduleRevisionEvent::ChangeFacultyReassignment,
                $modalityChanged && ! $facultyChanged && ! $timeChanged => ScheduleRevisionEvent::ChangeDeliveryModality,
                $timeChanged && ! $facultyChanged && ! $modalityChanged && ! $roomChanged => ScheduleRevisionEvent::ChangeTime,
                $roomChanged && ! $facultyChanged && ! $modalityChanged && ! $timeChanged => ScheduleRevisionEvent::ChangeRoom,
                default => null,
            };

            if ($rowType === null) {
                $detectedTypes[] = 'mixed';
                break;
            }

            $detectedTypes[] = $rowType;
            $changes[] = match ($rowType) {
                ScheduleRevisionEvent::ChangeFacultyReassignment => [
                    'section_meeting_id' => $sectionMeeting->id,
                    'faculty_user_id' => (int) $change['faculty_user_id'],
                ],
                ScheduleRevisionEvent::ChangeDeliveryModality => [
                    'section_meeting_id' => $sectionMeeting->id,
                    'modality' => (string) $change['modality'],
                    'room_id' => $change['room_id'] === null ? null : (int) $change['room_id'],
                ],
                ScheduleRevisionEvent::ChangeTime => [
                    'section_meeting_id' => $sectionMeeting->id,
                    'day_of_week' => (int) ($change['day_of_week'] ?? $canonical->day_of_week),
                    'starts_at' => (string) ($change['starts_at'] ?? $canonical->starts_at),
                    'ends_at' => (string) ($change['ends_at'] ?? $canonical->ends_at),
                ],
                ScheduleRevisionEvent::ChangeRoom => [
                    'section_meeting_id' => $sectionMeeting->id,
                    'room_id' => $change['room_id'] === null ? null : (int) $change['room_id'],
                ],
            };
        }

        $uniqueTypes = array_values(array_unique($detectedTypes));
        if (count($uniqueTypes) === 1 && $uniqueTypes[0] !== 'mixed') {
            return $this->revise(
                $run,
                $actor,
                $uniqueTypes[0],
                $changes,
                (string) $revision->reason,
                $revision->authority_reference,
                $draftReassignmentScope,
                $draftAddCoFaculty,
                $draftExpandAvailability,
            );
        }

        return DB::transaction(function () use ($run, $revision, $actor, $draftReassignmentScope, $draftAddCoFaculty, $draftExpandAvailability): EloquentCollection {
            $lockedRun = $this->lockedPublishedRun($run);
            Gate::forUser($actor)->authorize('revise', SectionMeeting::class);
            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $currentVersion = $this->canonicalVersionForRun($lockedRun, $actor, $timestamp);
            $lockedRevision = TimetableRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();
            $lockedChanges = (array) $lockedRevision->changes_snapshot;

            if ($draftExpandAvailability) {
                $term = Term::query()->findOrFail($lockedRun->term_id);
                $this->impactGuard->assertReady($lockedRevision, $currentVersion, $lockedChanges);
                $this->expandAvailabilityForCanonicalChanges(
                    term: $term,
                    run: $lockedRun,
                    currentVersion: $currentVersion,
                    canonicalChanges: $lockedChanges,
                    actor: $actor,
                    reason: (string) $lockedRevision->reason,
                );
            }

            $successor = $this->reviseTimetable->execute(
                $currentVersion,
                $actor,
                $lockedChanges,
                $lockedRevision->authority_reference,
                (string) $lockedRevision->reason,
                false,
                $lockedRevision,
            );
            $lockedChanges = $this->withoutReassignmentMetadata($lockedChanges);

            $events = [];
            $facultyMeetingChanges = [];
            foreach ($lockedChanges as $publishedMeetingId => $change) {
                $oldCanonical = $currentVersion->meetings->firstWhere('id', (int) $publishedMeetingId);
                if (! $oldCanonical instanceof PublishedTimetableMeeting) {
                    continue;
                }
                $newMeeting = SectionMeeting::query()
                    ->where('schedule_run_id', $successor->schedule_run_id)
                    ->where('scheduling_demand_id', $oldCanonical->scheduling_demand_id)
                    ->where('meeting_sequence', $oldCanonical->meeting_sequence)
                    ->firstOrFail();
                $oldSnapshot = [
                    'faculty_user_id' => (int) $oldCanonical->faculty_user_id,
                    'room_id' => $oldCanonical->room_id,
                    'day_of_week' => (int) $oldCanonical->day_of_week,
                    'starts_at' => (string) $oldCanonical->starts_at,
                    'ends_at' => (string) $oldCanonical->ends_at,
                    'modality' => (string) $oldCanonical->modality,
                    'state' => SectionMeeting::StateActive,
                ];
                $newSnapshot = [
                    'faculty_user_id' => (int) $newMeeting->faculty_user_id,
                    'room_id' => $newMeeting->room_id,
                    'day_of_week' => (int) $newMeeting->day_of_week,
                    'starts_at' => (string) $newMeeting->starts_at,
                    'ends_at' => (string) $newMeeting->ends_at,
                    'modality' => (string) $newMeeting->modality,
                    'state' => (string) $newMeeting->state,
                ];
                $event = new ScheduleRevisionEvent;
                $event->forceFill([
                    'term_id' => $lockedRun->term_id,
                    'section_meeting_id' => $newMeeting->id,
                    'change_type' => ScheduleRevisionEvent::ChangeTime,
                    'reason' => (string) $lockedRevision->reason,
                    'effective_date' => $timestamp->toDateString(),
                    'changed_by' => $actor->id,
                    'old_snapshot_json' => $oldSnapshot,
                    'new_snapshot_json' => $newSnapshot,
                    'affected_student_count' => (int) ($lockedRevision->impact_snapshot['affected_count'] ?? 0),
                    'affected_faculty_count' => 1,
                    'created_at' => $timestamp,
                ])->save();
                $events[] = $event;

                if ((int) $oldSnapshot['faculty_user_id'] !== (int) $newSnapshot['faculty_user_id']) {
                    $facultyMeetingChanges[] = [
                        'section_meeting_id' => (int) $newMeeting->id,
                        'old' => $oldSnapshot,
                        'new' => $newSnapshot,
                    ];
                }
            }

            $collection = new EloquentCollection($events);

            $resolvedScope = null;
            $resolvedAddCoFaculty = false;
            if ($facultyMeetingChanges !== []) {
                $resolvedScope = PublishedScheduleRevisionForm::reassignmentScope([
                    'reassignment_scope' => $draftReassignmentScope,
                ]);
                $resolvedAddCoFaculty = $resolvedScope === PublishedScheduleRevisionForm::ScopeTemporarySubstitute
                    && $draftAddCoFaculty;
            }

            $impact = new ScheduleRevisionImpact(
                changeType: (string) ($lockedRevision->change_type ?: ScheduleRevisionEvent::ChangeTime),
                meetingChanges: collect($events)
                    ->map(fn (ScheduleRevisionEvent $event): array => [
                        'section_meeting_id' => (int) $event->section_meeting_id,
                        'old' => $event->old_snapshot_json,
                        'new' => $event->new_snapshot_json,
                    ])
                    ->values()
                    ->all(),
                affectedStudents: (int) ($lockedRevision->impact_snapshot['affected_count'] ?? 0),
                affectedFaculty: 1,
                findings: [],
            );

            if ($facultyMeetingChanges !== []) {
                $this->applyFacultyReassignmentScope(
                    actor: $actor,
                    impact: new ScheduleRevisionImpact(
                        changeType: ScheduleRevisionEvent::ChangeFacultyReassignment,
                        meetingChanges: $facultyMeetingChanges,
                        affectedStudents: (int) ($lockedRevision->impact_snapshot['affected_count'] ?? 0),
                        affectedFaculty: 1,
                        findings: [],
                    ),
                    scope: $resolvedScope ?? PublishedScheduleRevisionForm::ScopeTemporarySubstitute,
                    addCoFaculty: $resolvedAddCoFaculty,
                    teachingAuthority: $lockedRevision->authority_reference ?: (string) $lockedRevision->reason,
                );
            }

            $this->recordActivity(
                run: $lockedRun,
                actor: $actor,
                reason: (string) $lockedRevision->reason,
                timestamp: $timestamp,
                impact: $impact,
                events: $collection,
                event: 'published_schedule_revised',
                description: 'Published timetable successor created from pending Draft revision.',
                reassignmentScope: $resolvedScope,
                addCoFaculty: $resolvedAddCoFaculty,
            );
            DB::afterCommit(fn () => $this->notificationService->recordAndQueue($collection));

            return $collection;
        }, attempts: 5);
    }

    /**
     * @param  array<int|string, mixed>  $changesSnapshot
     * @return array{0: string|null, 1: bool, 2: bool}
     */
    private function draftReassignmentOptions(array $changesSnapshot): array
    {
        $scope = null;
        $addCoFaculty = false;
        $expandAvailability = false;

        foreach ($changesSnapshot as $change) {
            if (! is_array($change)) {
                continue;
            }

            if ($scope === null && filled($change['reassignment_scope'] ?? null)) {
                $scope = (string) $change['reassignment_scope'];
            }

            if (($change['add_co_faculty'] ?? false) === true) {
                $addCoFaculty = true;
            }

            if (($change['expand_availability'] ?? false) === true) {
                $expandAvailability = true;
            }
        }

        return [$scope, $addCoFaculty, $expandAvailability];
    }

    /**
     * @param  array<int|string, mixed>  $changesSnapshot
     * @return array<int|string, mixed>
     */
    private function withoutReassignmentMetadata(array $changesSnapshot): array
    {
        return array_map(function (mixed $change): mixed {
            if (is_array($change)) {
                unset($change['reassignment_scope'], $change['add_co_faculty'], $change['expand_availability']);
            }

            return $change;
        }, $changesSnapshot);
    }

    private function assertTermOpen(ScheduleGenerationRun $run): void
    {
        $termState = Term::query()->whereKey($run->term_id)->value('state');

        if ($termState === Term::StateClosed) {
            throw ValidationException::withMessages([
                'term_id' => 'This term is closed and its published timetable cannot be revised.',
                'state' => 'This term is closed and its published timetable cannot be revised.',
            ]);
        }
    }

    private function lockedPublishedRun(ScheduleGenerationRun $run): ScheduleGenerationRun
    {
        $lockedTerm = Term::query()
            ->whereKey($run->term_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($lockedTerm->state === Term::StateClosed) {
            throw ValidationException::withMessages([
                'term_id' => 'This term is closed and its published timetable cannot be revised.',
                'state' => 'This term is closed and its published timetable cannot be revised.',
            ]);
        }

        $termRunIds = ScheduleGenerationRun::query()
            ->where('term_id', $run->term_id)
            ->orderBy('id')
            ->pluck('id');

        $termRuns = $termRunIds->isEmpty()
            ? collect()
            : ScheduleGenerationRun::query()
                ->whereKey($termRunIds)
                ->lockForUpdate()
                ->get();
        $lockedRun = $termRuns->firstWhere('id', $run->getKey());

        if (! $lockedRun instanceof ScheduleGenerationRun || ! $lockedRun->isPublished()) {
            throw ValidationException::withMessages([
                'schedule_run' => 'Live revision requires the current published schedule run.',
            ]);
        }

        return $lockedRun;
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A live schedule revision reason is required.',
            ]);
        }

        return $reason;
    }

    private function assertPasses(ScheduleRevisionImpact $impact): void
    {
        if ($impact->passes()) {
            return;
        }

        throw ValidationException::withMessages([
            'schedule_revision' => $impact->blockingMessage(),
        ]);
    }

    private function assertHardRulesPass(ScheduleRevisionImpact $impact): void
    {
        $finding = collect($impact->findings())->firstWhere('severity', 'blocking');

        if (is_array($finding)) {
            throw ValidationException::withMessages([
                'schedule_revision' => (string) ($finding['message'] ?? 'The proposed live revision failed hard-constraint validation.'),
            ]);
        }
    }

    /** @return EloquentCollection<int, ScheduleRevisionEvent> */
    private function applyMeetingChanges(
        ScheduleGenerationRun $run,
        User $actor,
        string $reason,
        CarbonImmutable $timestamp,
        ScheduleRevisionImpact $impact,
        ?string $authorityReference = null,
        ?TimetableRevision $preparedRevision = null,
        array $reassignmentMetadata = [],
    ): EloquentCollection {
        $currentVersion = $this->canonicalVersionForRun($run, $actor, $timestamp);
        $canonicalChanges = $this->canonicalChanges($currentVersion, $impact, $reassignmentMetadata);
        $authority = $this->authorityReference($authorityReference);

        $successor = $this->reviseTimetable->execute(
            $currentVersion,
            $actor,
            $canonicalChanges,
            $authority,
            $reason,
            $impact->changeType() === ScheduleRevisionEvent::ChangeSectionCancellation,
            $preparedRevision,
        );
        $events = [];

        foreach ($impact->meetingChanges() as $change) {
            $oldMeeting = SectionMeeting::query()->findOrFail($change['section_meeting_id']);
            $new = $change['new'];
            $meeting = $new['state'] === SectionMeeting::StateCancelled
                ? $oldMeeting
                : SectionMeeting::query()
                    ->where('schedule_run_id', $successor->schedule_run_id)
                    ->where('scheduling_demand_id', $oldMeeting->scheduling_demand_id)
                    ->where('meeting_sequence', $oldMeeting->meeting_sequence)
                    ->firstOrFail();
            $event = new ScheduleRevisionEvent;
            $event->forceFill([
                'term_id' => $run->term_id,
                'section_meeting_id' => $meeting->id,
                'change_type' => $impact->changeType(),
                'reason' => $reason,
                'effective_date' => $timestamp->toDateString(),
                'changed_by' => $actor->id,
                'old_snapshot_json' => $change['old'],
                'new_snapshot_json' => $new,
                'affected_student_count' => $change['affected_student_count'],
                'affected_faculty_count' => $change['affected_faculty_count'],
                'created_at' => $timestamp,
            ])->save();
            $events[] = $event;
        }

        return new EloquentCollection($events);
    }

    /**
     * Only non-default revision options are persisted on the Draft revision so
     * default revisions keep their existing content hash.
     *
     * @return array<string, mixed>
     */
    private function reassignmentMetadata(
        string $changeType,
        ?string $scope,
        bool $addCoFaculty,
        bool $expandAvailability = false,
    ): array {
        $metadata = [];

        if ($changeType === ScheduleRevisionEvent::ChangeFacultyReassignment
            && ($scope === PublishedScheduleRevisionForm::ScopePermanentTakeover || $addCoFaculty)) {
            $metadata['reassignment_scope'] = $scope ?? PublishedScheduleRevisionForm::ScopeTemporarySubstitute;
            $metadata['add_co_faculty'] = $addCoFaculty;
        }

        if ($expandAvailability) {
            $metadata['expand_availability'] = true;
        }

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $reassignmentMetadata
     * @return array<int, array<string, mixed>>
     */
    private function canonicalChanges(PublishedTimetableVersion $currentVersion, ScheduleRevisionImpact $impact, array $reassignmentMetadata = []): array
    {
        $canonicalChanges = [];

        foreach ($impact->meetingChanges() as $change) {
            $meeting = SectionMeeting::query()->findOrFail($change['section_meeting_id']);
            $canonical = $currentVersion->meetings()
                ->where('scheduling_demand_id', $meeting->scheduling_demand_id)
                ->where('meeting_sequence', $meeting->meeting_sequence)
                ->firstOrFail();
            $new = $change['new'];
            $canonicalChanges[$canonical->id] = [
                'faculty_user_id' => $new['faculty_user_id'],
                'room_id' => $new['room_id'],
                'day_of_week' => $new['day_of_week'],
                'starts_at' => $new['starts_at'],
                'ends_at' => $new['ends_at'],
                'modality' => $new['modality'],
                'location_label' => $new['modality'] === 'ONLINE' ? 'Online' : (string) ($new['room_id'] ?? 'Assigned room'),
                'remove' => $new['state'] === SectionMeeting::StateCancelled,
                ...$reassignmentMetadata,
            ];
        }

        return $canonicalChanges;
    }

    private function authorityReference(?string $authorityReference): ?string
    {
        return filled($authorityReference) ? trim($authorityReference) : null;
    }

    private function canonicalVersionForRun(
        ScheduleGenerationRun $run,
        User $actor,
        CarbonImmutable $timestamp,
    ): PublishedTimetableVersion {
        $existing = PublishedTimetableVersion::query()
            ->where('schedule_run_id', $run->id)
            ->where('state', PublishedTimetableVersion::StatePublished)
            ->with('meetings')
            ->lockForUpdate()
            ->first();

        if ($existing instanceof PublishedTimetableVersion) {
            return $existing;
        }

        $meetings = SectionMeeting::query()
            ->where('schedule_run_id', $run->id)
            ->with('schedulingDemand.sectionDeliveryGroup')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $payload = $meetings->map(fn (SectionMeeting $meeting): array => [
            'section_id' => (int) $meeting->schedulingDemand->sectionDeliveryGroup->section_id,
            'scheduling_demand_id' => (int) $meeting->scheduling_demand_id,
            'faculty_user_id' => (int) $meeting->faculty_user_id,
            'room_id' => $meeting->room_id !== null ? (int) $meeting->room_id : null,
            'meeting_sequence' => (int) $meeting->meeting_sequence,
            'day_of_week' => (int) $meeting->day_of_week,
            'starts_at' => (string) $meeting->starts_at,
            'ends_at' => (string) $meeting->ends_at,
            'modality' => (string) $meeting->modality,
            'location_label' => $meeting->modality === 'ONLINE' ? 'Online' : (string) ($meeting->room_id ?? 'Assigned room'),
        ])->values();
        $versionNumber = max(1, (int) ($run->publication_version ?? 1));
        $version = PublishedTimetableVersion::query()->create([
            'term_id' => $run->term_id,
            'schedule_run_id' => $run->id,
            'version' => $versionNumber,
            'state' => PublishedTimetableVersion::StatePublished,
            'authority_reference' => $run->publication_note ?: "Legacy published schedule run {$run->id}",
            'publication_reason' => 'Attributable baseline of the pre-Slice-3 published state.',
            'source_versions' => ['legacy_schedule_run_id' => (int) $run->id],
            'impact_summary' => ['legacy_baseline' => true],
            'content_hash' => hash('sha256', json_encode(['term_id' => (int) $run->term_id, 'version' => $versionNumber, 'meetings' => $payload->all()], JSON_THROW_ON_ERROR)),
            'published_by' => $run->published_by ?? $actor->id,
            'published_at' => $run->published_at ?? $timestamp,
        ]);

        foreach ($meetings as $index => $meeting) {
            $version->meetings()->create([
                ...$payload[$index],
                'supersedes_meeting_id' => null,
            ]);
            $meeting->forceFill(['published_timetable_version_id' => $version->id])->save();
        }

        return $version->fresh('meetings');
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     * @return array{0: list<array<string, mixed>>, 1: string|null, 2: bool, 3: bool}
     */
    private function extractReassignmentOptions(
        string $changeType,
        array $changes,
        ?string $reassignmentScope,
        bool $addCoFaculty,
        bool $expandTeacherAvailability = false,
    ): array {
        $sanitized = [];

        foreach ($changes as $change) {
            if (! is_array($change)) {
                $sanitized[] = $change;

                continue;
            }

            if ($reassignmentScope === null && array_key_exists('reassignment_scope', $change) && filled($change['reassignment_scope'])) {
                $reassignmentScope = (string) $change['reassignment_scope'];
            }

            if (! $addCoFaculty && array_key_exists('add_co_faculty', $change)) {
                $addCoFaculty = (bool) $change['add_co_faculty'];
            }

            if (! $expandTeacherAvailability && array_key_exists('expand_availability', $change)) {
                $expandTeacherAvailability = (bool) $change['expand_availability'];
            }

            unset($change['reassignment_scope'], $change['add_co_faculty'], $change['expand_availability']);
            $sanitized[] = $change;
        }

        if ($changeType !== ScheduleRevisionEvent::ChangeFacultyReassignment) {
            return [$sanitized, null, false, $expandTeacherAvailability];
        }

        $resolvedScope = PublishedScheduleRevisionForm::reassignmentScope([
            'reassignment_scope' => $reassignmentScope,
        ]);

        return [
            $sanitized,
            $resolvedScope,
            $resolvedScope === PublishedScheduleRevisionForm::ScopeTemporarySubstitute && $addCoFaculty,
            $expandTeacherAvailability,
        ];
    }

    /**
     * Execute a published schedule revision with monotonic teacher availability expansion.
     *
     * Guarantees:
     * 1. If unresolved student placement/registration impact reviews exist, parks a Draft TimetableRevision
     *    WITHOUT mutating FacultyAvailabilityDeclaration yet (so abandoned Drafts never strand widened hours).
     * 2. Once all student impact reviews are clear (or none exist), expands FacultyAvailabilityDeclaration v+1,
     *    revalidates 100% of hard rules, and publishes the successor version atomically inside one DB::transaction.
     *
     * @param  list<array<string, mixed>>  $sanitizedChanges
     * @param  array<int, array<string, mixed>>  $canonicalChanges
     * @param  array<string, mixed>  $reassignmentMetadata
     * @return EloquentCollection<int, ScheduleRevisionEvent>
     */
    private function reviseWithAtomicAvailabilityExpansion(
        ScheduleGenerationRun $run,
        PublishedTimetableVersion $currentVersion,
        User $actor,
        string $changeType,
        array $sanitizedChanges,
        array $canonicalChanges,
        string $reason,
        ?string $authorityReference,
        ?string $authority,
        ?string $resolvedScope,
        bool $resolvedAddCoFaculty,
        array $reassignmentMetadata,
    ): EloquentCollection {
        $normalized = $this->normalizedCanonicalChanges($canonicalChanges);
        $hash = $this->canonicalContentHash($currentVersion, $normalized);

        $existingDraft = TimetableRevision::query()
            ->where('source_version_id', $currentVersion->id)
            ->where('content_hash', $hash)
            ->first();

        $pendingDraftBlocksPublication = false;

        if ($existingDraft instanceof TimetableRevision) {
            $expectedCaseIds = collect($existingDraft->impact_snapshot['affected_registration_case_ids'] ?? [])
                ->map(fn (mixed $id): int => (int) $id)
                ->filter()
                ->values();

            if ($expectedCaseIds->isNotEmpty()) {
                $sourceReference = $this->impactGuard->sourceReference($existingDraft);
                $pendingDraftBlocksPublication = $expectedCaseIds->contains(function (int $caseId) use ($sourceReference): bool {
                    $opened = RegistrationCaseEvent::query()
                        ->where('enrollment_id', $caseId)
                        ->where('event_type', 'TimetableRevisionImpactReviewOpened')
                        ->where('authority_reference', $sourceReference)
                        ->exists();
                    $resolved = RegistrationCaseEvent::query()
                        ->where('enrollment_id', $caseId)
                        ->where('event_type', 'TimetableRevisionImpactReviewResolved')
                        ->where('authority_reference', $sourceReference)
                        ->exists();

                    return ! $opened || ! $resolved;
                });
            }
        } else {
            $meetings = $currentVersion->meetings()->orderBy('id')->get();
            $changedSectionIds = $meetings
                ->filter(fn (PublishedTimetableMeeting $meeting): bool => array_key_exists((int) $meeting->id, $normalized))
                ->pluck('section_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values();
            $hasTimeChange = $meetings->contains(function (PublishedTimetableMeeting $meeting) use ($normalized): bool {
                $change = $normalized[(int) $meeting->id] ?? null;
                if (! is_array($change) || ($change['remove'] ?? false) === true) {
                    return false;
                }

                $normalizeTime = static fn (mixed $time): string => strlen((string) $time) === 5 ? ((string) $time).':00' : (string) $time;

                return (array_key_exists('day_of_week', $change) && (int) $change['day_of_week'] !== (int) $meeting->day_of_week)
                    || (array_key_exists('starts_at', $change) && $normalizeTime($change['starts_at']) !== $normalizeTime($meeting->starts_at))
                    || (array_key_exists('ends_at', $change) && $normalizeTime($change['ends_at']) !== $normalizeTime($meeting->ends_at));
            });

            $affectedCaseIds = $hasTimeChange && $changedSectionIds->isNotEmpty()
                ? Enrollment::query()
                    ->where('canonical_outcome', Enrollment::OutcomeInProgress)
                    ->whereHas('currentProposalVersion', fn ($query) => $query
                        ->where('state', RegistrationProposalVersion::StateConfirmed)
                        ->whereHas('items', fn ($items) => $items->whereIn('section_id', $changedSectionIds->all())))
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all()
                : [];

            if ($affectedCaseIds !== []) {
                $pendingDraftBlocksPublication = true;
                $existingDraft = DB::transaction(function () use (
                    $currentVersion,
                    $actor,
                    $normalized,
                    $changedSectionIds,
                    $affectedCaseIds,
                    $hasTimeChange,
                    $hash,
                    $authority,
                    $reason,
                ): TimetableRevision {
                    $revision = TimetableRevision::query()->create([
                        'term_id' => $currentVersion->term_id,
                        'source_version_id' => $currentVersion->id,
                        'state' => TimetableRevision::StateDraft,
                        'change_type' => 'TargetedRevision',
                        'changes_snapshot' => $normalized,
                        'impact_snapshot' => [
                            'changed_section_ids' => $changedSectionIds->all(),
                            'affected_registration_case_ids' => $affectedCaseIds,
                            'affected_count' => count($affectedCaseIds),
                            'has_time_change' => $hasTimeChange,
                        ],
                        'content_hash' => $hash,
                        'authority_reference' => $authority,
                        'reason' => $reason,
                        'prepared_by' => $actor->id,
                        'prepared_at' => now(),
                    ]);

                    $impactReviews = app(RecordRegistrationSourceImpactReview::class);
                    foreach ($affectedCaseIds as $caseId) {
                        $case = Enrollment::query()->findOrFail($caseId);
                        $impactReviews->open(
                            $case,
                            $actor,
                            RecordRegistrationSourceImpactReview::SourceTimetableRevision,
                            $this->impactGuard->sourceReference($revision),
                            'Resolve the exact placement and official-registration impact before this timetable revision can be published: '.$reason,
                        );
                    }

                    return $revision->refresh();
                }, attempts: 5);
            }
        }

        if ($pendingDraftBlocksPublication && $existingDraft instanceof TimetableRevision) {
            // Throws the canonical readiness exception without mutating FacultyAvailabilityDeclaration.
            DB::transaction(fn (): TimetableRevision => $this->impactGuard->assertReady($existingDraft, $currentVersion, $canonicalChanges));
        }

        return DB::transaction(function () use (
            $run,
            $actor,
            $changeType,
            $sanitizedChanges,
            $canonicalChanges,
            $reason,
            $authorityReference,
            $authority,
            $resolvedScope,
            $resolvedAddCoFaculty,
            $reassignmentMetadata,
            $existingDraft,
        ): EloquentCollection {
            $lockedRun = $this->lockedPublishedRun($run);
            Gate::forUser($actor)->authorize('revise', SectionMeeting::class);
            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $lockedVersion = $this->canonicalVersionForRun($lockedRun, $actor, $timestamp);
            $term = Term::query()->findOrFail($lockedRun->term_id);

            $simulatedImpact = $this->impactService->lockForRevision(
                $lockedRun,
                $changeType,
                $sanitizedChanges,
                simulateAvailabilityExpansion: true,
            );
            $this->assertPasses($simulatedImpact);

            if ($existingDraft instanceof TimetableRevision) {
                $this->impactGuard->assertReady($existingDraft, $lockedVersion, $canonicalChanges);
            }

            $recorder = app(RecordFacultyAvailabilityDeclaration::class);
            foreach ($simulatedImpact->meetingChanges() as $change) {
                $new = $change['new'];
                $facultyId = (int) ($new['faculty_user_id'] ?? 0);
                if ($facultyId <= 0) {
                    continue;
                }
                $targetFaculty = User::query()->findOrFail($facultyId);
                $recorder->expandForScheduledSlot(
                    term: $term,
                    faculty: $targetFaculty,
                    actor: $actor,
                    dayOfWeek: (int) $new['day_of_week'],
                    startsAt: (string) $new['starts_at'],
                    endsAt: (string) $new['ends_at'],
                    reason: $reason,
                    context: [
                        'source' => 'published_schedule_revision',
                        'schedule_run_id' => (int) $lockedRun->id,
                        'section_meeting_id' => (int) $change['section_meeting_id'],
                    ],
                );
            }

            $preparedRevision = $this->impactGuard->prepare($lockedVersion, $actor, $canonicalChanges, $authority, $reason);
            $this->impactGuard->assertReady($preparedRevision, $lockedVersion, $canonicalChanges);

            // Re-run full hard-constraint validation against live DB with expansion simulation OFF.
            $impact = $this->impactService->lockForRevision(
                $lockedRun,
                $changeType,
                $sanitizedChanges,
                simulateAvailabilityExpansion: false,
            );
            $this->assertPasses($impact);

            $events = $this->applyMeetingChanges($lockedRun, $actor, $reason, $timestamp, $impact, $authorityReference, $preparedRevision, $reassignmentMetadata);

            if ($changeType === ScheduleRevisionEvent::ChangeFacultyReassignment) {
                $this->applyFacultyReassignmentScope(
                    actor: $actor,
                    impact: $impact,
                    scope: $resolvedScope ?? PublishedScheduleRevisionForm::ScopeTemporarySubstitute,
                    addCoFaculty: $resolvedAddCoFaculty,
                    teachingAuthority: $authority ?? $reason,
                );
            }

            $this->recordActivity(
                run: $lockedRun,
                actor: $actor,
                reason: $reason,
                timestamp: $timestamp,
                impact: $impact,
                events: $events,
                event: 'published_schedule_revised',
                description: 'Published timetable successor created with monotonic faculty availability expansion.',
                reassignmentScope: $resolvedScope,
                addCoFaculty: $resolvedAddCoFaculty,
            );
            DB::afterCommit(fn () => $this->notificationService->recordAndQueue($events));

            return $events;
        }, attempts: 5);
    }

    /**
     * @param  array<int, array<string, mixed>>  $canonicalChanges
     */
    private function expandAvailabilityForCanonicalChanges(
        Term $term,
        ScheduleGenerationRun $run,
        PublishedTimetableVersion $currentVersion,
        array $canonicalChanges,
        User $actor,
        string $reason,
    ): void {
        $recorder = app(RecordFacultyAvailabilityDeclaration::class);
        foreach ($canonicalChanges as $publishedMeetingId => $change) {
            if (! is_array($change) || ($change['remove'] ?? false) === true) {
                continue;
            }
            $canonical = $currentVersion->meetings->firstWhere('id', (int) $publishedMeetingId);
            if (! $canonical instanceof PublishedTimetableMeeting) {
                continue;
            }

            $facultyId = (int) ($change['faculty_user_id'] ?? $canonical->faculty_user_id);
            if ($facultyId <= 0) {
                continue;
            }

            $targetFaculty = User::query()->findOrFail($facultyId);
            $recorder->expandForScheduledSlot(
                term: $term,
                faculty: $targetFaculty,
                actor: $actor,
                dayOfWeek: (int) ($change['day_of_week'] ?? $canonical->day_of_week),
                startsAt: (string) ($change['starts_at'] ?? $canonical->starts_at),
                endsAt: (string) ($change['ends_at'] ?? $canonical->ends_at),
                reason: $reason,
                context: [
                    'source' => 'published_schedule_revision',
                    'schedule_run_id' => (int) $run->id,
                    'published_timetable_meeting_id' => (int) $canonical->id,
                ],
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $changes
     * @return array<int, array<string, mixed>>
     */
    private function normalizedCanonicalChanges(array $changes): array
    {
        $normalized = [];
        foreach ($changes as $meetingId => $change) {
            $normalized[(int) $meetingId] = collect($change)->sortKeys()->all();
        }
        ksort($normalized);

        return $normalized;
    }

    /** @param array<int, array<string, mixed>> $normalized */
    private function canonicalContentHash(PublishedTimetableVersion $current, array $normalized): string
    {
        return hash('sha256', json_encode([
            'source_version_id' => (int) $current->id,
            'changes' => $normalized,
        ], JSON_THROW_ON_ERROR));
    }

    private function applyFacultyReassignmentScope(
        User $actor,
        ScheduleRevisionImpact $impact,
        string $scope,
        bool $addCoFaculty,
        string $teachingAuthority,
    ): void {
        if ($scope === PublishedScheduleRevisionForm::ScopeTemporarySubstitute && ! $addCoFaculty) {
            return;
        }

        $processed = [];

        foreach ($impact->meetingChanges() as $change) {
            $meetingId = (int) ($change['section_meeting_id'] ?? 0);
            $facultyId = (int) ($change['new']['faculty_user_id'] ?? 0);
            $meeting = SectionMeeting::query()
                ->with('schedulingDemand.sectionDeliveryGroup.section')
                ->findOrFail($meetingId);
            $section = $meeting->schedulingDemand?->sectionDeliveryGroup?->section;
            $faculty = User::query()->findOrFail($facultyId);

            if (! $section instanceof Section) {
                continue;
            }

            $key = $section->id.'|'.$faculty->id;

            if (isset($processed[$key])) {
                continue;
            }

            $processed[$key] = true;

            if ($scope === PublishedScheduleRevisionForm::ScopePermanentTakeover) {
                app(ManageTeachingAssignment::class)->designate($section, $faculty, $actor, $teachingAuthority);

                if (! GradeRoster::query()->where('section_id', $section->id)->exists()
                    && CourseEnrollment::query()
                        ->where('term_offering_id', $section->term_offering_id)
                        ->where('section_id', $section->id)
                        ->where('status', CourseEnrollment::StatusActive)
                        ->where('is_current', true)
                        ->whereHas('enrollment', fn ($query) => $query
                            ->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled))
                        ->exists()) {
                    app(SynchronizeOfficialGradeRoster::class)->execute($section, $actor);
                }

                continue;
            }

            if ($addCoFaculty) {
                $isAlreadyDesignated = ClassOfferingTeachingAssignment::query()
                    ->where('section_id', $section->id)
                    ->where('role', ClassOfferingTeachingAssignment::RoleDesignated)
                    ->where('state', ClassOfferingTeachingAssignment::StateActive)
                    ->where('faculty_user_id', $faculty->id)
                    ->exists();

                if (! $isAlreadyDesignated) {
                    app(ManageTeachingAssignment::class)->addCoFaculty($section, $faculty, $actor, $teachingAuthority);
                }
            }
        }
    }

    /**
     * @param  EloquentCollection<int, ScheduleRevisionEvent>  $events
     */
    private function recordActivity(
        ScheduleGenerationRun $run,
        User $actor,
        string $reason,
        CarbonImmutable $timestamp,
        ScheduleRevisionImpact $impact,
        EloquentCollection $events,
        string $event,
        string $description,
        ?int $sectionId = null,
        ?string $reassignmentScope = null,
        bool $addCoFaculty = false,
    ): void {
        $properties = [
            'term_id' => (int) $run->term_id,
            'schedule_run_id' => (int) $run->id,
            'section_id' => $sectionId,
            'change_type' => $impact->changeType(),
            'reason' => $reason,
            'effective_date' => $timestamp->toDateString(),
            'schedule_revision_event_ids' => $events->modelKeys(),
            'section_meeting_ids' => collect($impact->meetingChanges())->pluck('section_meeting_id')->all(),
            'impact' => $impact->toArray(),
        ];

        if ($reassignmentScope !== null) {
            $properties['reassignment_scope'] = $reassignmentScope;
            $properties['add_co_faculty'] = $addCoFaculty;
        }

        DB::table('activity_log')->insert([
            'log_name' => 'scheduling',
            'description' => $description,
            'subject_type' => ScheduleGenerationRun::class,
            'subject_id' => $run->id,
            'event' => $event,
            'causer_type' => User::class,
            'causer_id' => $actor->id,
            'properties' => json_encode($properties, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }
}
