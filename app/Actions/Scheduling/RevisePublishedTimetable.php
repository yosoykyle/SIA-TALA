<?php

namespace App\Actions\Scheduling;

use App\Models\CorVersion;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationCaseEvent;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TimetableRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RevisePublishedTimetable
{
    public function __construct(
        private readonly ScheduleAssignmentRevalidationService $revalidator,
        private readonly TimetableRevisionImpactGuard $impactGuard,
    ) {}

    /**
     * @param  array<int, array{faculty_user_id?: int, room_id?: int|null, day_of_week?: int, starts_at?: string, ends_at?: string, modality?: string, location_label?: string, remove?: bool}>  $changesByMeetingId
     */
    public function execute(
        PublishedTimetableVersion $current,
        User $actor,
        array $changesByMeetingId,
        ?string $authorityReference,
        string $reason,
        bool $allowSectionCancellation = false,
        ?TimetableRevision $preparedRevision = null,
    ): PublishedTimetableVersion {
        Gate::forUser($actor)->authorize('revise', SectionMeeting::class);
        $preparedRevision ??= $this->impactGuard->prepare(
            $current,
            $actor,
            $changesByMeetingId,
            $authorityReference,
            $reason,
            $allowSectionCancellation,
        );

        return DB::transaction(function () use ($current, $actor, $changesByMeetingId, $authorityReference, $reason, $allowSectionCancellation, $preparedRevision): PublishedTimetableVersion {
            Term::query()->whereKey($current->term_id)->lockForUpdate()->firstOrFail();
            $locked = PublishedTimetableVersion::query()->whereKey($current)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('revise', SectionMeeting::class);

            if ($locked->state !== PublishedTimetableVersion::StatePublished) {
                throw ValidationException::withMessages(['timetable_version' => 'Only the current Published Timetable Version can be revised.']);
            }

            $preparedRevision = $this->impactGuard->assertReady($preparedRevision, $locked, $changesByMeetingId);

            // Reassignment scope metadata stays on the Draft revision (and its content hash) only.
            $changesByMeetingId = array_map(
                fn (array $change): array => array_diff_key($change, ['reassignment_scope' => true, 'add_co_faculty' => true]),
                $changesByMeetingId,
            );

            $authorityReference = filled($authorityReference) ? trim($authorityReference) : null;
            $reason = trim($reason);

            if ($changesByMeetingId === [] || $reason === '') {
                throw ValidationException::withMessages(['revision' => 'A changed meeting and reason are required.']);
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
                $sourceSectionMeetings = SectionMeeting::query()
                    ->where('schedule_run_id', $sourceRun->id)
                    ->where('state', SectionMeeting::StateActive)
                    ->with('schedulingDemand.sectionDeliveryGroup')
                    ->lockForUpdate()
                    ->get();
                $sourceIdsByDemandSequence = $sourceSectionMeetings->mapWithKeys(
                    fn (SectionMeeting $sectionMeeting): array => [
                        ((int) $sectionMeeting->scheduling_demand_id).':'.((int) $sectionMeeting->meeting_sequence) => (int) $sectionMeeting->id,
                    ],
                );
                $sourceIdsBySectionSequence = $sourceSectionMeetings->mapWithKeys(
                    fn (SectionMeeting $sectionMeeting): array => [
                        ((int) ($sectionMeeting->schedulingDemand?->sectionDeliveryGroup?->section_id ?? 0)).':'.((int) $sectionMeeting->meeting_sequence) => (int) $sectionMeeting->id,
                    ],
                );
                $resolveSourceMeetingId = function (int $sectionId, ?int $demandId, int $sequence) use ($sourceIdsByDemandSequence, $sourceIdsBySectionSequence): ?int {
                    if ($demandId !== null && $demandId > 0) {
                        $matched = $sourceIdsByDemandSequence->get($demandId.':'.$sequence);
                        if ($matched !== null) {
                            return $matched;
                        }
                    }

                    return $sourceIdsBySectionSequence->get($sectionId.':'.$sequence);
                };

                $excludedMeetingIds = $meetings
                    ->filter(fn (PublishedTimetableMeeting $meeting): bool => array_key_exists((int) $meeting->id, $changesByMeetingId))
                    ->map(fn (PublishedTimetableMeeting $meeting): ?int => $resolveSourceMeetingId(
                        (int) $meeting->section_id,
                        $meeting->scheduling_demand_id !== null ? (int) $meeting->scheduling_demand_id : null,
                        (int) $meeting->meeting_sequence,
                    ))
                    ->filter()
                    ->unique()
                    ->values()
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
                    $payloads->map(function (array $payload) use ($locked, $resolveSourceMeetingId): array {
                        $sourceMeetingId = $resolveSourceMeetingId(
                            (int) $payload['section_id'],
                            isset($payload['scheduling_demand_id']) ? (int) $payload['scheduling_demand_id'] : null,
                            (int) $payload['meeting_sequence'],
                        );

                        return [
                            ...$payload,
                            'term_id' => (int) $locked->term_id,
                            'status' => 'ok',
                            'source_section_meeting_id' => $sourceMeetingId,
                        ];
                    })->all(),
                    $excludedMeetingIds,
                    $excludedDemandIds,
                );

                if (! $validation->passes()) {
                    throw ValidationException::withMessages([
                        'revision' => $validation->blockingFindings()[0]['message'] ?? 'The proposed successor violates a current hard scheduling rule.',
                    ]);
                }

                $this->assertNoPairwiseStudentConflict((int) $locked->term_id, $payloads->all());
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
                    'timetable_revision_id' => $preparedRevision->id,
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
                        && (int) ($candidate['scheduling_demand_id'] ?? 0) === (int) $meeting->scheduling_demand_id
                        && (int) $candidate['meeting_sequence'] === (int) $meeting->meeting_sequence,
                );
                if (is_array($payload)
                    && array_key_exists('room_id', $changesByMeetingId[(int) $meeting->id] ?? [])
                    && ! array_key_exists('location_label', $changesByMeetingId[(int) $meeting->id] ?? [])) {
                    $payload['location_label'] = ($payload['room_id'] ?? null) !== null
                        ? (Room::query()->whereKey($payload['room_id'])->value('name') ?? $payload['location_label'])
                        : (($payload['modality'] ?? null) === 'ONLINE' ? 'Online' : $payload['location_label']);
                }
                $successorMeeting = PublishedTimetableMeeting::query()->create([
                    ...$payload,
                    'published_timetable_version_id' => $successor->id,
                    'supersedes_meeting_id' => $meeting->id,
                ]);

                if ($successorMeeting->scheduling_demand_id !== null) {
                    SectionMeeting::query()->create([
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
                }
            }

            $locked->forceFill(['state' => PublishedTimetableVersion::StateSuperseded])->save();
            $sourceRun->forceFill(['status' => ScheduleGenerationRun::StatusSuperseded])->save();
            $this->impactGuard->markPublished($preparedRevision, $successor, $actor);

            $changedSectionIds = $meetings
                ->filter(fn (PublishedTimetableMeeting $meeting): bool => array_key_exists((int) $meeting->id, $changesByMeetingId)
                    && ($changesByMeetingId[(int) $meeting->id]['remove'] ?? false) !== true)
                ->pluck('section_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all();
            $this->reissueAffectedCorVersions($successor, $preparedRevision, $changedSectionIds, $actor, $reason);

            return $successor->fresh('meetings');
        }, attempts: 5);
    }

    /**
     * @param  list<int>  $changedSectionIds
     */
    private function reissueAffectedCorVersions(
        PublishedTimetableVersion $successor,
        TimetableRevision $preparedRevision,
        array $changedSectionIds,
        User $actor,
        string $reason,
    ): void {
        if ($changedSectionIds === []) {
            return;
        }

        $affectedEnrollments = Enrollment::query()
            ->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled)
            ->whereNotNull('current_cor_version_id')
            ->whereHas('courseEnrollments', fn ($query) => $query
                ->whereIn('section_id', $changedSectionIds)
                ->where('is_current', true)
                ->where('status', CourseEnrollment::StatusActive))
            ->orderBy('id')
            ->get();

        if ($affectedEnrollments->isEmpty()) {
            return;
        }

        $meetingsBySection = PublishedTimetableMeeting::query()
            ->with(['faculty', 'room'])
            ->where('published_timetable_version_id', $successor->id)
            ->whereIn('section_id', $changedSectionIds)
            ->orderBy('meeting_sequence')
            ->get()
            ->groupBy('section_id');
        $issuedAt = now();

        foreach ($affectedEnrollments as $enrollment) {
            $lockedEnrollment = Enrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $currentCor = CorVersion::query()->whereKey($lockedEnrollment->current_cor_version_id)->lockForUpdate()->first();
            if (! $currentCor instanceof CorVersion) {
                continue;
            }

            $activeChangedCourses = CourseEnrollment::query()
                ->where('enrollment_id', $lockedEnrollment->id)
                ->whereIn('section_id', $changedSectionIds)
                ->where('is_current', true)
                ->where('status', CourseEnrollment::StatusActive)
                ->get();
            $changedCourseEnrollmentIds = $activeChangedCourses->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            $changedSectionIdsForStudent = $activeChangedCourses->pluck('section_id')->map(fn (mixed $id): int => (int) $id)->all();

            $snapshot = $currentCor->snapshot;
            $snapshot['courses'] = collect($snapshot['courses'] ?? [])
                ->map(function (array $row) use ($changedCourseEnrollmentIds, $changedSectionIdsForStudent, $meetingsBySection): array {
                    $courseEnrollmentId = (int) ($row['course_enrollment_id'] ?? 0);
                    $sectionId = (int) ($row['section_id'] ?? 0);

                    if (! in_array($courseEnrollmentId, $changedCourseEnrollmentIds, true)
                        && ! in_array($sectionId, $changedSectionIdsForStudent, true)) {
                        return $row;
                    }

                    $sectionMeetings = $meetingsBySection->get($sectionId, collect());
                    $row['meetings'] = $sectionMeetings->map(fn (PublishedTimetableMeeting $meeting): array => [
                        'id' => $meeting->id,
                        'meeting_sequence' => $meeting->meeting_sequence,
                        'day_of_week' => $meeting->day_of_week,
                        'starts_at' => $meeting->starts_at,
                        'ends_at' => $meeting->ends_at,
                        'modality' => $meeting->modality,
                        'room_label' => $meeting->room !== null ? $meeting->room->name : $meeting->location_label,
                        'faculty_user_id' => $meeting->faculty_user_id,
                        'faculty_name' => $meeting->faculty?->getFilamentName(),
                        'room_id' => $meeting->room_id,
                    ])->all();

                    return $row;
                })
                ->values()
                ->all();

            $snapshot['published_timetable_version_id'] = $successor->id;
            $timetableRevisionChange = [
                'type' => 'TimetableRevision',
                'timetable_revision_id' => $preparedRevision->id,
                'published_timetable_version_id' => $successor->id,
                'reason' => $reason,
            ];
            $snapshot['timetable_revision'] = $timetableRevisionChange;
            $existingChange = $snapshot['change'] ?? null;
            $hasPendingNonTimetableChange = is_array($existingChange)
                && ($existingChange['type'] ?? null) !== 'TimetableRevision'
                && (($existingChange['type'] ?? null) !== null
                    || ($existingChange['finance_state'] ?? null) === 'AccountingReviewPending');
            if (! $hasPendingNonTimetableChange) {
                $snapshot['change'] = $timetableRevisionChange;
            }
            $snapshot['issued_by_user_id'] = $actor->id;
            $snapshot['issued_by_name'] = $actor->getFilamentName();
            $snapshot['issued_at'] = $issuedAt->toIso8601String();

            $nextVersion = ((int) $lockedEnrollment->corVersions()->lockForUpdate()->max('version')) + 1;
            $successorCor = CorVersion::query()->create([
                'enrollment_id' => $lockedEnrollment->id,
                'supersedes_version_id' => $currentCor->id,
                'version' => $nextVersion,
                'registration_proposal_version_id' => $currentCor->registration_proposal_version_id,
                'assessment_id' => $currentCor->assessment_id,
                'published_timetable_version_id' => $successor->id,
                'snapshot' => $snapshot,
                'content_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
                'issued_by' => $actor->id,
                'issued_at' => $issuedAt,
            ]);
            $lockedEnrollment->update([
                'current_cor_version_id' => $successorCor->id,
                'lock_version' => $lockedEnrollment->lock_version + 1,
            ]);

            RegistrationCaseEvent::query()->create([
                'enrollment_id' => $lockedEnrollment->id,
                'sequence' => ((int) $lockedEnrollment->registrationEvents()->lockForUpdate()->max('sequence')) + 1,
                'event_type' => 'CorReissuedForTimetableRevision',
                'from_outcome' => $lockedEnrollment->canonical_outcome,
                'to_outcome' => $lockedEnrollment->canonical_outcome,
                'reason' => 'Certificate of Registration automatically reissued for published timetable revision v'.$successor->version.'.',
                'authority_reference' => $this->impactGuard->sourceReference($preparedRevision),
                'actor_id' => $actor->id,
                'recorded_at' => $issuedAt,
            ]);
        }
    }

    /** @param list<array<string, mixed>> $payloads */
    private function assertNoPairwiseStudentConflict(int $termId, array $payloads): void
    {
        if (count($payloads) < 2) {
            return;
        }

        $sectionIds = collect($payloads)->pluck('section_id')->map(fn (mixed $id): int => (int) $id)->unique()->values();
        $studentsBySection = CourseEnrollment::query()
            ->whereIn('section_id', $sectionIds)
            ->where('status', CourseEnrollment::StatusActive)
            ->where('is_current', true)
            ->whereHas('enrollment', fn ($query) => $query->where('term_id', $termId))
            ->with('enrollment:id,credential_user_id')
            ->get()
            ->groupBy('section_id')
            ->map(fn ($rows): array => $rows
                ->map(fn (CourseEnrollment $row): int => (int) ($row->enrollment?->credential_user_id ?? 0))
                ->filter()
                ->unique()
                ->values()
                ->all());

        foreach ($payloads as $index => $meeting) {
            $leftStudents = $studentsBySection->get((int) $meeting['section_id'], []);
            if ($leftStudents === []) {
                continue;
            }

            foreach (array_slice($payloads, $index + 1) as $other) {
                if ((int) $meeting['section_id'] === (int) $other['section_id']) {
                    continue;
                }

                $overlaps = (int) $meeting['day_of_week'] === (int) $other['day_of_week']
                    && $this->normalizeTime($meeting['starts_at']) < $this->normalizeTime($other['ends_at'])
                    && $this->normalizeTime($other['starts_at']) < $this->normalizeTime($meeting['ends_at']);

                if (! $overlaps) {
                    continue;
                }

                $rightStudents = $studentsBySection->get((int) $other['section_id'], []);
                if (array_intersect($leftStudents, $rightStudents) !== []) {
                    throw ValidationException::withMessages([
                        'revision' => 'The proposed live assignment conflicts with another active meeting for an affected student.',
                    ]);
                }
            }
        }
    }

    private function normalizeTime(mixed $time): string
    {
        $value = (string) $time;

        return strlen($value) === 5 ? $value.':00' : $value;
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
