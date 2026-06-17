<?php

namespace App\Actions\Scheduling;

use App\Models\ScheduleDraftRow;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionMeeting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleCommitService
{
    public function __construct(
        private readonly SectionMeetingAssignmentService $assignmentService,
    ) {}

    public function commit(ScheduleGenerationRun $run, User $registrar): ScheduleGenerationRun
    {
        $this->authorizeRegistrar($registrar);

        return DB::transaction(function () use ($run, $registrar): ScheduleGenerationRun {
            $lockedRun = ScheduleGenerationRun::query()
                ->lockForUpdate()
                ->findOrFail($run->getKey());

            if (! $lockedRun->canBeCommitted()) {
                throw ValidationException::withMessages([
                    'status' => 'Only generated or under-review schedule runs can be committed.',
                ]);
            }

            $draftRows = DB::table('schedule_draft_rows')
                ->where('generation_run_id', $lockedRun->id)
                ->orderBy('id')
                ->get();

            if ($draftRows->isEmpty()) {
                throw ValidationException::withMessages([
                    'schedule_draft_rows' => 'A schedule run must have draft rows before it can be committed.',
                ]);
            }

            $blockingDraftRow = $draftRows->first(
                fn (object $row): bool => ! in_array((string) $row->status, ScheduleDraftRow::committableStatuses(), true)
            );

            if ($blockingDraftRow !== null) {
                throw ValidationException::withMessages([
                    'schedule_draft_rows' => 'Resolve all draft-row conflicts before committing the schedule run.',
                ]);
            }

            $timestamp = CarbonImmutable::now(config('app.timezone'));

            foreach ($draftRows as $draftRow) {
                $this->createSectionMeeting($lockedRun, $draftRow, $registrar, $timestamp);
            }

            $lockedRun->forceFill([
                'status' => ScheduleGenerationRun::StatusCommitted,
                'committed_by' => $registrar->id,
                'committed_at' => $timestamp,
            ])->save();

            $this->recordActivity($lockedRun, $registrar, $timestamp, $draftRows->count());

            return $lockedRun->fresh();
        });
    }

    private function createSectionMeeting(
        ScheduleGenerationRun $run,
        object $draftRow,
        User $registrar,
        CarbonImmutable $timestamp,
    ): void {
        $payload = $this->assignmentService->prepareForCreate([
            'term_id' => $run->term_id,
            'section_id' => $draftRow->section_id,
            'section_delivery_group_id' => $draftRow->section_delivery_group_id,
            'subject_id' => $draftRow->subject_id,
            'faculty_id' => $draftRow->faculty_id,
            'room' => $draftRow->room,
            'day_of_week' => $draftRow->day_of_week,
            'starts_at' => $draftRow->starts_at,
            'ends_at' => $draftRow->ends_at,
            'modality' => $draftRow->modality,
            'availability_override_reason' => $draftRow->override_reason,
        ], $registrar, $timestamp);

        $sectionMeeting = SectionMeeting::query()->create([
            ...$payload,
            'schedule_generation_run_id' => $run->id,
        ]);

        DB::table('section_teacher')->updateOrInsert(
            [
                'section_id' => $sectionMeeting->section_id,
                'user_id' => $sectionMeeting->faculty_id,
                'subject_id' => $sectionMeeting->subject_id,
            ],
            [
                'updated_at' => $timestamp->toDateTimeString(),
                'created_at' => $timestamp->toDateTimeString(),
            ],
        );
    }

    private function recordActivity(
        ScheduleGenerationRun $run,
        User $registrar,
        CarbonImmutable $timestamp,
        int $committedMeetings,
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'scheduling',
            'description' => 'Schedule generation run committed.',
            'subject_type' => ScheduleGenerationRun::class,
            'subject_id' => $run->id,
            'event' => 'schedule_generation_run_committed',
            'causer_type' => User::class,
            'causer_id' => $registrar->id,
            'properties' => json_encode([
                'term_id' => $run->term_id,
                'status_after' => ScheduleGenerationRun::StatusCommitted,
                'committed_meetings' => $committedMeetings,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }

    private function authorizeRegistrar(User $registrar): void
    {
        if ($registrar->can('manage-schedules')) {
            return;
        }

        throw new AuthorizationException('Only authorized Registrar staff can commit schedule runs.');
    }
}
