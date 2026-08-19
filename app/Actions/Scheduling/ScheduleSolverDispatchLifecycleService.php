<?php

namespace App\Actions\Scheduling;

use App\Actions\Integrations\SchedulingSolver\SchedulingSolverTransportException;
use App\Models\OperationalEvent;
use App\Models\ScheduleGenerationRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ScheduleSolverDispatchLifecycleService
{
    /**
     * @return array{run: ScheduleGenerationRun, event: OperationalEvent}|null
     */
    public function claim(int $scheduleGenerationRunId, int $attempt): ?array
    {
        return DB::transaction(function () use ($scheduleGenerationRunId, $attempt): ?array {
            /** @var ScheduleGenerationRun $run */
            $run = ScheduleGenerationRun::query()
                ->lockForUpdate()
                ->findOrFail($scheduleGenerationRunId);

            if (! in_array($run->status, [
                ScheduleGenerationRun::StatusQueued,
                ScheduleGenerationRun::StatusDispatching,
            ], true)) {
                return null;
            }

            $diagnostics = $this->arrayValue($run->getAttribute('diagnostics'));
            $dispatch = $this->arrayValue($diagnostics['solver_dispatch'] ?? null);
            $cycle = max(1, (int) ($dispatch['dispatch_cycle'] ?? 1));
            $lastAttempt = max(0, (int) ($dispatch['last_attempt'] ?? 0));

            if ($attempt <= $lastAttempt) {
                return null;
            }

            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $this->closeAbandonedAttempts($run, $cycle, $attempt, $timestamp);

            $event = OperationalEvent::query()->create([
                'event_domain' => OperationalEvent::DomainIntegration,
                'integration' => OperationalEvent::IntegrationSchedulingSolver,
                'channel' => 'queue',
                'direction' => 'OUTBOUND',
                'event_type' => OperationalEvent::TypeSolverDispatchAttempt,
                'event_version' => '1',
                'user_id' => $run->requested_by,
                'external_id' => $this->externalId($run, $cycle, $attempt),
                'status' => OperationalEvent::StatusPending,
                'occurred_at' => $timestamp,
                'related_record_type' => ScheduleGenerationRun::class,
                'related_record_id' => $run->id,
                'diagnostics' => [
                    'cycle' => $cycle,
                    'attempt' => $attempt,
                    'driver' => config('tala_integrations.scheduling_solver.driver', 'local_stub'),
                ],
            ]);

            unset($dispatch['failure']);
            $diagnostics['solver_dispatch'] = [
                ...$dispatch,
                'status' => 'dispatching',
                'dispatch_cycle' => $cycle,
                'last_attempt' => $attempt,
                'latest_outcome' => 'pending',
                'started_at' => $timestamp->toIso8601String(),
                'driver' => config('tala_integrations.scheduling_solver.driver', 'local_stub'),
            ];

            $run->forceFill([
                'status' => ScheduleGenerationRun::StatusDispatching,
                'diagnostics' => $diagnostics,
            ])->save();

            return ['run' => $run, 'event' => $event];
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $resultSummary
     * @param  array<string, mixed>  $ingestionSummary
     */
    public function markProcessed(
        ScheduleGenerationRun $run,
        OperationalEvent $event,
        int $durationMs,
        array $resultSummary,
        array $ingestionSummary,
    ): void {
        DB::transaction(function () use ($run, $event, $durationMs, $resultSummary, $ingestionSummary): void {
            /** @var ScheduleGenerationRun $lockedRun */
            $lockedRun = ScheduleGenerationRun::query()->lockForUpdate()->findOrFail($run->id);
            /** @var OperationalEvent $lockedEvent */
            $lockedEvent = OperationalEvent::query()->lockForUpdate()->findOrFail($event->id);
            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $eventDiagnostics = $this->arrayValue($lockedEvent->getAttribute('diagnostics'));

            $lockedEvent->forceFill([
                'status' => OperationalEvent::StatusProcessed,
                'processed_at' => $timestamp,
                'failed_at' => null,
                'diagnostics' => [
                    ...$eventDiagnostics,
                    'outcome' => 'processed',
                    'duration_ms' => max(0, $durationMs),
                    'provider_request_id' => $resultSummary['provider_request_id'] ?? null,
                    'timings' => $resultSummary['attempt_timings'] ?? [],
                    'retryable' => false,
                    'final' => true,
                ],
            ])->save();

            $diagnostics = $this->arrayValue($lockedRun->getAttribute('diagnostics'));
            $dispatch = $this->arrayValue($diagnostics['solver_dispatch'] ?? null);
            unset($dispatch['failure']);
            $diagnostics['solver_dispatch'] = [
                ...$dispatch,
                'status' => 'completed',
                'latest_outcome' => 'processed',
                'completed_at' => $timestamp->toIso8601String(),
                'result_summary' => $resultSummary,
                'ingestion_summary' => $ingestionSummary,
            ];

            $lockedRun->forceFill(['diagnostics' => $diagnostics])->save();
        }, 3);
    }

    public function markFailed(
        ScheduleGenerationRun $run,
        OperationalEvent $event,
        SchedulingSolverTransportException $exception,
        int $durationMs,
        bool $final,
    ): void {
        DB::transaction(function () use ($run, $event, $exception, $durationMs, $final): void {
            /** @var ScheduleGenerationRun $lockedRun */
            $lockedRun = ScheduleGenerationRun::query()->lockForUpdate()->findOrFail($run->id);
            /** @var OperationalEvent $lockedEvent */
            $lockedEvent = OperationalEvent::query()->lockForUpdate()->findOrFail($event->id);
            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $failure = [
                ...$exception->safeDiagnostics(),
                'message' => $exception->getMessage(),
                'final' => $final,
            ];
            $eventDiagnostics = $this->arrayValue($lockedEvent->getAttribute('diagnostics'));

            $lockedEvent->forceFill([
                'status' => OperationalEvent::StatusFailed,
                'failed_at' => $timestamp,
                'diagnostics' => [
                    ...$eventDiagnostics,
                    ...$failure,
                    'outcome' => 'failed',
                    'duration_ms' => max(0, $durationMs),
                ],
            ])->save();

            $diagnostics = $this->arrayValue($lockedRun->getAttribute('diagnostics'));
            $dispatch = $this->arrayValue($diagnostics['solver_dispatch'] ?? null);
            $diagnostics['solver_dispatch'] = [
                ...$dispatch,
                'status' => $final ? 'failed' : 'queued',
                'latest_outcome' => 'failed',
                'failed_at' => $timestamp->toIso8601String(),
                'failure' => [
                    ...$failure,
                    'failed_at' => $timestamp->toIso8601String(),
                ],
            ];

            $lockedRun->forceFill([
                'status' => $final
                    ? ScheduleGenerationRun::StatusFailed
                    : ScheduleGenerationRun::StatusQueued,
                'diagnostics' => $diagnostics,
            ])->save();
        }, 3);
    }

    public function finalizeUnhandledFailure(
        int $scheduleGenerationRunId,
        int $attempt,
        SchedulingSolverTransportException $exception,
    ): void {
        $context = DB::transaction(function () use ($scheduleGenerationRunId, $attempt): ?array {
            /** @var ScheduleGenerationRun|null $run */
            $run = ScheduleGenerationRun::query()->lockForUpdate()->find($scheduleGenerationRunId);

            if (! $run instanceof ScheduleGenerationRun) {
                return null;
            }

            $cycle = $run->dispatchCycle();
            $externalId = $this->externalId($run, $cycle, $attempt);
            /** @var OperationalEvent|null $event */
            $event = OperationalEvent::query()
                ->where('event_domain', OperationalEvent::DomainIntegration)
                ->where('external_id', $externalId)
                ->lockForUpdate()
                ->first();

            if ($event instanceof OperationalEvent
                && $event->status === OperationalEvent::StatusFailed
                && data_get($event->diagnostics, 'final') === true) {
                return null;
            }

            if (! $event instanceof OperationalEvent) {
                $event = OperationalEvent::query()->create([
                    'event_domain' => OperationalEvent::DomainIntegration,
                    'integration' => OperationalEvent::IntegrationSchedulingSolver,
                    'channel' => 'queue',
                    'direction' => 'OUTBOUND',
                    'event_type' => OperationalEvent::TypeSolverDispatchAttempt,
                    'event_version' => '1',
                    'user_id' => $run->requested_by,
                    'external_id' => $externalId,
                    'status' => OperationalEvent::StatusPending,
                    'occurred_at' => CarbonImmutable::now(config('app.timezone')),
                    'related_record_type' => ScheduleGenerationRun::class,
                    'related_record_id' => $run->id,
                    'diagnostics' => [
                        'cycle' => $cycle,
                        'attempt' => $attempt,
                        'driver' => config('tala_integrations.scheduling_solver.driver', 'local_stub'),
                    ],
                ]);
            }

            return ['run' => $run, 'event' => $event];
        }, 3);

        if ($context === null) {
            return;
        }

        $this->markFailed(
            $context['run'],
            $context['event'],
            $exception,
            durationMs: 0,
            final: true,
        );
    }

    private function closeAbandonedAttempts(
        ScheduleGenerationRun $run,
        int $cycle,
        int $currentAttempt,
        CarbonImmutable $timestamp,
    ): void {
        $pendingEvents = OperationalEvent::query()
            ->where('event_domain', OperationalEvent::DomainIntegration)
            ->where('integration', OperationalEvent::IntegrationSchedulingSolver)
            ->where('related_record_type', ScheduleGenerationRun::class)
            ->where('related_record_id', $run->id)
            ->where('status', OperationalEvent::StatusPending)
            ->lockForUpdate()
            ->get();

        foreach ($pendingEvents as $pendingEvent) {
            $diagnostics = $this->arrayValue($pendingEvent->getAttribute('diagnostics'));

            if ((int) ($diagnostics['cycle'] ?? 0) !== $cycle
                || (int) ($diagnostics['attempt'] ?? 0) >= $currentAttempt) {
                continue;
            }

            $pendingEvent->forceFill([
                'status' => OperationalEvent::StatusFailed,
                'failed_at' => $timestamp,
                'diagnostics' => [
                    ...$diagnostics,
                    'outcome' => 'failed',
                    'classification' => SchedulingSolverTransportException::ClassificationTimeout,
                    'retryable' => true,
                    'final' => false,
                    'message' => 'Scheduling solver attempt ended before the queue retry.',
                ],
            ])->save();
        }
    }

    private function externalId(ScheduleGenerationRun $run, int $cycle, int $attempt): string
    {
        return "schedule-solver:run:{$run->id}:cycle:{$cycle}:attempt:{$attempt}";
    }

    /** @return array<string, mixed> */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
