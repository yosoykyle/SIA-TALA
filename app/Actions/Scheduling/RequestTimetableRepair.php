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

final class RequestTimetableRepair
{
    public function __construct(private readonly ScheduleSolverSnapshotService $snapshotService) {}

    /**
     * @param  array{faculty_user_id:int,room_id?:int|null,day_of_week:int,starts_at:string,ends_at:string}  $assignment
     */
    public function execute(
        CandidateScheduleRow $requestedRow,
        User $actor,
        array $assignment,
        string $reason,
        string $authority,
    ): ScheduleGenerationRun {
        Gate::forUser($actor)->authorize('reviewCandidates', $requestedRow->scheduleRun);

        return DB::transaction(function () use ($requestedRow, $actor, $assignment, $reason, $authority): ScheduleGenerationRun {
            $termId = $requestedRow->scheduleRun()->value('term_id');
            $term = Term::query()->whereKey($termId)->lockForUpdate()->firstOrFail();
            $sourceRun = ScheduleGenerationRun::query()->whereKey($requestedRow->schedule_run_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('reviewCandidates', $sourceRun);

            if ($sourceRun->status !== ScheduleGenerationRun::StatusUnderReview
                || $sourceRun->candidate_state === 'Stale') {
                throw ValidationException::withMessages([
                    'source_candidate' => 'Only the current non-stale candidate can request a repair.',
                ]);
            }

            if (ScheduleGenerationRun::query()
                ->where('term_id', $term->id)
                ->whereIn('status', [ScheduleGenerationRun::StatusQueued, ScheduleGenerationRun::StatusDispatching])
                ->exists()) {
                throw ValidationException::withMessages([
                    'term_id' => 'Another generation or repair run is already active for this exact Term.',
                ]);
            }

            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $placeholder = [
                'contract_version' => 'pending-repair-capture',
                'nonce' => (string) Str::uuid(),
                'created_at' => $timestamp->toIso8601String(),
            ];
            $repairRun = ScheduleGenerationRun::query()->create([
                'term_id' => $term->id,
                'status' => ScheduleGenerationRun::StatusQueued,
                'requested_by' => $actor->id,
                'input_snapshot' => $placeholder,
                'input_hash' => hash('sha256', json_encode($placeholder, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                'solver_version' => 'pending-dispatch',
                'candidate_version' => ((int) $sourceRun->candidate_version) + 1,
                'candidate_state' => 'RepairQueued',
                'diagnostics' => [
                    'solver_dispatch' => [
                        'status' => 'queued',
                        'dispatch_cycle' => 1,
                        'last_attempt' => 0,
                        'latest_outcome' => 'queued',
                        'queued_at' => $timestamp->toIso8601String(),
                        'driver' => config('tala_integrations.scheduling_solver.driver', 'local_stub'),
                        'operation' => 'repair',
                    ],
                ],
            ]);

            $this->snapshotService->captureRepairForRun(
                run: $repairRun,
                sourceRun: $sourceRun,
                requestedRow: $requestedRow,
                assignment: $assignment,
                actor: $actor,
                reason: $reason,
                authority: $authority,
            );
            ScheduleSolverDispatchJob::dispatch((int) $repairRun->id)->afterCommit();

            return $repairRun->fresh();
        }, 3);
    }
}
