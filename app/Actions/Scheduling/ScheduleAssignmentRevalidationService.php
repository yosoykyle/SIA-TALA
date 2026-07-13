<?php

namespace App\Actions\Scheduling;

use App\Models\CalendarEvent;
use App\Models\CourseEnrollment;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionMeeting;
use App\Models\StudentScheduleBinding;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ScheduleAssignmentRevalidationService
{
    public function __construct(
        private readonly ScheduleSolverSnapshotService $snapshotService,
        private readonly ScheduleAssignmentValidationService $assignmentValidator,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $assignments
     */
    public function validateCandidateSet(
        ScheduleGenerationRun $run,
        array $assignments,
        array $excludedDemandIds = [],
    ): ScheduleValidationResult {
        $snapshot = $this->snapshotService->currentForRun($run, $excludedDemandIds);

        return $this->assignmentValidator->validateCandidateAssignments(
            $run,
            $snapshot,
            $this->solverAssignments($snapshot, $assignments),
        );
    }

    /**
     * Entry point reserved for controlled live revisions in TAL-94D.
     *
     * @param  list<array<string, mixed>>  $assignments
     * @param  list<int>  $excludedMeetingIds
     * @param  list<int>  $excludedDemandIds
     */
    public function validateLiveAssignments(
        ScheduleGenerationRun $run,
        array $assignments,
        array $excludedMeetingIds = [],
        array $excludedDemandIds = [],
    ): ScheduleValidationResult {
        $validation = $this->validateCandidateSet($run, $assignments, $excludedDemandIds);
        $liveFindings = $this->liveConflictFindings($run, $assignments, $excludedMeetingIds);

        return $this->withAdditionalFindings($validation, $liveFindings);
    }

    /**
     * @param  array<string, mixed>  $assignment
     */
    public function assertRecurringBlocksAllow(array $assignment): void
    {
        $termId = $this->integerValue($assignment['term_id'] ?? null);
        $facultyId = $this->integerValue($assignment['faculty_user_id'] ?? $assignment['faculty_id'] ?? null);
        $roomId = $this->integerValue($assignment['room_id'] ?? null);
        $dayOfWeek = $this->integerValue($assignment['day_of_week'] ?? null);
        $startsAt = $this->timeValue($assignment['starts_at'] ?? null);
        $endsAt = $this->timeValue($assignment['ends_at'] ?? null);

        if ($roomId === null && filled($assignment['room'] ?? null)) {
            $roomId = Room::query()->where('code', (string) $assignment['room'])->value('id');
        }

        if ($termId === null || $dayOfWeek === null || $startsAt === null || $endsAt === null) {
            throw ValidationException::withMessages([
                'calendar_blocks' => 'Term, day, start time, and end time are required for recurring scheduling-block validation.',
            ]);
        }

        $block = CalendarEvent::query()
            ->recurringSchedulingBlocks()
            ->where('term_id', $termId)
            ->where('state', CalendarEvent::StateActive)
            ->where('day_of_week', $dayOfWeek)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->where(function ($query) use ($facultyId, $roomId): void {
                $query->where('scope_type', CalendarEvent::ScopeInstitution);

                if ($facultyId !== null) {
                    $query->orWhere(fn ($query) => $query
                        ->where('scope_type', CalendarEvent::ScopeFaculty)
                        ->where('faculty_user_id', $facultyId));
                }

                if ($roomId !== null) {
                    $query->orWhere(fn ($query) => $query
                        ->where('scope_type', CalendarEvent::ScopeRoom)
                        ->where('room_id', $roomId));
                }
            })
            ->orderBy('scope_type')
            ->orderBy('id')
            ->first();

        if (! $block instanceof CalendarEvent) {
            return;
        }

        $field = match ($block->scope_type) {
            CalendarEvent::ScopeFaculty => 'faculty_user_id',
            CalendarEvent::ScopeRoom => 'room_id',
            default => 'day_of_week',
        };

        throw ValidationException::withMessages([
            $field => "The assignment overlaps recurring scheduling block #{$block->id} ({$block->scope_type}). Update the authoritative calendar event before assigning this meeting.",
        ]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<array<string, mixed>>  $assignments
     * @return list<array<string, mixed>>
     */
    private function solverAssignments(array $snapshot, array $assignments): array
    {
        $demands = collect($snapshot['scheduling_demands'] ?? [])->keyBy('scheduling_demand_id');
        $timeSlots = collect($snapshot['time_slots'] ?? []);

        return collect($assignments)
            ->map(function (array $assignment) use ($demands, $timeSlots): array {
                $demandId = $this->integerValue($assignment['scheduling_demand_id'] ?? null);
                $demand = $demandId !== null ? $demands->get($demandId) : null;
                $demand = is_array($demand) ? $demand : [];
                $day = $this->integerValue($assignment['day_of_week'] ?? null);
                $startsAt = $this->timeValue($assignment['starts_at'] ?? null);
                $endsAt = $this->timeValue($assignment['ends_at'] ?? null);
                $timeBlockKey = filled($assignment['time_block_key'] ?? null)
                    ? (string) $assignment['time_block_key']
                    : null;
                $slot = $timeSlots->first(function (mixed $slot) use ($day, $startsAt, $timeBlockKey): bool {
                    if (! is_array($slot)
                        || (int) ($slot['day_of_week'] ?? 0) !== $day
                        || $this->timeValue($slot['starts_at'] ?? null) !== $startsAt) {
                        return false;
                    }

                    return $timeBlockKey === null || ($slot['time_block_key'] ?? null) === $timeBlockKey;
                });
                $timeBlockKey ??= is_array($slot) ? (string) $slot['time_block_key'] : null;
                $facultyId = $this->integerValue($assignment['faculty_user_id'] ?? $assignment['faculty_id'] ?? null);
                $status = (string) ($assignment['status'] ?? $assignment['assignment_status'] ?? 'ok');
                $status = in_array($status, ['ok', 'warning'], true) ? $status : 'conflict';

                return [
                    'scheduling_demand_id' => $demandId,
                    'meeting_sequence' => $this->integerValue($assignment['meeting_sequence'] ?? null),
                    'term_offering_id' => $demand['term_offering_id'] ?? null,
                    'section_id' => $demand['section_id'] ?? null,
                    'section_delivery_group_id' => $demand['section_delivery_group_id'] ?? null,
                    'subject_id' => $demand['course_id'] ?? null,
                    'course_component_id' => $demand['course_component_id'] ?? null,
                    'faculty_user_id' => $facultyId,
                    'faculty_id' => $facultyId,
                    'room_id' => $this->integerValue($assignment['room_id'] ?? null),
                    'day_of_week' => $day,
                    'day' => $day,
                    'starts_at' => $startsAt,
                    'start_time' => $startsAt,
                    'ends_at' => $endsAt,
                    'end_time' => $endsAt,
                    'time_block_key' => $timeBlockKey,
                    'time_block_reference' => $timeBlockKey,
                    'time_slot_id' => is_array($slot) ? (int) $slot['time_slot_id'] : null,
                    'assignment_status' => $status,
                    'scores' => is_array($assignment['scores'] ?? null) ? $assignment['scores'] : [],
                    'warnings' => is_array($assignment['warnings'] ?? null) ? array_values($assignment['warnings']) : [],
                    'violations' => is_array($assignment['violations'] ?? null) ? array_values($assignment['violations']) : [],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $assignments
     * @param  list<int>  $excludedMeetingIds
     * @return list<array<string, mixed>>
     */
    private function liveConflictFindings(
        ScheduleGenerationRun $run,
        array $assignments,
        array $excludedMeetingIds,
    ): array {
        $excludedMeetingIds = collect($excludedMeetingIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $meetings = SectionMeeting::query()
            ->activeOfficial()
            ->with('schedulingDemand')
            ->whereHas('scheduleRun', fn ($query) => $query->where('term_id', $run->term_id))
            ->when($excludedMeetingIds !== [], fn ($query) => $query->whereNotIn('id', $excludedMeetingIds))
            ->get();
        $findings = [];

        foreach ($assignments as $assignment) {
            foreach ($meetings as $meeting) {
                if ($this->integerValue($assignment['source_section_meeting_id'] ?? null) === (int) $meeting->id) {
                    continue;
                }

                if (! $this->overlaps($assignment, $meeting->getAttributes())) {
                    continue;
                }

                $checks = [
                    'live_faculty_overlap' => ['faculty_user_id', 'faculty_no_overlap'],
                    'live_room_overlap' => ['room_id', 'room_no_overlap'],
                ];

                foreach ($checks as $code => [$field, $constraint]) {
                    $actual = $this->integerValue($assignment[$field] ?? null);

                    if ($actual === null || $actual !== (int) $meeting->getAttribute($field)) {
                        continue;
                    }

                    $findings[] = $this->finding($run, $assignment, $code, $constraint, $field, $meeting);
                }

                $groupId = $this->integerValue($assignment['section_delivery_group_id'] ?? null);
                $meetingGroupId = $meeting->schedulingDemand?->section_delivery_group_id;

                if ($groupId !== null && $groupId === (int) $meetingGroupId) {
                    $findings[] = $this->finding(
                        $run,
                        $assignment,
                        'live_delivery_group_overlap',
                        'section_delivery_group_no_overlap',
                        'section_delivery_group_id',
                        $meeting,
                    );
                }
            }
        }

        return [...$findings, ...$this->studentConflictFindings($run, $assignments, $excludedMeetingIds)];
    }

    /**
     * @param  list<array<string, mixed>>  $assignments
     * @param  list<int>  $excludedMeetingIds
     * @return list<array<string, mixed>>
     */
    private function studentConflictFindings(
        ScheduleGenerationRun $run,
        array $assignments,
        array $excludedMeetingIds,
    ): array {
        $sourceMeetingIds = collect($assignments)
            ->pluck('source_section_meeting_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique();

        if ($sourceMeetingIds->isEmpty()) {
            return [];
        }

        $studentIdsByMeeting = StudentScheduleBinding::query()
            ->where('is_active', true)
            ->whereIn('section_meeting_id', $sourceMeetingIds->all())
            ->whereHas('courseEnrollment', fn ($query) => $query->where('status', CourseEnrollment::StatusActive))
            ->with('courseEnrollment.enrollment')
            ->get()
            ->groupBy('section_meeting_id')
            ->map(fn (Collection $bindings): array => $bindings
                ->pluck('courseEnrollment.enrollment.student_profile_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all());
        $unaffected = StudentScheduleBinding::query()
            ->where('is_active', true)
            ->whereHas('courseEnrollment', fn ($query) => $query->where('status', CourseEnrollment::StatusActive))
            ->whereHas('sectionMeeting', function ($query) use ($run, $excludedMeetingIds): void {
                $query->where('state', SectionMeeting::StateActive)
                    ->whereHas('scheduleRun', fn ($query) => $query
                        ->where('status', ScheduleGenerationRun::StatusPublished)
                        ->where('term_id', $run->term_id))
                    ->when($excludedMeetingIds !== [], fn ($query) => $query->whereNotIn('id', $excludedMeetingIds));
            })
            ->with(['courseEnrollment.enrollment', 'sectionMeeting'])
            ->get();
        $findings = [];

        foreach ($assignments as $assignment) {
            $sourceMeetingId = $this->integerValue($assignment['source_section_meeting_id'] ?? null);
            $studentIds = $sourceMeetingId !== null ? ($studentIdsByMeeting->get($sourceMeetingId) ?? []) : [];

            foreach ($unaffected as $binding) {
                $studentId = (int) $binding->courseEnrollment?->enrollment?->student_profile_id;
                $meeting = $binding->sectionMeeting;

                if (! in_array($studentId, $studentIds, true)
                    || ! $meeting instanceof SectionMeeting
                    || $sourceMeetingId === (int) $meeting->id
                    || ! $this->overlaps($assignment, $meeting->getAttributes())) {
                    continue;
                }

                $findings[] = [
                    'code' => 'active_student_binding_overlap',
                    'severity' => 'blocking',
                    'constraint' => 'student_no_overlap',
                    'message' => 'The proposed live assignment conflicts with another active meeting for an affected student.',
                    'scheduling_demand_id' => $this->integerValue($assignment['scheduling_demand_id'] ?? null),
                    'meeting_sequence' => $this->integerValue($assignment['meeting_sequence'] ?? null),
                    'source_type' => 'student_schedule_binding',
                    'source_id' => (int) $binding->id,
                    'source_field' => 'section_meeting_id',
                ];
            }
        }

        return $findings;
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     */
    private function withAdditionalFindings(
        ScheduleValidationResult $validation,
        array $findings,
    ): ScheduleValidationResult {
        if ($findings === []) {
            return $validation;
        }

        $allFindings = [...$validation->findings(), ...$findings];
        $blocking = collect($allFindings)->where('severity', 'blocking');
        $summary = $validation->summary();
        $summary['status'] = $blocking->isEmpty() ? 'accepted' : 'blocked';
        $summary['candidate_row_count'] = $blocking->isEmpty() ? count($validation->candidateRows()) : 0;
        $summary['rejected_count'] = $blocking->count();
        $summary['rejected_rows'] = $blocking
            ->map(fn (array $finding): array => [
                'reason' => $finding['code'],
                'message' => $finding['message'],
            ])
            ->values()
            ->all();

        return new ScheduleValidationResult(
            candidateRows: $blocking->isEmpty() ? $validation->candidateRows() : [],
            findings: $allFindings,
            metadata: $validation->metadata(),
            summary: $summary,
        );
    }

    /**
     * @param  array<string, mixed>  $assignment
     * @return array<string, mixed>
     */
    private function finding(
        ScheduleGenerationRun $run,
        array $assignment,
        string $code,
        string $constraint,
        string $sourceField,
        SectionMeeting $meeting,
    ): array {
        return [
            'code' => $code,
            'severity' => 'blocking',
            'constraint' => $constraint,
            'message' => 'The proposed live assignment conflicts with an unaffected official meeting.',
            'scheduling_demand_id' => $this->integerValue($assignment['scheduling_demand_id'] ?? null),
            'meeting_sequence' => $this->integerValue($assignment['meeting_sequence'] ?? null),
            'source_type' => 'section_meeting',
            'source_id' => (int) $meeting->id,
            'source_field' => $sourceField,
        ];
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function overlaps(array $left, array $right): bool
    {
        $leftDay = $this->integerValue($left['day_of_week'] ?? null);
        $rightDay = $this->integerValue($right['day_of_week'] ?? null);
        $leftStart = $this->timeValue($left['starts_at'] ?? null);
        $leftEnd = $this->timeValue($left['ends_at'] ?? null);
        $rightStart = $this->timeValue($right['starts_at'] ?? null);
        $rightEnd = $this->timeValue($right['ends_at'] ?? null);

        return $leftDay !== null
            && $leftDay === $rightDay
            && $leftStart !== null
            && $leftEnd !== null
            && $rightStart !== null
            && $rightEnd !== null
            && $leftStart < $rightEnd
            && $leftEnd > $rightStart;
    }

    private function integerValue(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function timeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $time = (string) $value;

        return strlen($time) === 5 ? $time.':00' : $time;
    }
}
