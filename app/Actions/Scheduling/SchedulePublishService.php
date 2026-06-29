<?php

namespace App\Actions\Scheduling;

use App\Models\CandidateScheduleRow;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SchedulePublishService
{
    public function publish(
        ScheduleGenerationRun $run,
        User $publisher,
        ?string $note = null,
    ): ScheduleGenerationRun {
        Gate::forUser($publisher)->authorize('publish', $run);
        $note = $this->normalizedNote($note);

        return DB::transaction(function () use ($run, $publisher, $note): ScheduleGenerationRun {
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

            if (! $lockedRun instanceof ScheduleGenerationRun) {
                abort(404);
            }

            Gate::forUser($publisher)->authorize('publish', $lockedRun);

            $candidateRows = CandidateScheduleRow::query()
                ->where('schedule_run_id', $lockedRun->id)
                ->with('schedulingDemand.termOffering')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $this->assertPublishable($lockedRun, $candidateRows);

            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $publicationVersion = ((int) ($termRuns->max('publication_version') ?? 0)) + 1;

            ScheduleGenerationRun::query()
                ->where('term_id', $lockedRun->term_id)
                ->where('status', ScheduleGenerationRun::StatusPublished)
                ->whereKeyNot($lockedRun->getKey())
                ->update([
                    'status' => ScheduleGenerationRun::StatusSuperseded,
                    'updated_at' => $timestamp,
                ]);

            foreach ($candidateRows as $candidateRow) {
                SectionMeeting::query()->create([
                    'schedule_run_id' => $lockedRun->id,
                    'scheduling_demand_id' => $candidateRow->scheduling_demand_id,
                    'meeting_sequence' => $candidateRow->meeting_sequence,
                    'faculty_user_id' => $candidateRow->faculty_user_id,
                    'room_id' => $candidateRow->room_id,
                    'day_of_week' => $candidateRow->day_of_week,
                    'starts_at' => $candidateRow->starts_at,
                    'ends_at' => $candidateRow->ends_at,
                    'modality' => $candidateRow->publicationModality(),
                    'state' => SectionMeeting::StateActive,
                    'published_at' => $timestamp,
                ]);
            }

            $lockedRun->forceFill([
                'status' => ScheduleGenerationRun::StatusPublished,
                'published_by' => $publisher->id,
                'published_at' => $timestamp,
                'publication_version' => $publicationVersion,
                'publication_note' => $note,
            ])->save();

            $this->recordActivity(
                $lockedRun,
                $publisher,
                $timestamp,
                $publicationVersion,
                $candidateRows->count(),
            );

            return $lockedRun->fresh(['candidateRows', 'sectionMeetings']);
        }, attempts: 5);
    }

    /**
     * @param  Collection<int, CandidateScheduleRow>  $candidateRows
     */
    private function assertPublishable(ScheduleGenerationRun $run, Collection $candidateRows): void
    {
        if (! in_array($run->status, ScheduleGenerationRun::publishableStatuses(), true)) {
            throw ValidationException::withMessages([
                'status' => 'Only an under-review schedule run can be published.',
            ]);
        }

        if ($candidateRows->isEmpty()) {
            throw ValidationException::withMessages([
                'candidate_schedule_rows' => 'A schedule run must contain reviewed candidate rows before publication.',
            ]);
        }

        $blockingCandidate = $candidateRows->first(
            fn (CandidateScheduleRow $candidateRow): bool => ! $candidateRow->isPublishableFor($run),
        );

        if ($blockingCandidate instanceof CandidateScheduleRow) {
            throw ValidationException::withMessages([
                'candidate_schedule_rows' => 'Resolve all candidate conflicts, blocking violations, and invalid assignment fields before publication.',
            ]);
        }
    }

    private function normalizedNote(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $note = Str::of($note)->trim()->toString();

        if (Str::length($note) > 2000) {
            throw ValidationException::withMessages([
                'publication_note' => 'The publication note may not be greater than 2,000 characters.',
            ]);
        }

        return $note === '' ? null : $note;
    }

    private function recordActivity(
        ScheduleGenerationRun $run,
        User $publisher,
        CarbonImmutable $timestamp,
        int $publicationVersion,
        int $publishedMeetings,
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'scheduling',
            'description' => 'Schedule generation run published.',
            'subject_type' => ScheduleGenerationRun::class,
            'subject_id' => $run->id,
            'event' => 'schedule_generation_run_published',
            'causer_type' => User::class,
            'causer_id' => $publisher->id,
            'properties' => json_encode([
                'term_id' => $run->term_id,
                'status_after' => ScheduleGenerationRun::StatusPublished,
                'publication_version' => $publicationVersion,
                'published_meetings' => $publishedMeetings,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }
}
