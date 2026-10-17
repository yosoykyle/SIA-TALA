<?php

namespace App\Actions\Scheduling;

use App\Jobs\ScheduleSolverDispatchJob;
use App\Models\CandidateScheduleRow;
use App\Models\ScheduleGenerationRun;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ScheduleSolverRetryService
{
    public function __construct(
        private readonly ScheduleGenerationService $generationService,
        private readonly RequestTimetableRepair $repairService,
        private readonly ScheduleSolverSnapshotService $snapshotService,
    ) {}

    public function recover(ScheduleGenerationRun $run, User $actor, string $correctionExplanation): ScheduleGenerationRun
    {
        Gate::forUser($actor)->authorize('recover', $run);

        return DB::transaction(function () use ($run, $actor, $correctionExplanation): ScheduleGenerationRun {
            $term = Term::query()->lockForUpdate()->findOrFail($run->term_id);
            $failed = ScheduleGenerationRun::query()->lockForUpdate()->findOrFail($run->id);
            Gate::forUser($actor)->authorize('recover', $failed);

            if (data_get($failed->input_snapshot, 'operation.kind') !== 'repair') {
                return $this->generationService->generate($term, $actor, $failed, $correctionExplanation);
            }

            $operation = $failed->input_snapshot['operation'];
            $source = ScheduleGenerationRun::query()->where('term_id', $term->id)->findOrFail(data_get($operation, 'source_candidate.run_id'));
            $this->snapshotService->currentForRun($source);
            $assignment = $operation['requested_assignment'];
            $row = CandidateScheduleRow::query()
                ->where('schedule_run_id', data_get($operation, 'source_candidate.run_id'))
                ->where('scheduling_demand_id', $assignment['scheduling_demand_id'])
                ->where('meeting_sequence', $assignment['meeting_sequence'])
                ->first();

            if (! $row instanceof CandidateScheduleRow) {
                throw ValidationException::withMessages(['recovery_source' => 'The original repair candidate meeting is unavailable.']);
            }

            unset($assignment['scheduling_demand_id'], $assignment['meeting_sequence']);

            return $this->repairService->execute($row, $actor, $assignment, $operation['reason'], $operation['authority_reference'], $failed, $correctionExplanation);
        }, 3);
    }

    public function retry(ScheduleGenerationRun $run, User $actor): ScheduleGenerationRun
    {
        Gate::forUser($actor)->authorize('retry', $run);

        return DB::transaction(function () use ($run, $actor): ScheduleGenerationRun {
            Term::query()->lockForUpdate()->findOrFail($run->term_id);

            /** @var ScheduleGenerationRun $lockedRun */
            $lockedRun = ScheduleGenerationRun::query()->lockForUpdate()->findOrFail($run->id);

            if (! $lockedRun->canRetrySolver()) {
                throw ValidationException::withMessages([
                    'status' => 'Retry requires an Unknown result or a final retryable technical failure without retained candidate data.',
                ]);
            }

            $anotherActiveRunExists = ScheduleGenerationRun::query()
                ->where('term_id', $lockedRun->term_id)
                ->whereKeyNot($lockedRun->id)
                ->whereIn('status', [
                    ScheduleGenerationRun::StatusQueued,
                    ScheduleGenerationRun::StatusDispatching,
                ])
                ->exists();

            if ($anotherActiveRunExists) {
                throw ValidationException::withMessages([
                    'term_id' => 'Another queued or dispatching solver run already exists for this term.',
                ]);
            }

            $timestamp = CarbonImmutable::now(config('app.timezone'));

            if (data_get($lockedRun->diagnostics, 'solver_result.solver_status') === 'unknown') {
                if ((int) ScheduleGenerationRun::query()->where('term_id', $lockedRun->term_id)->max('id') !== (int) $lockedRun->id) {
                    throw ValidationException::withMessages(['status' => 'Retry search from the latest run for this exact Term.']);
                }

                $successor = $lockedRun->replicate();
                $successor->forceFill([
                    'status' => ScheduleGenerationRun::StatusQueued,
                    'requested_by' => $actor->id,
                    'input_hash' => hash('sha256', $lockedRun->input_hash.'|unknown-retry|'.Str::uuid()),
                    'solver_version' => 'pending-dispatch',
                    'model_version' => null,
                    'runtime_ms' => null,
                    'objective_value' => null,
                    'quality_measures' => null,
                    'candidate_state' => data_get($lockedRun->input_snapshot, 'operation.kind') === 'repair' ? 'RepairQueued' : 'Queued',
                    'diagnostics' => ['solver_dispatch' => [
                        'status' => 'queued',
                        'dispatch_cycle' => 1,
                        'last_attempt' => 0,
                        'latest_outcome' => 'unknown_retry_queued',
                        'queued_at' => $timestamp->toIso8601String(),
                        'retried_by' => $actor->id,
                        'source_unknown_run_id' => $lockedRun->id,
                        'driver' => config('tala_integrations.scheduling_solver.driver', 'local_stub'),
                    ]],
                ])->save();
                $snapshot = $successor->input_snapshot;
                $snapshot['run_metadata']['solver_run_id'] = (int) $successor->id;
                $snapshot['run_metadata']['requested_by'] = (int) $actor->id;
                $successor->forceFill([
                    'input_snapshot' => $snapshot,
                    'input_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                ])->save();
                ScheduleSolverDispatchJob::dispatch((int) $successor->id)->afterCommit();

                return $successor;
            }

            $diagnostics = $this->arrayValue($lockedRun->getAttribute('diagnostics'));
            $dispatch = $this->arrayValue($diagnostics['solver_dispatch'] ?? null);
            $previousFailure = $this->arrayValue($dispatch['failure'] ?? null);
            unset($dispatch['failure']);
            $diagnostics['solver_dispatch'] = [
                ...$dispatch,
                'status' => 'queued',
                'dispatch_cycle' => $lockedRun->dispatchCycle() + 1,
                'last_attempt' => 0,
                'latest_outcome' => 'retry_queued',
                'queued_at' => $timestamp->toIso8601String(),
                'retried_at' => $timestamp->toIso8601String(),
                'retried_by' => $actor->id,
                'previous_failure' => $previousFailure,
            ];

            $lockedRun->forceFill([
                'status' => ScheduleGenerationRun::StatusQueued,
                'diagnostics' => $diagnostics,
            ])->save();

            ScheduleSolverDispatchJob::dispatch((int) $lockedRun->id)->afterCommit();

            return $lockedRun;
        }, 3);
    }

    /** @return array<string, mixed> */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
