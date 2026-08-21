<?php

namespace App\Actions\Scheduling;

use App\Models\CandidateScheduleRow;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
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
        private readonly SchedulePublicationImpactService $impactService,
        private readonly ScheduleReleaseNotificationService $releaseNotifications,
        private readonly PublishTimetableVersion $publishTimetableVersion,
    ) {}

    public function publish(
        ScheduleGenerationRun $run,
        User $publisher,
        ?string $note = null,
        bool $acceptLowerQuality = false,
        ?string $authorityReference = null,
    ): ScheduleGenerationRun {
        Gate::forUser($publisher)->authorize('publish', $run);
        $note = $this->normalizedNote($note);
        $authorityReference = $this->normalizedNote($authorityReference);

        $outcome = DB::transaction(function () use ($run, $publisher, $note, $acceptLowerQuality, $authorityReference): array {
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
                    'room',
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

            $this->assertPublicationReason($lockedRun, $candidateRows, $note, $acceptLowerQuality, $authorityReference);

            $currentPublishedRun = $termRuns->first(
                fn (ScheduleGenerationRun $termRun): bool => $termRun->status === ScheduleGenerationRun::StatusPublished
                    && ! $termRun->is($lockedRun),
            );
            $impact = $this->impactService->lockForPublication(
                $currentPublishedRun instanceof ScheduleGenerationRun ? $currentPublishedRun : null,
                $candidateRows,
            );

            if ($impact->blocksFullReplacement()) {
                throw ValidationException::withMessages([
                    'publication_impact' => sprintf(
                        'Full replacement is blocked because the current published schedule has %d active student %s. Use the controlled live-revision workflow instead.',
                        $impact->activeOfficialRegistrations(),
                        $impact->activeOfficialRegistrations() === 1 ? 'official registration' : 'official registrations',
                    ),
                ]);
            }

            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $publicationVersion = ((int) ($termRuns->max('publication_version') ?? 0)) + 1;
            $authorityReference = $this->authorityReference($lockedRun, $authorityReference);

            ScheduleGenerationRun::query()
                ->where('term_id', $lockedRun->term_id)
                ->where('status', ScheduleGenerationRun::StatusPublished)
                ->whereKeyNot($lockedRun->getKey())
                ->update([
                    'status' => ScheduleGenerationRun::StatusSuperseded,
                    'updated_at' => $timestamp,
                ]);

            $timetableVersion = $this->publishTimetableVersion->createLocked(
                run: $lockedRun,
                publisher: $publisher,
                candidateRows: $candidateRows,
                authorityReference: $authorityReference,
                reason: $note,
                sourceVersions: [
                    'contract_version' => $lockedRun->contract_version,
                    'solver_version' => $lockedRun->solver_version,
                    'model_version' => $lockedRun->model_version,
                    'input_hash' => $lockedRun->input_hash,
                ],
                impactSummary: $impact->toArray(),
            );

            foreach ($candidateRows as $candidateRow) {
                SectionMeeting::query()->create([
                    'schedule_run_id' => $lockedRun->id,
                    'published_timetable_version_id' => $timetableVersion->id,
                    'scheduling_demand_id' => $candidateRow->scheduling_demand_id,
                    'meeting_sequence' => $candidateRow->meeting_sequence,
                    'faculty_user_id' => $candidateRow->faculty_user_id,
                    'room_id' => $candidateRow->room_id,
                    'day_of_week' => $candidateRow->day_of_week,
                    'starts_at' => $candidateRow->starts_at,
                    'ends_at' => $candidateRow->ends_at,
                    'modality' => $this->impactService->modalityFor($candidateRow),
                    'state' => SectionMeeting::StateActive,
                    'published_at' => $timestamp,
                ]);
            }

            $this->activatePublishedSources($candidateRows);

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
                $note,
                $acceptLowerQuality,
                $impact,
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

        $publishedRun = $outcome['run'];
        $this->releaseNotifications->recordPublishedRun($publishedRun);

        return $publishedRun;
    }

    /**
     * @param  Collection<int, CandidateScheduleRow>  $candidateRows
     */
    private function activatePublishedSources(Collection $candidateRows): void
    {
        $termOfferingIds = $candidateRows
            ->pluck('schedulingDemand.term_offering_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $sectionIds = $candidateRows
            ->pluck('schedulingDemand.sectionDeliveryGroup.section_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        TermOffering::query()
            ->whereKey($termOfferingIds)
            ->where('state', TermOffering::StatePendingScheduling)
            ->update(['state' => TermOffering::StateScheduled]);

        Section::query()
            ->whereKey($sectionIds)
            ->where('state', Section::StatePlanned)
            ->update(['state' => Section::StateOpen]);
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

        if ($run->contract_version === ScheduleGenerationRun::ContractVersion
            && $run->candidate_state !== 'Accepted') {
            throw ValidationException::withMessages([
                'candidate_state' => 'The Registrar must explicitly accept this immutable candidate before publication.',
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

    /**
     * @param  Collection<int, CandidateScheduleRow>  $candidateRows
     */
    private function assertPublicationReason(
        ScheduleGenerationRun $run,
        Collection $candidateRows,
        ?string $note,
        bool $acceptLowerQuality,
        ?string $authorityReference,
    ): void {
        if ($run->contract_version === ScheduleGenerationRun::ContractVersion
            && ($note === null || $authorityReference === null)) {
            throw ValidationException::withMessages([
                'publication_note' => 'Publication requires a distinct external timetable sign-off reference and publication reason.',
            ]);
        }

        $hasWarnings = $candidateRows->contains(
            fn (CandidateScheduleRow $candidateRow): bool => $candidateRow->hasWarnings(),
        );

        if (($hasWarnings || $acceptLowerQuality) && $note === null) {
            throw ValidationException::withMessages([
                'publication_note' => 'A publication note is required when accepting advisory warnings or a lower soft-quality result.',
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

    private function authorityReference(ScheduleGenerationRun $run, ?string $note): string
    {
        if ($run->contract_version === ScheduleGenerationRun::ContractVersion && $note === null) {
            throw ValidationException::withMessages([
                'publication_note' => 'Recorded external timetable sign-off is required before publication.',
            ]);
        }

        return $note ?? "Legacy published schedule run {$run->id}";
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
        ?string $publicationNote,
        bool $acceptLowerQuality,
        SchedulePublicationImpact $impact,
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
                'publication_note' => $publicationNote,
                'accepted_lower_quality' => $acceptLowerQuality,
                'impact' => $impact->toArray(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }
}
