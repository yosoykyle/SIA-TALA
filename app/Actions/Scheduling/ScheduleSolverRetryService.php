<?php

namespace App\Actions\Scheduling;

use App\Jobs\ScheduleSolverDispatchJob;
use App\Models\ScheduleGenerationRun;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ScheduleSolverRetryService
{
    public function retry(ScheduleGenerationRun $run, User $actor): ScheduleGenerationRun
    {
        Gate::forUser($actor)->authorize('retry', $run);

        return DB::transaction(function () use ($run, $actor): ScheduleGenerationRun {
            Term::query()->lockForUpdate()->findOrFail($run->term_id);

            /** @var ScheduleGenerationRun $lockedRun */
            $lockedRun = ScheduleGenerationRun::query()->lockForUpdate()->findOrFail($run->id);

            if (! $lockedRun->canRetrySolver()) {
                throw ValidationException::withMessages([
                    'status' => 'Only finally failed transient solver runs without candidate data can be retried.',
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
