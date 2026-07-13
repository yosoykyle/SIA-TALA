<?php

namespace App\Actions\Scheduling;

use App\Models\EnrollmentSeatReservation;
use App\Models\ScheduleGenerationRun;
use App\Models\ScheduleRevisionEvent;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\StudentScheduleBinding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Validation\ValidationException;

final class ScheduleRevisionImpactService
{
    public function __construct(
        private ScheduleAssignmentRevalidationService $revalidator,
    ) {}

    /**
     * @param  array<array-key, mixed>  $changes
     */
    public function preview(
        ScheduleGenerationRun $run,
        string $changeType,
        array $changes,
    ): ScheduleRevisionImpact {
        return $this->revisionImpact($run, $changeType, $changes, lock: false);
    }

    /**
     * @param  array<array-key, mixed>  $changes
     */
    public function lockForRevision(
        ScheduleGenerationRun $run,
        string $changeType,
        array $changes,
    ): ScheduleRevisionImpact {
        return $this->revisionImpact($run, $changeType, $changes, lock: true);
    }

    public function previewCancellation(
        ScheduleGenerationRun $run,
        Section $section,
    ): ScheduleRevisionImpact {
        return $this->cancellationImpact($run, $section, lock: false);
    }

    public function lockForCancellation(
        ScheduleGenerationRun $run,
        Section $section,
    ): ScheduleRevisionImpact {
        return $this->cancellationImpact($run, $section, lock: true);
    }

    /**
     * @param  array<array-key, mixed>  $changes
     */
    private function revisionImpact(
        ScheduleGenerationRun $run,
        string $changeType,
        array $changes,
        bool $lock,
    ): ScheduleRevisionImpact {
        $run = $this->publishedRun($run);
        $changesByMeeting = $this->normalizedChanges($changeType, $changes);
        $meetings = $this->meetingsForRun($run, $lock);
        $selected = $meetings->whereIn('id', array_keys($changesByMeeting));

        if ($selected->count() !== count($changesByMeeting)) {
            throw ValidationException::withMessages([
                'section_meetings' => 'Every revised meeting must be an active meeting in the selected published run.',
            ]);
        }

        $bindings = $this->activeBindings($selected->modelKeys(), $lock);
        $studentIdsByMeeting = $this->studentIdsByMeeting($bindings);
        $meetingChanges = [];
        $proposedSnapshots = [];

        foreach ($meetings as $meeting) {
            $old = $this->snapshot($meeting);
            $new = $old;
            $change = $changesByMeeting[(int) $meeting->id] ?? null;

            if (is_array($change)) {
                $new = $this->applyChange($meeting, $changeType, $change, $old);

                if ($this->sameOwnedFields($changeType, $old, $new)) {
                    throw ValidationException::withMessages([
                        'section_meetings' => 'A live schedule revision must change at least one owned assignment field.',
                    ]);
                }

                $meetingStudentIds = $studentIdsByMeeting[(int) $meeting->id] ?? [];
                $meetingFacultyIds = collect([$old['faculty_user_id'], $new['faculty_user_id']])
                    ->map(fn (mixed $id): int => (int) $id)
                    ->filter()
                    ->unique();
                $meetingChanges[] = [
                    'section_meeting_id' => (int) $meeting->id,
                    'old' => $old,
                    'new' => $new,
                    'affected_student_count' => count($meetingStudentIds),
                    'affected_faculty_count' => $meetingFacultyIds->count(),
                ];
            }

            $proposedSnapshots[] = $new;
        }

        $validation = $this->revalidator->validateLiveAssignments(
            $run,
            array_map(fn (array $snapshot): array => $this->assignment($snapshot), $proposedSnapshots),
            $selected->modelKeys(),
        );
        $affectedStudents = collect($studentIdsByMeeting)->flatten()->unique()->count();
        $affectedFaculty = collect($meetingChanges)
            ->flatMap(fn (array $change): array => [
                $change['old']['faculty_user_id'],
                $change['new']['faculty_user_id'],
            ])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->count();

        return new ScheduleRevisionImpact(
            changeType: $changeType,
            meetingChanges: $meetingChanges,
            affectedStudents: $affectedStudents,
            affectedFaculty: $affectedFaculty,
            findings: $validation->findings(),
        );
    }

    private function cancellationImpact(
        ScheduleGenerationRun $run,
        Section $section,
        bool $lock,
    ): ScheduleRevisionImpact {
        $run = $this->publishedRun($run);
        $section->loadMissing('termOffering');

        if ((int) $section->termOffering?->term_id !== (int) $run->term_id) {
            throw ValidationException::withMessages([
                'section' => 'The cancelled Section must belong to the published run term.',
            ]);
        }

        $meetings = $this->meetingsForRun($run, $lock);
        $cancelledMeetings = $meetings->filter(
            fn (SectionMeeting $meeting): bool => (int) $meeting->schedulingDemand?->sectionDeliveryGroup?->section_id === (int) $section->id,
        );

        if ($cancelledMeetings->isEmpty()) {
            throw ValidationException::withMessages([
                'section' => 'Section cancellation requires active official meetings in the published run.',
            ]);
        }

        $cancelledMeetingIds = $cancelledMeetings->modelKeys();
        $cancelledDemandIds = $cancelledMeetings
            ->pluck('scheduling_demand_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $bindings = $this->activeBindings($cancelledMeetingIds, $lock);
        $reservations = $this->capacityHoldingReservations((int) $section->id, $lock);
        $studentIdsByMeeting = $this->studentIdsByMeeting($bindings);
        $meetingChanges = $cancelledMeetings
            ->map(function (SectionMeeting $meeting) use ($studentIdsByMeeting): array {
                $old = $this->snapshot($meeting);
                $new = [...$old, 'state' => SectionMeeting::StateCancelled];

                return [
                    'section_meeting_id' => (int) $meeting->id,
                    'old' => $old,
                    'new' => $new,
                    'affected_student_count' => count($studentIdsByMeeting[(int) $meeting->id] ?? []),
                    'affected_faculty_count' => 1,
                ];
            })
            ->values()
            ->all();
        $remainingAssignments = $meetings
            ->reject(fn (SectionMeeting $meeting): bool => $cancelledMeetings->contains('id', $meeting->id))
            ->map(fn (SectionMeeting $meeting): array => $this->assignment($this->snapshot($meeting)))
            ->values()
            ->all();
        $validation = $this->revalidator->validateLiveAssignments(
            $run,
            $remainingAssignments,
            $cancelledMeetingIds,
            $cancelledDemandIds,
        );

        return new ScheduleRevisionImpact(
            changeType: ScheduleRevisionEvent::ChangeSectionCancellation,
            meetingChanges: $meetingChanges,
            affectedStudents: collect($studentIdsByMeeting)->flatten()->unique()->count(),
            affectedFaculty: $cancelledMeetings->pluck('faculty_user_id')->unique()->count(),
            findings: $validation->findings(),
            activeBindings: $bindings->count(),
            capacityHoldingReservations: $reservations->count(),
        );
    }

    private function publishedRun(ScheduleGenerationRun $run): ScheduleGenerationRun
    {
        $current = ScheduleGenerationRun::query()->find($run->getKey());

        if (! $current instanceof ScheduleGenerationRun || ! $current->isPublished()) {
            throw ValidationException::withMessages([
                'schedule_run' => 'Live revision requires the current published schedule run.',
            ]);
        }

        return $current;
    }

    /** @return EloquentCollection<int, SectionMeeting> */
    private function meetingsForRun(ScheduleGenerationRun $run, bool $lock): EloquentCollection
    {
        $query = SectionMeeting::query()
            ->where('schedule_run_id', $run->id)
            ->where('state', SectionMeeting::StateActive)
            ->with([
                'schedulingDemand.sectionDeliveryGroup.section',
                'schedulingDemand.termOffering',
            ])
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * @param  list<int>  $meetingIds
     * @return EloquentCollection<int, StudentScheduleBinding>
     */
    private function activeBindings(array $meetingIds, bool $lock): EloquentCollection
    {
        if ($meetingIds === []) {
            return new EloquentCollection;
        }

        $query = StudentScheduleBinding::query()
            ->whereIn('section_meeting_id', $meetingIds)
            ->where('is_active', true)
            ->with('courseEnrollment.enrollment')
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /** @return EloquentCollection<int, EnrollmentSeatReservation> */
    private function capacityHoldingReservations(int $sectionId, bool $lock): EloquentCollection
    {
        $query = EnrollmentSeatReservation::query()
            ->where('section_id', $sectionId)
            ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * @param  EloquentCollection<int, StudentScheduleBinding>  $bindings
     * @return array<int, list<int>>
     */
    private function studentIdsByMeeting(EloquentCollection $bindings): array
    {
        $studentIds = [];

        foreach ($bindings as $binding) {
            $meetingId = (int) $binding->section_meeting_id;
            $studentId = (int) $binding->courseEnrollment?->enrollment?->student_profile_id;

            if ($meetingId > 0 && $studentId > 0) {
                $studentIds[$meetingId][$studentId] = $studentId;
            }
        }

        return collect($studentIds)
            ->map(fn (array $ids): array => array_values($ids))
            ->all();
    }

    /**
     * @param  array<array-key, mixed>  $changes
     * @return array<int, array<string, mixed>>
     */
    private function normalizedChanges(string $changeType, array $changes): array
    {
        $allowedFields = match ($changeType) {
            ScheduleRevisionEvent::ChangeRoom => ['section_meeting_id', 'room_id'],
            ScheduleRevisionEvent::ChangeFacultyReassignment => ['section_meeting_id', 'faculty_user_id'],
            ScheduleRevisionEvent::ChangeTime => ['section_meeting_id', 'day_of_week', 'starts_at', 'ends_at'],
            ScheduleRevisionEvent::ChangeDeliveryModality => ['section_meeting_id', 'modality', 'room_id'],
            default => throw ValidationException::withMessages([
                'change_type' => 'The selected live revision change type is not supported.',
            ]),
        };

        if ($changes === [] || ! array_is_list($changes)) {
            throw ValidationException::withMessages([
                'section_meetings' => 'Select at least one meeting for the live revision.',
            ]);
        }

        $normalized = [];

        foreach ($changes as $change) {
            if (! is_array($change)
                || array_diff(array_keys($change), $allowedFields) !== []
                || array_diff($allowedFields, array_keys($change)) !== []) {
                throw ValidationException::withMessages([
                    'section_meetings' => 'A live revision may contain only the fields owned by its change type.',
                ]);
            }

            $meetingId = filter_var($change['section_meeting_id'] ?? null, FILTER_VALIDATE_INT);

            if ($meetingId === false || $meetingId < 1) {
                throw ValidationException::withMessages([
                    'section_meetings' => 'Every live revision row requires a valid Section Meeting.',
                ]);
            }

            if (array_key_exists($meetingId, $normalized)) {
                throw ValidationException::withMessages([
                    'section_meetings' => 'Each Section Meeting may appear only once in a live revision.',
                ]);
            }

            $normalized[$meetingId] = [...$change, 'section_meeting_id' => $meetingId];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $change
     * @param  array<string, mixed>  $old
     * @return array<string, mixed>
     */
    private function applyChange(
        SectionMeeting $meeting,
        string $changeType,
        array $change,
        array $old,
    ): array {
        return match ($changeType) {
            ScheduleRevisionEvent::ChangeRoom => [
                ...$old,
                'room_id' => $change['room_id'] === null ? null : (int) $change['room_id'],
            ],
            ScheduleRevisionEvent::ChangeFacultyReassignment => [
                ...$old,
                'faculty_user_id' => (int) $change['faculty_user_id'],
            ],
            ScheduleRevisionEvent::ChangeTime => [
                ...$old,
                'day_of_week' => (int) $change['day_of_week'],
                'starts_at' => (string) $change['starts_at'],
                'ends_at' => (string) $change['ends_at'],
            ],
            ScheduleRevisionEvent::ChangeDeliveryModality => $this->modalityChange($meeting, $change, $old),
            default => $old,
        };
    }

    /**
     * @param  array<string, mixed>  $change
     * @param  array<string, mixed>  $old
     * @return array<string, mixed>
     */
    private function modalityChange(SectionMeeting $meeting, array $change, array $old): array
    {
        $demand = $meeting->schedulingDemand;
        $modality = $demand?->sectionDeliveryGroup?->modality ?: $demand?->termOffering?->modality;

        if (! $demand instanceof SchedulingDemand || ! filled($modality) || $change['modality'] !== $modality) {
            throw ValidationException::withMessages([
                'modality' => 'A delivery-modality correction must match the current authoritative delivery source.',
            ]);
        }

        return [
            ...$old,
            'modality' => (string) $change['modality'],
            'room_id' => $change['room_id'] === null ? null : (int) $change['room_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function sameOwnedFields(string $changeType, array $old, array $new): bool
    {
        $fields = match ($changeType) {
            ScheduleRevisionEvent::ChangeRoom => ['room_id'],
            ScheduleRevisionEvent::ChangeFacultyReassignment => ['faculty_user_id'],
            ScheduleRevisionEvent::ChangeTime => ['day_of_week', 'starts_at', 'ends_at'],
            ScheduleRevisionEvent::ChangeDeliveryModality => ['modality', 'room_id'],
            default => [],
        };

        return collect($fields)->every(fn (string $field): bool => $old[$field] === $new[$field]);
    }

    /** @return array<string, mixed> */
    private function snapshot(SectionMeeting $meeting): array
    {
        return [
            'section_meeting_id' => (int) $meeting->id,
            'schedule_run_id' => (int) $meeting->schedule_run_id,
            'scheduling_demand_id' => (int) $meeting->scheduling_demand_id,
            'meeting_sequence' => (int) $meeting->meeting_sequence,
            'faculty_user_id' => (int) $meeting->faculty_user_id,
            'room_id' => $meeting->room_id === null ? null : (int) $meeting->room_id,
            'day_of_week' => (int) $meeting->day_of_week,
            'starts_at' => (string) $meeting->starts_at,
            'ends_at' => (string) $meeting->ends_at,
            'modality' => (string) $meeting->modality,
            'state' => (string) $meeting->state,
            'published_at' => CarbonImmutable::parse((string) $meeting->published_at)->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function assignment(array $snapshot): array
    {
        return [
            'scheduling_demand_id' => $snapshot['scheduling_demand_id'],
            'meeting_sequence' => $snapshot['meeting_sequence'],
            'faculty_user_id' => $snapshot['faculty_user_id'],
            'room_id' => $snapshot['room_id'],
            'day_of_week' => $snapshot['day_of_week'],
            'starts_at' => $snapshot['starts_at'],
            'ends_at' => $snapshot['ends_at'],
            'status' => 'ok',
            'scores' => [],
            'warnings' => [],
            'violations' => [],
            'source_section_meeting_id' => $snapshot['section_meeting_id'] ?? null,
        ];
    }
}
