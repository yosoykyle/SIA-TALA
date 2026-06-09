<?php

namespace App\Actions\Scheduling;

use App\Jobs\ScheduleSolverDispatchJob;
use App\Models\ScheduleGenerationRun;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ScheduleGenerationService
{
    public function __construct(private readonly ScheduleSolverSnapshotService $snapshotService) {}

    public function generate(Term $term, User $registrar): ScheduleGenerationRun
    {
        $this->authorizeRegistrar($registrar);

        return DB::transaction(function () use ($term, $registrar): ScheduleGenerationRun {
            $timestamp = CarbonImmutable::now(config('app.timezone'));

            $run = ScheduleGenerationRun::query()->create([
                'term_id' => $term->id,
                'status' => ScheduleGenerationRun::StatusDraft,
                'requested_by' => $registrar->id,
                'generated_at' => $timestamp,
                'constraint_summary' => [
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
        });
    }

    private function authorizeRegistrar(User $registrar): void
    {
        if ($registrar->can('manage-schedules')) {
            return;
        }

        throw new AuthorizationException('Only authorized Registrar staff can generate schedule runs.');
    }
}
