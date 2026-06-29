<?php

namespace App\Actions\Scheduling;

use App\Jobs\ScheduleSolverDispatchJob;
use App\Models\ScheduleGenerationRun;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ScheduleGenerationService
{
    public function __construct(private readonly ScheduleSolverSnapshotService $snapshotService) {}

    public function generate(Term $term, User $registrar): ScheduleGenerationRun
    {
        Gate::forUser($registrar)->authorize('create', ScheduleGenerationRun::class);

        return DB::transaction(function () use ($term, $registrar): ScheduleGenerationRun {
            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $placeholder = [
                'contract_version' => 'pending-capture',
                'nonce' => (string) Str::uuid(),
                'created_at' => $timestamp->toIso8601String(),
            ];

            $run = ScheduleGenerationRun::query()->create([
                'term_id' => $term->id,
                'status' => ScheduleGenerationRun::StatusQueued,
                'requested_by' => $registrar->id,
                'input_snapshot' => $placeholder,
                'input_hash' => hash('sha256', json_encode($placeholder, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                'solver_version' => 'pending-dispatch',
                'diagnostics' => [
                    'solver_dispatch' => [
                        'status' => 'queued',
                        'queued_at' => $timestamp->toIso8601String(),
                        'driver' => config('tala_integrations.scheduling_solver.driver', 'local_stub'),
                    ],
                ],
            ]);

            $this->snapshotService->captureForRun($run);

            ScheduleSolverDispatchJob::dispatch((int) $run->id)->afterCommit();

            return $run->fresh();
        }, 3);
    }
}
