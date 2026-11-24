<?php

namespace App\Actions\Scheduling;

use App\Models\CandidateScheduleRow;
use App\Models\ScheduleGenerationRun;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RevertCandidateRun
{
    public const EventUndo = 'candidate_undo';

    public const EventRevertedToSolver = 'candidate_reverted_to_solver';

    public function __construct(private readonly ScheduleAssignmentRevalidationService $revalidator) {}

    public function execute(
        ScheduleGenerationRun $currentRun,
        ScheduleGenerationRun $targetRun,
        User $actor,
        string $event = self::EventUndo,
    ): ScheduleGenerationRun {
        Gate::forUser($actor)->authorize('reviewCandidates', $currentRun);

        if (! in_array($event, [self::EventUndo, self::EventRevertedToSolver], true)) {
            throw ValidationException::withMessages([
                'event' => 'Unsupported candidate revert event.',
            ]);
        }

        return DB::transaction(function () use ($currentRun, $targetRun, $actor, $event): ScheduleGenerationRun {
            $term = Term::query()->whereKey($currentRun->term_id)->lockForUpdate()->firstOrFail();

            if ($term->state === Term::StateClosed) {
                throw ValidationException::withMessages([
                    'term' => 'This term is closed and its timetable cannot be reverted.',
                ]);
            }

            $lockedCurrent = ScheduleGenerationRun::query()->whereKey($currentRun->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('reviewCandidates', $lockedCurrent);

            if ($lockedCurrent->status !== ScheduleGenerationRun::StatusUnderReview
                || in_array($lockedCurrent->candidate_state, ['Rejected', 'Stale', 'Superseded'], true)) {
                throw ValidationException::withMessages([
                    'candidate' => 'Only the current non-stale draft candidate can be reverted.',
                ]);
            }

            $lockedTarget = ScheduleGenerationRun::query()->whereKey($targetRun->id)->lockForUpdate()->firstOrFail();

            if ((int) $lockedTarget->term_id !== (int) $lockedCurrent->term_id) {
                throw ValidationException::withMessages([
                    'target_run' => 'The target run must belong to the same term.',
                ]);
            }

            if ((int) $lockedTarget->id === (int) $lockedCurrent->id) {
                throw ValidationException::withMessages([
                    'target_run' => 'Choose an earlier ancestor draft to revert to.',
                ]);
            }

            $lineage = $this->resolveAncestorLineage($lockedCurrent, $lockedTarget);

            if (! $lineage['is_ancestor']) {
                throw ValidationException::withMessages([
                    'target_run' => 'The target run must be an ancestor of the current draft candidate.',
                ]);
            }

            /** @var ScheduleGenerationRun $rootSolverRun */
            $rootSolverRun = $lineage['root_run'];

            $currentRows = CandidateScheduleRow::query()
                ->where('schedule_run_id', $lockedCurrent->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $targetRows = CandidateScheduleRow::query()
                ->where('schedule_run_id', $lockedTarget->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($targetRows->isEmpty()) {
                throw ValidationException::withMessages([
                    'target_run' => 'The target draft has no retained schedule assignments to restore.',
                ]);
            }

            $payloads = $targetRows->map(fn (CandidateScheduleRow $row): array => $this->payload($row))->values()->all();
            $validation = $this->revalidator->validateCandidateSet($lockedCurrent, $payloads);

            if (! $validation->passes()) {
                throw ValidationException::withMessages([
                    'candidate' => $validation->blockingFindings()[0]['message'] ?? 'The restored draft violates a current hard scheduling rule.',
                ]);
            }

            $targetParentId = self::parentRunId($lockedTarget);
            $isRevertingToRoot = $targetParentId === null && ! self::isLocalAdjustment($lockedTarget);

            $currentSnapshot = is_array($lockedCurrent->input_snapshot) ? $lockedCurrent->input_snapshot : [];
            $targetSnapshot = is_array($lockedTarget->input_snapshot) ? $lockedTarget->input_snapshot : [];
            $targetDiagnostics = is_array($lockedTarget->diagnostics) ? $lockedTarget->diagnostics : [];
            $rootDiagnostics = is_array($rootSolverRun->diagnostics) ? $rootSolverRun->diagnostics : [];

            $reason = $event === self::EventRevertedToSolver
                ? sprintf('Reverted draft to original solver schedule (Attempt #%d)', $lockedTarget->id)
                : sprintf('Undid last manual draft change (restored Attempt #%d)', $lockedTarget->id);

            $revertMetadata = [
                'event' => $event,
                'reverted_from_run_id' => (int) $lockedCurrent->id,
                'reverted_to_run_id' => (int) $lockedTarget->id,
                'root_solver_run_id' => (int) $rootSolverRun->id,
                'source_run_id' => $targetParentId,
                'reason' => $reason,
                'authority' => (string) $actor->id,
            ];

            $rootSourceEvidence = [
                'run_id' => (int) $rootSolverRun->id,
                'solver_version' => $rootSolverRun->solver_version,
                'runtime_ms' => $rootSolverRun->runtime_ms,
                'result' => $rootDiagnostics['solver_result'] ?? null,
                'dispatch' => $rootDiagnostics['solver_dispatch'] ?? null,
            ];

            $nextSnapshot = $currentSnapshot;
            unset($nextSnapshot['candidate_adjustment']);

            if (! $isRevertingToRoot && is_array($targetSnapshot['candidate_adjustment'] ?? null)) {
                $nextSnapshot['candidate_adjustment'] = $targetSnapshot['candidate_adjustment'];
            } elseif (! $isRevertingToRoot && $targetParentId !== null) {
                $nextSnapshot['candidate_adjustment'] = [
                    'source_run_id' => $targetParentId,
                    'source_candidate_version' => $lockedTarget->candidate_version,
                    'reason' => $reason,
                    'authority' => (string) $actor->id,
                ];
            }

            $nextSnapshot['candidate_revert'] = $revertMetadata;

            if ($isRevertingToRoot) {
                $nextDiagnostics = $rootDiagnostics;
                unset($nextDiagnostics['candidate_origin'], $nextDiagnostics['quality_comparison']);
                $nextDiagnostics['root_solver_run_id'] = (int) $rootSolverRun->id;
                $nextDiagnostics['source_solver_evidence'] = $rootSourceEvidence;
                $nextDiagnostics['candidate_revert'] = $revertMetadata;
                $nextDiagnostics['current_revalidation'] = [
                    'status' => 'accepted',
                    'context' => $event,
                    'findings' => [],
                    'summary' => $validation->summary(),
                ];
            } else {
                $nextDiagnostics = $targetDiagnostics;
                unset($nextDiagnostics['solver_result'], $nextDiagnostics['solver_dispatch']);
                $nextDiagnostics['candidate_origin'] = 'LocalAdjustment';
                $nextDiagnostics['root_solver_run_id'] = (int) $rootSolverRun->id;
                $nextDiagnostics['source_solver_evidence'] = $rootSourceEvidence;
                $nextDiagnostics['candidate_revert'] = $revertMetadata;
                $nextDiagnostics['current_revalidation'] = [
                    'status' => 'accepted',
                    'context' => $event,
                    'findings' => [],
                    'summary' => $validation->summary(),
                ];
                $nextDiagnostics['quality_comparison'] = [
                    'status' => 'requires_attributable_review',
                    'reason' => 'A local correction is hard-constraint validated but is not re-optimized by CP-SAT.',
                    'source_run_id' => $targetParentId,
                ];
            }

            $successor = $lockedCurrent->replicate();
            $successor->forceFill([
                'status' => ScheduleGenerationRun::StatusUnderReview,
                'input_snapshot' => $nextSnapshot,
                'input_hash' => hash(
                    'sha256',
                    $lockedCurrent->input_hash.'|'.$event.'|'.json_encode($revertMetadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).'|'.Str::uuid(),
                ),
                'objective_value' => $isRevertingToRoot ? $rootSolverRun->objective_value : null,
                'quality_measures' => $isRevertingToRoot ? $rootSolverRun->quality_measures : null,
                'runtime_ms' => $isRevertingToRoot ? $rootSolverRun->runtime_ms : null,
                'solver_version' => $isRevertingToRoot
                    ? $rootSolverRun->solver_version
                    : 'local-adjustment-hard-validation',
                'diagnostics' => $nextDiagnostics,
                'candidate_key' => ($isRevertingToRoot ? 'solver-revert-' : 'local-revert-').Str::uuid(),
                'candidate_version' => ((int) $lockedCurrent->candidate_version) + 1,
                'candidate_state' => 'UnderReview',
                'candidate_reviewed_by' => null,
                'candidate_reviewed_at' => null,
                'candidate_review_reason' => $reason,
                'published_by' => null,
                'published_at' => null,
                'publication_version' => null,
            ])->save();

            $currentRowsByKey = $currentRows->keyBy(
                fn (CandidateScheduleRow $row): string => $row->scheduling_demand_id.':'.$row->meeting_sequence,
            );

            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $timestampString = $timestamp->toDateTimeString();
            $rowsToInsert = [];

            foreach ($targetRows as $index => $targetRow) {
                $payload = $payloads[$index];
                $rowKey = $payload['scheduling_demand_id'].':'.$payload['meeting_sequence'];
                $matchingCurrent = $currentRowsByKey->get($rowKey);

                $changedFromCurrent = ! $matchingCurrent instanceof CandidateScheduleRow
                    || (int) $matchingCurrent->day_of_week !== (int) $payload['day_of_week']
                    || (string) $matchingCurrent->starts_at !== (string) $payload['starts_at']
                    || (string) $matchingCurrent->ends_at !== (string) $payload['ends_at']
                    || (int) $matchingCurrent->faculty_user_id !== (int) $payload['faculty_user_id']
                    || ($matchingCurrent->room_id !== null ? (int) $matchingCurrent->room_id : null) !== $payload['room_id'];

                $rowsToInsert[] = [
                    'schedule_run_id' => $successor->id,
                    'supersedes_candidate_row_id' => $matchingCurrent instanceof CandidateScheduleRow
                        ? $matchingCurrent->id
                        : $targetRow->id,
                    'scheduling_demand_id' => $payload['scheduling_demand_id'],
                    'meeting_sequence' => $payload['meeting_sequence'],
                    'faculty_user_id' => $payload['faculty_user_id'],
                    'room_id' => $payload['room_id'],
                    'day_of_week' => $payload['day_of_week'],
                    'starts_at' => $payload['starts_at'],
                    'ends_at' => $payload['ends_at'],
                    'time_block_key' => $payload['time_block_key'],
                    'status' => $payload['status'],
                    'change_type' => $changedFromCurrent ? 'Reverted' : 'Unchanged',
                    'scores' => is_array($payload['scores'] ?? null)
                        ? json_encode($payload['scores'], JSON_THROW_ON_ERROR)
                        : ($payload['scores'] ?? null),
                    'warnings' => is_array($payload['warnings'] ?? null)
                        ? json_encode($payload['warnings'], JSON_THROW_ON_ERROR)
                        : ($payload['warnings'] ?? null),
                    'violations' => is_array($payload['violations'] ?? null)
                        ? json_encode($payload['violations'], JSON_THROW_ON_ERROR)
                        : ($payload['violations'] ?? null),
                    'override_authority' => (string) $actor->id,
                    'override_reason' => $reason,
                    'created_at' => $timestampString,
                    'updated_at' => $timestampString,
                ];
            }

            if ($rowsToInsert !== []) {
                foreach (array_chunk($rowsToInsert, 1000) as $chunk) {
                    CandidateScheduleRow::query()->insert($chunk);
                }
            }

            $lockedCurrent->forceFill([
                'status' => ScheduleGenerationRun::StatusSuperseded,
                'candidate_state' => 'Superseded',
            ])->save();

            DB::table('activity_log')->insert([
                'log_name' => 'scheduling',
                'description' => $event === self::EventRevertedToSolver
                    ? 'Candidate schedule reverted to original solver draft as an immutable successor.'
                    : 'Candidate schedule adjustment undone as an immutable successor.',
                'subject_type' => ScheduleGenerationRun::class,
                'subject_id' => $successor->id,
                'event' => $event,
                'causer_type' => User::class,
                'causer_id' => $actor->id,
                'properties' => json_encode([
                    'reverted_from_run_id' => (int) $lockedCurrent->id,
                    'reverted_to_run_id' => (int) $lockedTarget->id,
                    'root_solver_run_id' => (int) $rootSolverRun->id,
                    'source_run_id' => $targetParentId,
                    'reason' => $reason,
                    'validation_result' => 'accepted',
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'created_at' => $timestampString,
                'updated_at' => $timestampString,
            ]);

            return $successor->fresh('candidateRows');
        }, attempts: 5);
    }

    public static function isLocalAdjustment(ScheduleGenerationRun $run): bool
    {
        return data_get($run->diagnostics, 'candidate_origin') === 'LocalAdjustment';
    }

    public static function isIntermediateAdjustmentSnapshot(ScheduleGenerationRun $run): bool
    {
        if ($run->status !== ScheduleGenerationRun::StatusSuperseded) {
            return false;
        }

        return self::isLocalAdjustment($run)
            || data_get($run->diagnostics, 'candidate_revert') !== null
            || data_get($run->input_snapshot, 'candidate_adjustment') !== null;
    }

    public static function parentRunId(ScheduleGenerationRun $run): ?int
    {
        if (! self::isLocalAdjustment($run)) {
            return null;
        }

        $candidateId = data_get($run->input_snapshot, 'candidate_adjustment.source_run_id')
            ?? data_get($run->diagnostics, 'quality_comparison.source_run_id')
            ?? data_get($run->diagnostics, 'source_solver_evidence.run_id');

        if (! is_numeric($candidateId) || (int) $candidateId <= 0 || (int) $candidateId === (int) $run->id) {
            return null;
        }

        return (int) $candidateId;
    }

    public static function rootSolverRun(ScheduleGenerationRun $run): ?ScheduleGenerationRun
    {
        if (! self::isLocalAdjustment($run)) {
            return null;
        }

        $cursor = $run;
        $visited = [(int) $run->id => true];
        $steps = 0;

        while ($steps < 200) {
            $parentId = self::parentRunId($cursor);
            if ($parentId === null) {
                $revertedToId = data_get($cursor->diagnostics, 'candidate_revert.reverted_to_run_id');
                if (is_numeric($revertedToId) && (int) $revertedToId > 0 && (int) $revertedToId !== (int) $cursor->id) {
                    $parentId = (int) $revertedToId;
                }
            }

            if ($parentId === null || isset($visited[$parentId])) {
                break;
            }

            $parent = ScheduleGenerationRun::query()
                ->where('term_id', $run->term_id)
                ->whereKey($parentId)
                ->first();

            if (! $parent instanceof ScheduleGenerationRun) {
                break;
            }

            $visited[$parentId] = true;
            $cursor = $parent;
            $steps++;
        }

        return (int) $cursor->id !== (int) $run->id ? $cursor : null;
    }

    public static function manualEditCount(ScheduleGenerationRun $run): int
    {
        if (! self::isLocalAdjustment($run)) {
            return 0;
        }

        $cursor = $run;
        $visited = [(int) $run->id => true];
        $count = 0;

        while ($count < 200) {
            $parentId = self::parentRunId($cursor);
            if ($parentId === null || isset($visited[$parentId])) {
                break;
            }

            $count++;

            $parent = ScheduleGenerationRun::query()
                ->where('term_id', $run->term_id)
                ->whereKey($parentId)
                ->first(['id', 'term_id', 'diagnostics', 'input_snapshot']);

            if (! $parent instanceof ScheduleGenerationRun) {
                break;
            }

            $visited[$parentId] = true;
            $cursor = $parent;
        }

        return $count;
    }

    /**
     * @return array{is_ancestor: bool, root_run: ScheduleGenerationRun}
     */
    private function resolveAncestorLineage(ScheduleGenerationRun $currentRun, ScheduleGenerationRun $targetRun): array
    {
        $cursor = $currentRun;
        $visited = [(int) $currentRun->id => true];
        $isAncestor = false;
        $steps = 0;

        while ($steps < 200) {
            $parentId = self::parentRunId($cursor);
            if ($parentId === null) {
                $revertedToId = data_get($cursor->diagnostics, 'candidate_revert.reverted_to_run_id');
                if (is_numeric($revertedToId) && (int) $revertedToId > 0 && (int) $revertedToId !== (int) $cursor->id) {
                    $parentId = (int) $revertedToId;
                }
            }

            if ($parentId === null || isset($visited[$parentId])) {
                break;
            }

            $parent = ScheduleGenerationRun::query()
                ->where('term_id', $currentRun->term_id)
                ->whereKey($parentId)
                ->first();

            if (! $parent instanceof ScheduleGenerationRun) {
                break;
            }

            if ((int) $parent->id === (int) $targetRun->id) {
                $isAncestor = true;
            }

            $visited[$parentId] = true;
            $cursor = $parent;
            $steps++;
        }

        return [
            'is_ancestor' => $isAncestor,
            'root_run' => $cursor,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CandidateScheduleRow $row): array
    {
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
            'scores' => $row->scores ?? [],
            'warnings' => $row->warnings ?? [],
            'violations' => $row->violations ?? [],
        ];
    }
}
