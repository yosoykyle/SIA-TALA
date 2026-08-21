<?php

namespace App\Actions\Scheduling;

use App\Actions\Enrollment\RecordRegistrationSourceImpactReview;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationCaseEvent;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TimetableRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TimetableRevisionImpactGuard
{
    public function __construct(
        private readonly ScheduleAssignmentRevalidationService $revalidator,
        private readonly RecordRegistrationSourceImpactReview $impactReviews,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $changesByMeetingId
     */
    public function prepare(
        PublishedTimetableVersion $current,
        User $actor,
        array $changesByMeetingId,
        string $authorityReference,
        string $reason,
        bool $allowSectionCancellation = false,
    ): TimetableRevision {
        Gate::forUser($actor)->authorize('revise', SectionMeeting::class);

        return DB::transaction(function () use ($current, $actor, $changesByMeetingId, $authorityReference, $reason, $allowSectionCancellation): TimetableRevision {
            Term::query()->whereKey($current->term_id)->lockForUpdate()->firstOrFail();
            $locked = PublishedTimetableVersion::query()->whereKey($current->id)->lockForUpdate()->firstOrFail();
            $authorityReference = trim($authorityReference);
            $reason = trim($reason);
            $normalized = $this->normalizedChanges($changesByMeetingId);

            if ($locked->state !== PublishedTimetableVersion::StatePublished
                || $normalized === [] || $authorityReference === '' || $reason === '') {
                throw ValidationException::withMessages(['revision' => 'A current Published Timetable, changed meeting, authority reference, and reason are required.']);
            }
            if (! $allowSectionCancellation && collect($normalized)->contains(fn (array $change): bool => ($change['remove'] ?? false) === true)) {
                throw ValidationException::withMessages(['revision' => 'Meeting removal requires the authorized whole-Section cancellation path.']);
            }

            $meetings = PublishedTimetableMeeting::query()
                ->where('published_timetable_version_id', $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if (array_diff(array_keys($normalized), $meetings->modelKeys()) !== []) {
                throw ValidationException::withMessages(['revision' => 'Every changed meeting must belong to the current Published Timetable Version.']);
            }

            $payloads = $this->proposedPayloads($meetings, $normalized);
            $this->assertConflictFree($payloads);
            $this->assertCurrentHardRules($locked, $meetings, $payloads, $normalized);
            $hash = $this->contentHash($locked, $normalized);
            $existing = TimetableRevision::query()
                ->where('source_version_id', $locked->id)
                ->where('content_hash', $hash)
                ->first();
            if ($existing instanceof TimetableRevision) {
                return $existing;
            }

            $changedSectionIds = $meetings
                ->filter(fn (PublishedTimetableMeeting $meeting): bool => array_key_exists((int) $meeting->id, $normalized))
                ->pluck('section_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values();
            $affectedCaseIds = $this->affectedCaseIds($changedSectionIds->all());
            $revision = TimetableRevision::query()->create([
                'term_id' => $locked->term_id,
                'source_version_id' => $locked->id,
                'state' => TimetableRevision::StateDraft,
                'change_type' => $allowSectionCancellation ? 'SectionCancellation' : 'TargetedRevision',
                'changes_snapshot' => $normalized,
                'impact_snapshot' => [
                    'changed_section_ids' => $changedSectionIds->all(),
                    'affected_registration_case_ids' => $affectedCaseIds,
                    'affected_count' => count($affectedCaseIds),
                ],
                'content_hash' => $hash,
                'authority_reference' => $authorityReference,
                'reason' => $reason,
                'prepared_by' => $actor->id,
                'prepared_at' => now(),
            ]);

            foreach ($affectedCaseIds as $caseId) {
                $case = Enrollment::query()->findOrFail($caseId);
                $this->impactReviews->open(
                    $case,
                    $actor,
                    RecordRegistrationSourceImpactReview::SourceTimetableRevision,
                    $this->sourceReference($revision),
                    'Resolve the exact placement and official-registration impact before this timetable revision can be published: '.$reason,
                );
            }

            return $revision->refresh();
        }, attempts: 5);
    }

    /**
     * @param  array<int, array<string, mixed>>  $changesByMeetingId
     */
    public function assertReady(
        TimetableRevision $revision,
        PublishedTimetableVersion $current,
        array $changesByMeetingId,
    ): TimetableRevision {
        $locked = TimetableRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();
        if ($locked->state !== TimetableRevision::StateDraft
            || (int) $locked->source_version_id !== (int) $current->id
            || $locked->content_hash !== $this->contentHash($current, $this->normalizedChanges($changesByMeetingId))) {
            throw ValidationException::withMessages(['revision' => 'The prepared Timetable Revision is stale or does not match this exact change.']);
        }

        $expectedCaseIds = collect($locked->impact_snapshot['affected_registration_case_ids'] ?? [])->map(fn (mixed $id): int => (int) $id)->sort()->values();
        $currentCaseIds = collect($this->affectedCaseIds($locked->impact_snapshot['changed_section_ids'] ?? []))->sort()->values();
        if ($expectedCaseIds->all() !== $currentCaseIds->all()) {
            throw ValidationException::withMessages(['revision' => 'Clinic 4 placement impacts changed after preparation. Prepare a fresh revision preview.']);
        }

        $sourceReference = $this->sourceReference($locked);
        $unresolved = $expectedCaseIds->filter(function (int $caseId) use ($sourceReference): bool {
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
        if ($unresolved->isNotEmpty()) {
            throw ValidationException::withMessages([
                'revision' => 'Resolve every affected Clinic 4 placement or official-registration review before publishing this timetable revision.',
            ]);
        }

        return $locked;
    }

    public function markPublished(TimetableRevision $revision, PublishedTimetableVersion $successor, User $actor): void
    {
        $revision->update([
            'state' => TimetableRevision::StatePublished,
            'successor_version_id' => $successor->id,
            'published_by' => $actor->id,
            'published_at' => now(),
        ]);
    }

    public function sourceReference(TimetableRevision $revision): string
    {
        return 'timetable-revision:'.$revision->id;
    }

    /** @param array<int, array<string, mixed>> $changes */
    private function normalizedChanges(array $changes): array
    {
        $normalized = [];
        foreach ($changes as $meetingId => $change) {
            $normalized[(int) $meetingId] = collect($change)->sortKeys()->all();
        }
        ksort($normalized);

        return $normalized;
    }

    /** @param array<int, array<string, mixed>> $changes */
    private function contentHash(PublishedTimetableVersion $current, array $changes): string
    {
        return hash('sha256', json_encode([
            'source_version_id' => (int) $current->id,
            'changes' => $changes,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  Collection<int, PublishedTimetableMeeting>  $meetings
     * @param  array<int, array<string, mixed>>  $changes
     * @return list<array<string, mixed>>
     */
    private function proposedPayloads($meetings, array $changes): array
    {
        return $meetings->map(function (PublishedTimetableMeeting $meeting) use ($changes): array {
            return array_replace($meeting->only([
                'section_id', 'scheduling_demand_id', 'faculty_user_id', 'room_id',
                'meeting_sequence', 'day_of_week', 'starts_at', 'ends_at', 'modality', 'location_label',
            ]), $changes[(int) $meeting->id] ?? []);
        })->reject(fn (array $payload): bool => ($payload['remove'] ?? false) === true)
            ->map(fn (array $payload): array => collect($payload)->except('remove')->all())
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, PublishedTimetableMeeting>  $meetings
     * @param  list<array<string, mixed>>  $payloads
     * @param  array<int, array<string, mixed>>  $changes
     */
    private function assertCurrentHardRules(PublishedTimetableVersion $current, $meetings, array $payloads, array $changes): void
    {
        $sourceRun = ScheduleGenerationRun::query()->whereKey($current->schedule_run_id)->lockForUpdate()->firstOrFail();
        if ($sourceRun->contract_version !== ScheduleGenerationRun::ContractVersion) {
            return;
        }

        $excludedMeetingIds = SectionMeeting::query()->where('schedule_run_id', $sourceRun->id)->lockForUpdate()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $excludedDemandIds = $meetings
            ->filter(fn (PublishedTimetableMeeting $meeting): bool => ($changes[(int) $meeting->id]['remove'] ?? false) === true)
            ->pluck('scheduling_demand_id')->filter()->map(fn (mixed $id): int => (int) $id)->values()->all();
        $validation = $this->revalidator->validateLiveAssignments(
            $sourceRun,
            collect($payloads)->map(fn (array $payload): array => [...$payload, 'term_id' => (int) $current->term_id, 'status' => 'ok'])->all(),
            $excludedMeetingIds,
            $excludedDemandIds,
        );
        if (! $validation->passes()) {
            throw ValidationException::withMessages([
                'revision' => $validation->blockingFindings()[0]['message'] ?? 'The prepared revision violates a current hard scheduling rule.',
            ]);
        }
    }

    /** @param list<array<string, mixed>> $meetings */
    private function assertConflictFree(array $meetings): void
    {
        foreach ($meetings as $index => $meeting) {
            if ((int) $meeting['day_of_week'] < 1 || (int) $meeting['day_of_week'] > 7
                || (string) $meeting['starts_at'] >= (string) $meeting['ends_at']) {
                throw ValidationException::withMessages(['revision' => 'Every prepared meeting needs a valid day and increasing time bounds.']);
            }
            foreach (array_slice($meetings, $index + 1) as $other) {
                $overlaps = (int) $meeting['day_of_week'] === (int) $other['day_of_week']
                    && (string) $meeting['starts_at'] < (string) $other['ends_at']
                    && (string) $other['starts_at'] < (string) $meeting['ends_at'];
                $sameFaculty = (int) $meeting['faculty_user_id'] === (int) $other['faculty_user_id'];
                $sameRoom = $meeting['room_id'] !== null && (int) $meeting['room_id'] === (int) $other['room_id'];
                if ($overlaps && ($sameFaculty || $sameRoom)) {
                    throw ValidationException::withMessages(['revision' => 'The prepared successor contains a Faculty or room overlap.']);
                }
            }
        }
    }

    /** @param list<int> $sectionIds @return list<int> */
    private function affectedCaseIds(array $sectionIds): array
    {
        $official = CourseEnrollment::query()
            ->whereIn('section_id', $sectionIds)
            ->where('is_current', true)
            ->where('status', CourseEnrollment::StatusActive)
            ->whereHas('enrollment', fn ($query) => $query->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled))
            ->pluck('enrollment_id');
        $inProgress = Enrollment::query()
            ->where('canonical_outcome', Enrollment::OutcomeInProgress)
            ->whereHas('currentProposalVersion.items', fn ($query) => $query->whereIn('section_id', $sectionIds))
            ->pluck('id');

        return $official->merge($inProgress)->map(fn (mixed $id): int => (int) $id)->unique()->sort()->values()->all();
    }
}
