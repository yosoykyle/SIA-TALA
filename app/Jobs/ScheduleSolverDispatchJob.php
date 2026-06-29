<?php

namespace App\Jobs;

use App\Actions\Integrations\SchedulingSolver\SchedulingSolverClient;
use App\Actions\Scheduling\ScheduleCloudResultIngestor;
use App\Actions\Scheduling\ScheduleSolverSnapshotService;
use App\Models\ScheduleGenerationRun;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ScheduleSolverDispatchJob implements ShouldQueue
{
    use Queueable;

    /**
     * @var int
     */
    public $tries = 3;

    /**
     * @var int
     */
    public $timeout = 360;

    public function __construct(public readonly int $scheduleGenerationRunId)
    {
        $this->onQueue('scheduling');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(
        ScheduleSolverSnapshotService $snapshotService,
        SchedulingSolverClient $solverClient,
        ScheduleCloudResultIngestor $resultIngestor,
    ): void {
        $run = ScheduleGenerationRun::query()->findOrFail($this->scheduleGenerationRunId);
        $snapshot = $snapshotService->captureForRun($run);

        $run->forceFill([
            'status' => ScheduleGenerationRun::StatusDispatching,
        ])->save();

        try {
            $solverResult = $solverClient->solve($snapshot);
        } catch (Throwable $exception) {
            $this->recordDispatchSummary($run, [
                'status' => 'failed',
                'failed_at' => CarbonImmutable::now(config('app.timezone'))->toIso8601String(),
                'driver' => config('tala_integrations.scheduling_solver.driver', 'local_stub'),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $run->forceFill([
                'status' => ScheduleGenerationRun::StatusFailed,
            ])->save();

            throw $exception;
        }

        $ingestionSummary = $resultIngestor->ingest($run, $solverResult);

        $this->recordDispatchSummary($run, [
            'status' => 'completed',
            'completed_at' => CarbonImmutable::now(config('app.timezone'))->toIso8601String(),
            'driver' => config('tala_integrations.scheduling_solver.driver', 'local_stub'),
            'result_summary' => $this->resultSummary($solverResult),
            'ingestion_summary' => [
                'status' => $ingestionSummary['status'],
                'candidate_row_count' => $ingestionSummary['candidate_row_count'],
                'ok_count' => $ingestionSummary['ok_count'],
                'warning_count' => $ingestionSummary['warning_count'],
                'conflict_count' => $ingestionSummary['conflict_count'],
                'rejected_count' => $ingestionSummary['rejected_count'],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function recordDispatchSummary(ScheduleGenerationRun $run, array $summary): void
    {
        $run->refresh();
        $diagnostics = $this->arrayValue($run->getAttribute('diagnostics'));
        $diagnostics['solver_dispatch'] = [
            ...($diagnostics['solver_dispatch'] ?? []),
            ...$summary,
        ];

        $run->forceFill([
            'diagnostics' => $diagnostics,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $solverResult
     * @return array<string, mixed>
     */
    private function resultSummary(array $solverResult): array
    {
        return [
            'solver_status' => $solverResult['solver_status'] ?? null,
            'candidate_schedule_id' => $solverResult['candidate_schedule_id'] ?? null,
            'assigned_count' => $this->integerResult($solverResult, 'assigned_count'),
            'unassigned_count' => $this->integerResult($solverResult, 'unassigned_count'),
            'hard_violation_count' => $this->integerResult($solverResult, 'hard_violation_count'),
            'warning_count' => $this->integerResult($solverResult, 'warning_count'),
            'timeout' => (bool) ($solverResult['timeout'] ?? false),
            'objective_score' => $solverResult['objective_score'] ?? null,
            'runtime_seconds' => $solverResult['runtime_seconds'] ?? null,
            'assignment_count' => is_countable($solverResult['assignments'] ?? null)
                ? count($solverResult['assignments'])
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $solverResult
     */
    private function integerResult(array $solverResult, string $key): ?int
    {
        return array_key_exists($key, $solverResult) && $solverResult[$key] !== null
            ? (int) $solverResult[$key]
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
