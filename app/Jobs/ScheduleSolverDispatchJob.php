<?php

namespace App\Jobs;

use App\Actions\Integrations\SchedulingSolver\SchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverTransportException;
use App\Actions\Scheduling\ScheduleCloudResultIngestor;
use App\Actions\Scheduling\ScheduleSolverDispatchLifecycleService;
use App\Actions\Scheduling\ScheduleSolverSnapshotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\TimeoutExceededException;
use RuntimeException;
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
        return [60, 300];
    }

    public function handle(
        ScheduleSolverSnapshotService $snapshotService,
        SchedulingSolverClient $solverClient,
        ScheduleCloudResultIngestor $resultIngestor,
        ?ScheduleSolverDispatchLifecycleService $lifecycleService = null,
    ): void {
        $lifecycleService ??= app(ScheduleSolverDispatchLifecycleService::class);
        $attempt = max(1, $this->attempts());
        $context = $lifecycleService->claim($this->scheduleGenerationRunId, $attempt);

        if ($context === null) {
            return;
        }

        $startedAt = hrtime(true);

        try {
            $snapshot = $snapshotService->captureForRun($context['run']);
            $solverResult = $solverClient->solve($snapshot);
            $ingestionSummary = $resultIngestor->ingest($context['run'], $solverResult);

            $lifecycleService->markProcessed(
                $context['run'],
                $context['event'],
                $this->elapsedMilliseconds($startedAt),
                $this->resultSummary($solverResult),
                $this->ingestionSummary($ingestionSummary),
            );
        } catch (Throwable $exception) {
            $transportException = $exception instanceof SchedulingSolverTransportException
                ? $exception
                : SchedulingSolverTransportException::unexpected($exception);
            $final = ! $transportException->isRetryable() || $attempt >= $this->tries;

            $lifecycleService->markFailed(
                $context['run'],
                $context['event'],
                $transportException,
                $this->elapsedMilliseconds($startedAt),
                $final,
            );

            if ($final) {
                $this->fail($transportException);

                return;
            }

            throw $transportException;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $transportException = match (true) {
            $exception instanceof SchedulingSolverTransportException => $exception,
            $exception instanceof TimeoutExceededException => SchedulingSolverTransportException::retryable(
                SchedulingSolverTransportException::ClassificationTimeout,
                'Scheduling solver attempt timed out.',
                previous: $exception,
            ),
            default => SchedulingSolverTransportException::unexpected(
                $exception ?? new RuntimeException('Scheduling solver dispatch failed.'),
            ),
        };

        app(ScheduleSolverDispatchLifecycleService::class)->finalizeUnhandledFailure(
            $this->scheduleGenerationRunId,
            max(1, $this->attempts()),
            $transportException,
        );
    }

    /**
     * @param  array<string, mixed>  $ingestionSummary
     * @return array<string, mixed>
     */
    private function ingestionSummary(array $ingestionSummary): array
    {
        return [
            'status' => $ingestionSummary['status'] ?? null,
            'candidate_row_count' => $ingestionSummary['candidate_row_count'] ?? null,
            'ok_count' => $ingestionSummary['ok_count'] ?? null,
            'warning_count' => $ingestionSummary['warning_count'] ?? null,
            'conflict_count' => $ingestionSummary['conflict_count'] ?? null,
            'rejected_count' => $ingestionSummary['rejected_count'] ?? null,
        ];
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

    private function elapsedMilliseconds(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
