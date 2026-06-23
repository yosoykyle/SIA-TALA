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

class SchedulePublishService
{
    public function __construct(
        private readonly SectionMeetingAssignmentService $assignmentService,
    ) {}

    public function publish(
        ScheduleGenerationRun $run,
        User $registrar,
        ?string $note = null,
        bool $emergency = false,
    ): ScheduleGenerationRun {
        $note = $this->normalizedNote($note);
        $this->authorizePublisher($registrar, $emergency);

        return DB::transaction(function () use ($run, $registrar, $note): ScheduleGenerationRun {
            $lockedRun = ScheduleGenerationRun::query()
                ->with('draftRows')
                ->lockForUpdate()
                ->findOrFail($run->getKey());

            if (! $lockedRun->canBePublished()) {
                throw ValidationException::withMessages([
                    'status' => 'Only generated or reviewed schedule runs can be published.',
                ]);
            }

            if ($lockedRun->draftRows->isEmpty()) {
                throw ValidationException::withMessages([
                    'schedule_draft_rows' => 'A schedule run must have reviewed draft rows before it can be published.',
                ]);
            }

            $blockingDraftRow = $lockedRun->draftRows->first(
                fn (ScheduleDraftRow $row): bool => ! in_array($row->status, ScheduleDraftRow::committableStatuses(), true)
            );

            if ($blockingDraftRow instanceof ScheduleDraftRow) {
                throw ValidationException::withMessages([
                    'schedule_draft_rows' => 'Resolve all draft-row conflicts before publishing the schedule run.',
                ]);
            }

            $timestamp = CarbonImmutable::now(config('app.timezone'));

            $this->supersedePriorPublishedRuns($lockedRun, $timestamp);

            foreach ($lockedRun->draftRows as $draftRow) {
                $this->createSectionMeeting($lockedRun, $draftRow, $registrar, $timestamp);
            }

            $lockedRun->forceFill([
                'status' => ScheduleGenerationRun::StatusPublished,
                'committed_by' => $registrar->id,
                'committed_at' => $timestamp,
                'published_by' => $registrar->id,
                'published_at' => $timestamp,
                'publish_note' => $note,
                'emergency_published' => false,
            ])->save();

            $this->recordActivity($lockedRun, $registrar, $timestamp, $lockedRun->draftRows->count());

            return $lockedRun->fresh();
        });
    }

    private function authorizePublisher(User $registrar, bool $emergency): void
    {
        if ($emergency) {
            throw new AuthorizationException('Emergency schedule publication is outside the active scheduling workflow.');
        }

        if ($registrar->can('manage-schedules')) {
            return;
        }

        throw new AuthorizationException('Only authorized Registrar staff can publish reviewed schedule runs.');
    }

    private function normalizedNote(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $note = trim($note);

        return $note === '' ? null : $note;
    }

    private function supersedePriorPublishedRuns(ScheduleGenerationRun $run, CarbonImmutable $timestamp): void
    {
        ScheduleGenerationRun::query()
            ->where('term_id', $run->term_id)
            ->where('status', ScheduleGenerationRun::StatusPublished)
            ->whereKeyNot($run->getKey())
            ->lockForUpdate()
            ->update([
                'status' => ScheduleGenerationRun::StatusSuperseded,
                'updated_at' => $timestamp,
            ]);
    }

    private function createSectionMeeting(
        ScheduleGenerationRun $run,
        ScheduleDraftRow $draftRow,
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
        ], $registrar, $timestamp);

        $sectionMeeting = SectionMeeting::query()->create([
            ...$payload,
            'schedule_generation_run_id' => $run->id,
        ]);

        DB::table('section_teacher')
            ->where('section_id', $sectionMeeting->section_id)
            ->where('subject_id', $sectionMeeting->subject_id)
            ->delete();

        DB::table('section_teacher')->insert([
            'section_id' => $sectionMeeting->section_id,
            'user_id' => $sectionMeeting->faculty_id,
            'subject_id' => $sectionMeeting->subject_id,
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }

    private function recordActivity(
        ScheduleGenerationRun $run,
        User $registrar,
        CarbonImmutable $timestamp,
        int $publishedMeetings,
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'scheduling',
            'description' => 'Schedule generation run published.',
            'subject_type' => ScheduleGenerationRun::class,
            'subject_id' => $run->id,
            'event' => 'schedule_generation_run_published',
            'causer_type' => User::class,
            'causer_id' => $registrar->id,
            'properties' => json_encode([
                'term_id' => $run->term_id,
                'status_after' => ScheduleGenerationRun::StatusPublished,
                'emergency_published' => false,
                'published_meetings' => $publishedMeetings,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }
}
