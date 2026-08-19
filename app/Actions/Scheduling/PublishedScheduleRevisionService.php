<?php

namespace App\Actions\Scheduling;

use App\Models\PublishedTimetableVersion;
use App\Models\ScheduleGenerationRun;
use App\Models\ScheduleRevisionEvent;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\Term;
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
    ): EloquentCollection {
        Gate::forUser($actor)->authorize('revise', SectionMeeting::class);
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($run, $actor, $changeType, $changes, $reason, $authorityReference): EloquentCollection {
            $lockedRun = $this->lockedPublishedRun($run);
            Gate::forUser($actor)->authorize('revise', SectionMeeting::class);
            $impact = $this->impactService->lockForRevision($lockedRun, $changeType, $changes);
            $this->assertPasses($impact);
            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $events = $this->applyMeetingChanges($lockedRun, $actor, $reason, $timestamp, $impact, $authorityReference);

            $this->recordActivity(
                run: $lockedRun,
                actor: $actor,
                reason: $reason,
                timestamp: $timestamp,
                impact: $impact,
                events: $events,
                event: 'published_schedule_revised',
                description: 'Published timetable successor created.',
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
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($run, $section, $actor, $reason, $authorityReference): EloquentCollection {
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
            $events = $this->applyMeetingChanges($lockedRun, $actor, $reason, $timestamp, $impact, $authorityReference);

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

    private function lockedPublishedRun(ScheduleGenerationRun $run): ScheduleGenerationRun
    {
        Term::query()
            ->whereKey($run->term_id)
            ->lockForUpdate()
            ->firstOrFail();
        $termRuns = ScheduleGenerationRun::query()
            ->where('term_id', $run->term_id)
            ->orderBy('id')
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

    /** @return EloquentCollection<int, ScheduleRevisionEvent> */
    private function applyMeetingChanges(
        ScheduleGenerationRun $run,
        User $actor,
        string $reason,
        CarbonImmutable $timestamp,
        ScheduleRevisionImpact $impact,
        ?string $authorityReference = null,
    ): EloquentCollection {
        $currentVersion = $this->canonicalVersionForRun($run, $actor, $timestamp);
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
            ];
        }

        $authority = trim((string) $authorityReference);

        if ($authority === '') {
            if ($run->contract_version === ScheduleGenerationRun::ContractVersion) {
                throw ValidationException::withMessages([
                    'authority_reference' => 'Recorded external revision sign-off is required.',
                ]);
            }

            $authority = "Legacy revision authority for run {$run->id}";
        }

        $successor = $this->reviseTimetable->execute(
            $currentVersion,
            $actor,
            $canonicalChanges,
            $authority,
            $reason,
            $impact->changeType() === ScheduleRevisionEvent::ChangeSectionCancellation,
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
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'scheduling',
            'description' => $description,
            'subject_type' => ScheduleGenerationRun::class,
            'subject_id' => $run->id,
            'event' => $event,
            'causer_type' => User::class,
            'causer_id' => $actor->id,
            'properties' => json_encode([
                'term_id' => (int) $run->term_id,
                'schedule_run_id' => (int) $run->id,
                'section_id' => $sectionId,
                'change_type' => $impact->changeType(),
                'reason' => $reason,
                'effective_date' => $timestamp->toDateString(),
                'schedule_revision_event_ids' => $events->modelKeys(),
                'section_meeting_ids' => collect($impact->meetingChanges())->pluck('section_meeting_id')->all(),
                'impact' => $impact->toArray(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }
}
