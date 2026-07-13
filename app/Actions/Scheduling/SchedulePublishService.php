<?php

namespace App\Actions\Scheduling;

use App\Models\CandidateScheduleRow;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SchedulePublishService
{
    public function __construct(
        private readonly ScheduleAssignmentRevalidationService $revalidator,
    ) {}

    public function publish(
        ScheduleGenerationRun $run,
        User $publisher,
        ?string $note = null,
    ): ScheduleGenerationRun {
        Gate::forUser($publisher)->authorize('publish', $run);
        $note = $this->normalizedNote($note);

        $outcome = DB::transaction(function () use ($run, $publisher, $note): array {
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
                ->with([
                    'schedulingDemand.sectionDeliveryGroup',
                    'schedulingDemand.termOffering',
                ])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $this->assertPublishable($lockedRun, $candidateRows);

            $validation = $this->revalidator->validateCandidateSet(
                $lockedRun,
                $candidateRows
                    ->map(fn (CandidateScheduleRow $row): array => $this->candidatePayload($row))
                    ->values()
                    ->all(),
            );
            $this->storeCurrentRevalidation($lockedRun, $validation, $publisher);

            if (! $validation->passes()) {
                return [
                    'run' => $lockedRun->fresh(),
                    'validation' => $validation,
                ];
            }

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
                    'modality' => $this->currentPublicationModality($candidateRow),
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

            return [
                'run' => $lockedRun->fresh(['candidateRows', 'sectionMeetings']),
                'validation' => $validation,
            ];
        }, attempts: 5);

        if (! $outcome['validation']->passes()) {
            $first = $outcome['validation']->blockingFindings()[0] ?? null;

            throw ValidationException::withMessages([
                'candidate_schedule_rows' => is_array($first)
                    ? (string) $first['message']
                    : 'The candidate schedule failed current hard-constraint validation.',
            ]);
        }

        return $outcome['run'];
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
            fn (CandidateScheduleRow $candidateRow): bool => ! $candidateRow->isCommittable(),
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

    private function currentPublicationModality(CandidateScheduleRow $candidateRow): string
    {
        $demand = $candidateRow->getRelation('schedulingDemand');

        if (! $demand instanceof SchedulingDemand) {
            throw ValidationException::withMessages([
                'candidate_schedule_rows' => 'A candidate assignment must reference a current Scheduling Demand.',
            ]);
        }

        $group = $demand->getRelation('sectionDeliveryGroup');
        $offering = $demand->getRelation('termOffering');

        if ($group instanceof SectionDeliveryGroup && filled($group->modality)) {
            return (string) $group->modality;
        }

        if ($offering instanceof TermOffering) {
            return (string) $offering->modality;
        }

        throw ValidationException::withMessages([
            'candidate_schedule_rows' => 'A candidate assignment must reference a current Term Offering.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function candidatePayload(CandidateScheduleRow $row): array
    {
        $scores = $row->getAttribute('scores');
        $warnings = $row->getAttribute('warnings');
        $violations = $row->getAttribute('violations');

        return [
            'scheduling_demand_id' => (int) $row->scheduling_demand_id,
            'meeting_sequence' => (int) $row->meeting_sequence,
            'faculty_user_id' => (int) $row->faculty_user_id,
            'room_id' => $row->room_id !== null ? (int) $row->room_id : null,
            'day_of_week' => (int) $row->day_of_week,
            'starts_at' => (string) $row->starts_at,
            'ends_at' => (string) $row->ends_at,
            'time_block_key' => $row->time_block_key,
            'status' => $row->status,
            'scores' => is_array($scores) ? $scores : [],
            'warnings' => is_array($warnings) ? $warnings : [],
            'violations' => is_array($violations) ? $violations : [],
        ];
    }

    private function storeCurrentRevalidation(
        ScheduleGenerationRun $run,
        ScheduleValidationResult $validation,
        User $publisher,
    ): void {
        $currentDiagnostics = $run->getAttribute('diagnostics');
        $diagnostics = is_array($currentDiagnostics) ? $currentDiagnostics : [];
        $diagnostics['current_revalidation'] = [
            'context' => 'publication',
            'status' => $validation->passes() ? 'accepted' : 'blocked',
            'validated_at' => now()->toIso8601String(),
            'actor_id' => (int) $publisher->id,
            'summary' => $validation->summary(),
            'findings' => $validation->findings(),
        ];
        $run->forceFill(['diagnostics' => $diagnostics])->save();
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
