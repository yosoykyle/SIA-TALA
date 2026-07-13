<?php

namespace App\Actions\Scheduling;

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
    ): EloquentCollection {
        Gate::forUser($actor)->authorize('revise', SectionMeeting::class);
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($run, $actor, $changeType, $changes, $reason): EloquentCollection {
            $lockedRun = $this->lockedPublishedRun($run);
            Gate::forUser($actor)->authorize('revise', SectionMeeting::class);
            $impact = $this->impactService->lockForRevision($lockedRun, $changeType, $changes);
            $this->assertPasses($impact);
            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $events = $this->applyMeetingChanges($lockedRun, $actor, $reason, $timestamp, $impact);

            $this->recordActivity(
                run: $lockedRun,
                actor: $actor,
                reason: $reason,
                timestamp: $timestamp,
                impact: $impact,
                events: $events,
                event: 'published_schedule_revised',
                description: 'Published schedule revised in place.',
            );
            $this->notificationService->recordAndQueue($events);

            return $events;
        }, attempts: 5);
    }

    /** @return EloquentCollection<int, ScheduleRevisionEvent> */
    public function cancelSection(
        ScheduleGenerationRun $run,
        Section $section,
        User $actor,
        string $reason,
    ): EloquentCollection {
        Gate::forUser($actor)->authorize('revise', SectionMeeting::class);
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($run, $section, $actor, $reason): EloquentCollection {
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
            $events = $this->applyMeetingChanges($lockedRun, $actor, $reason, $timestamp, $impact);

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
            $this->notificationService->recordAndQueue($events);

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
    ): EloquentCollection {
        $events = [];

        foreach ($impact->meetingChanges() as $change) {
            $meeting = SectionMeeting::query()->findOrFail($change['section_meeting_id']);
            $new = $change['new'];
            $meeting->forceFill([
                'faculty_user_id' => $new['faculty_user_id'],
                'room_id' => $new['room_id'],
                'day_of_week' => $new['day_of_week'],
                'starts_at' => $new['starts_at'],
                'ends_at' => $new['ends_at'],
                'modality' => $new['modality'],
                'state' => $new['state'],
            ])->save();
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
