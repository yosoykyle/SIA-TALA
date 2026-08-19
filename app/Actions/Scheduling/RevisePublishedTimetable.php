<?php

namespace App\Actions\Scheduling;

use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionMeeting;
use App\Models\StudentScheduleBinding;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RevisePublishedTimetable
{
    public function __construct(private readonly ScheduleAssignmentRevalidationService $revalidator) {}

    /**
     * @param  array<int, array{faculty_user_id?: int, room_id?: int|null, day_of_week?: int, starts_at?: string, ends_at?: string, modality?: string, location_label?: string, remove?: bool}>  $changesByMeetingId
     */
    public function execute(
        PublishedTimetableVersion $current,
        User $actor,
        array $changesByMeetingId,
        string $authorityReference,
        string $reason,
        bool $allowSectionCancellation = false,
    ): PublishedTimetableVersion {
        Gate::forUser($actor)->authorize('revise', SectionMeeting::class);

        return DB::transaction(function () use ($current, $actor, $changesByMeetingId, $authorityReference, $reason, $allowSectionCancellation): PublishedTimetableVersion {
            Term::query()->whereKey($current->term_id)->lockForUpdate()->firstOrFail();
            $locked = PublishedTimetableVersion::query()->whereKey($current)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('revise', SectionMeeting::class);

            if ($locked->state !== PublishedTimetableVersion::StatePublished) {
                throw ValidationException::withMessages(['timetable_version' => 'Only the current Published Timetable Version can be revised.']);
            }

            $authorityReference = trim($authorityReference);
            $reason = trim($reason);

            if ($changesByMeetingId === [] || $authorityReference === '' || $reason === '') {
                throw ValidationException::withMessages(['revision' => 'A changed meeting, external authority reference, and reason are required.']);
            }

            $meetings = PublishedTimetableMeeting::query()
                ->where('published_timetable_version_id', $locked->id)
                ->with('classOffering.cohorts')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if (array_diff(array_map('intval', array_keys($changesByMeetingId)), $meetings->modelKeys()) !== []) {
                throw ValidationException::withMessages(['revision' => 'A requested meeting does not belong to the current version.']);
            }

            if (! $allowSectionCancellation && collect($changesByMeetingId)->contains(
                fn (array $change): bool => ($change['remove'] ?? false) === true,
            )) {
                throw ValidationException::withMessages(['revision' => 'Meeting removal is allowed only through the authorized whole-Section cancellation workflow.']);
            }

            $payloads = $meetings->map(function (PublishedTimetableMeeting $meeting) use ($changesByMeetingId): array {
                $change = $changesByMeetingId[(int) $meeting->id] ?? [];

                return array_replace($meeting->only([
                    'section_id', 'scheduling_demand_id', 'faculty_user_id', 'room_id',
                    'meeting_sequence', 'day_of_week', 'starts_at', 'ends_at', 'modality', 'location_label',
                ]), $change);
            })->reject(fn (array $payload): bool => ($payload['remove'] ?? false) === true)
                ->map(fn (array $payload): array => collect($payload)->except('remove')->all())
                ->values();

            $sourceRun = ScheduleGenerationRun::query()->whereKey($locked->schedule_run_id)->lockForUpdate()->firstOrFail();
            $this->assertConflictFree($payloads->all());

            if ($sourceRun->contract_version === ScheduleGenerationRun::ContractVersion) {
                $excludedMeetingIds = SectionMeeting::query()
                    ->where('schedule_run_id', $sourceRun->id)
                    ->lockForUpdate()
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();
                $excludedDemandIds = $meetings
                    ->filter(fn (PublishedTimetableMeeting $meeting): bool => ($changesByMeetingId[(int) $meeting->id]['remove'] ?? false) === true)
                    ->pluck('scheduling_demand_id')
                    ->filter()
                    ->map(fn (mixed $id): int => (int) $id)
                    ->values()
                    ->all();
                $validation = $this->revalidator->validateLiveAssignments(
                    $sourceRun,
                    $payloads->map(fn (array $payload): array => [
                        ...$payload,
                        'term_id' => (int) $locked->term_id,
                        'status' => 'ok',
                    ])->all(),
                    $excludedMeetingIds,
                    $excludedDemandIds,
                );

                if (! $validation->passes()) {
                    throw ValidationException::withMessages([
                        'revision' => $validation->blockingFindings()[0]['message'] ?? 'The proposed successor violates a current hard scheduling rule.',
                    ]);
                }
            }

            $nextVersion = ((int) PublishedTimetableVersion::query()->where('term_id', $locked->term_id)->lockForUpdate()->max('version')) + 1;
            $contentHash = hash('sha256', json_encode([
                'term_id' => (int) $locked->term_id,
                'version' => $nextVersion,
                'meetings' => $payloads->all(),
            ], JSON_THROW_ON_ERROR));
            $successorRun = $sourceRun->replicate();
            $successorRun->forceFill([
                'status' => ScheduleGenerationRun::StatusPublished,
                'input_hash' => hash('sha256', $sourceRun->input_hash.'|revision|'.$contentHash),
                'candidate_key' => null,
                'candidate_version' => ((int) $sourceRun->candidate_version) + 1,
                'candidate_state' => 'PublishedRevision',
                'candidate_reviewed_by' => $actor->id,
                'candidate_reviewed_at' => now(),
                'candidate_review_reason' => $reason,
                'published_by' => $actor->id,
                'published_at' => now(),
                'publication_version' => $nextVersion,
                'publication_note' => $reason,
            ])->save();
            $successor = PublishedTimetableVersion::query()->create([
                'term_id' => $locked->term_id,
                'schedule_run_id' => $successorRun->id,
                'supersedes_version_id' => $locked->id,
                'version' => $nextVersion,
                'state' => PublishedTimetableVersion::StatePublished,
                'authority_reference' => $authorityReference,
                'publication_reason' => $reason,
                'source_versions' => $locked->source_versions,
                'impact_summary' => [
                    'changed_meeting_ids' => array_map('intval', array_keys($changesByMeetingId)),
                    'changed_count' => count($changesByMeetingId),
                ],
                'content_hash' => $contentHash,
                'published_by' => $actor->id,
                'published_at' => now(),
            ]);

            foreach ($meetings as $meeting) {
                if (($changesByMeetingId[(int) $meeting->id]['remove'] ?? false) === true) {
                    continue;
                }

                $payload = $payloads->first(
                    fn (array $candidate): bool => (int) $candidate['section_id'] === (int) $meeting->section_id
                        && (int) $candidate['meeting_sequence'] === (int) $meeting->meeting_sequence,
                );
                $successorMeeting = PublishedTimetableMeeting::query()->create([
                    ...$payload,
                    'published_timetable_version_id' => $successor->id,
                    'supersedes_meeting_id' => $meeting->id,
                ]);

                if ($successorMeeting->scheduling_demand_id !== null) {
                    $legacyMeeting = SectionMeeting::query()
                        ->where('schedule_run_id', $sourceRun->id)
                        ->where('scheduling_demand_id', $successorMeeting->scheduling_demand_id)
                        ->where('meeting_sequence', $successorMeeting->meeting_sequence)
                        ->lockForUpdate()
                        ->first();
                    $projectedMeeting = SectionMeeting::query()->create([
                        'schedule_run_id' => $successorRun->id,
                        'published_timetable_version_id' => $successor->id,
                        'scheduling_demand_id' => $successorMeeting->scheduling_demand_id,
                        'meeting_sequence' => $successorMeeting->meeting_sequence,
                        'faculty_user_id' => $successorMeeting->faculty_user_id,
                        'room_id' => $successorMeeting->room_id,
                        'day_of_week' => $successorMeeting->day_of_week,
                        'starts_at' => $successorMeeting->starts_at,
                        'ends_at' => $successorMeeting->ends_at,
                        'modality' => $successorMeeting->modality,
                        'state' => SectionMeeting::StateActive,
                        'published_at' => now(),
                    ]);

                    if ($legacyMeeting instanceof SectionMeeting) {
                        $this->carryStudentBindings(
                            $legacyMeeting,
                            $projectedMeeting,
                            $actor,
                            $reason,
                        );
                    }
                }
            }

            $locked->forceFill(['state' => PublishedTimetableVersion::StateSuperseded])->save();
            $sourceRun->forceFill(['status' => ScheduleGenerationRun::StatusSuperseded])->save();

            return $successor->fresh('meetings');
        }, attempts: 5);
    }

    private function carryStudentBindings(
        SectionMeeting $source,
        SectionMeeting $successor,
        User $actor,
        string $reason,
    ): void {
        $timestamp = now();
        $bindings = StudentScheduleBinding::query()
            ->where('section_meeting_id', $source->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($bindings as $binding) {
            $binding->forceFill([
                'is_active' => false,
                'effective_until' => $timestamp->toDateString(),
                'released_by' => $actor->id,
                'released_at' => $timestamp,
                'release_reason' => 'Superseded by immutable timetable revision: '.$reason,
            ])->save();
            StudentScheduleBinding::query()->firstOrCreate(
                [
                    'course_enrollment_id' => $binding->course_enrollment_id,
                    'section_meeting_id' => $successor->id,
                ],
                [
                    'is_active' => true,
                    'effective_from' => $timestamp->toDateString(),
                    'effective_until' => null,
                    'source' => $binding->source,
                    'released_by' => null,
                    'released_at' => null,
                    'release_reason' => null,
                ],
            );
        }
    }

    /** @param list<array<string, mixed>> $meetings */
    private function assertConflictFree(array $meetings): void
    {
        foreach ($meetings as $index => $meeting) {
            if ((int) $meeting['day_of_week'] < 1 || (int) $meeting['day_of_week'] > 7
                || (string) $meeting['starts_at'] >= (string) $meeting['ends_at']) {
                throw ValidationException::withMessages(['revision' => 'Every successor meeting needs a valid day and increasing time bounds.']);
            }

            foreach (array_slice($meetings, $index + 1) as $other) {
                $overlaps = (int) $meeting['day_of_week'] === (int) $other['day_of_week']
                    && (string) $meeting['starts_at'] < (string) $other['ends_at']
                    && (string) $other['starts_at'] < (string) $meeting['ends_at'];
                $sameFaculty = (int) $meeting['faculty_user_id'] === (int) $other['faculty_user_id'];
                $sameRoom = $meeting['room_id'] !== null && (int) $meeting['room_id'] === (int) $other['room_id'];

                if ($overlaps && ($sameFaculty || $sameRoom)) {
                    throw ValidationException::withMessages(['revision' => 'The complete successor timetable contains a Faculty or room overlap.']);
                }
            }
        }
    }
}
