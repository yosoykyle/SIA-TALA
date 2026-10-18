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
use Illuminate\Validation\ValidationException;

class ScheduleGenerationService
{
    public function __construct(
        private readonly ScheduleSolverSnapshotService $snapshotService,
        private readonly ScheduleSolverRecoveryService $recoveryService,
    ) {}

    public function generate(Term $term, User $registrar, ?ScheduleGenerationRun $recoverySource = null, ?string $correctionExplanation = null): ScheduleGenerationRun
    {
        Gate::forUser($registrar)->authorize('create', ScheduleGenerationRun::class);

        return DB::transaction(function () use ($term, $registrar, $recoverySource, $correctionExplanation): ScheduleGenerationRun {
            /** @var Term $lockedTerm */
            $lockedTerm = Term::query()->lockForUpdate()->findOrFail($term->id);

            $anotherActiveRunExists = ScheduleGenerationRun::query()
                ->where('term_id', $lockedTerm->id)
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

            $recoverySource?->refresh();
            $recovery = $recoverySource ? $this->recoveryService->recoveryEvidence($recoverySource, $registrar, (int) $lockedTerm->id, $correctionExplanation) : null;

            if ($recoverySource && data_get($recoverySource->input_snapshot, 'operation.kind') === 'repair') {
                throw ValidationException::withMessages(['recovery_source' => 'Recover the original repair request so its intended change is preserved.']);
            }

            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $placeholder = [
                'contract_version' => 'pending-capture',
                'nonce' => (string) Str::uuid(),
                'created_at' => $timestamp->toIso8601String(),
            ];

            $run = ScheduleGenerationRun::query()->create([
                'term_id' => $lockedTerm->id,
                'status' => ScheduleGenerationRun::StatusQueued,
                'requested_by' => $registrar->id,
                'input_snapshot' => $placeholder,
                'input_hash' => hash('sha256', json_encode($placeholder, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                'solver_version' => 'pending-dispatch',
                'diagnostics' => [
                    ...($recovery ? ['technical_recovery' => $recovery] : []),
                    'solver_dispatch' => [
                        'status' => 'queued',
                        'dispatch_cycle' => 1,
                        'last_attempt' => 0,
                        'latest_outcome' => 'queued',
                        'queued_at' => $timestamp->toIso8601String(),
                        'driver' => config('tala_integrations.scheduling_solver.driver', 'local_stub'),
                        'technical_context_fingerprint' => $this->recoveryService->contextFingerprint(),
                    ],
                ],
            ]);

            $this->snapshotService->captureForRun($run);
            $this->assertRerunAllowed($run, $recoverySource);

            ScheduleSolverDispatchJob::dispatch((int) $run->id)->afterCommit();

            return $run->fresh();
        }, 3);
    }

    private function assertRerunAllowed(ScheduleGenerationRun $run, ?ScheduleGenerationRun $recoverySource): void
    {
        $previous = ScheduleGenerationRun::query()
            ->where('term_id', $run->term_id)
            ->whereKeyNot($run->id)
            ->where('contract_version', ScheduleGenerationRun::ContractVersion)
            ->latest('id')
            ->first();

        if (! $previous instanceof ScheduleGenerationRun
            || $this->sourceFingerprint($run) !== $this->sourceFingerprint($previous)) {
            return;
        }

        if ($recoverySource && (int) $previous->id === (int) $recoverySource->id) {
            return;
        }

        $outcome = data_get($previous->diagnostics, 'solver_result.solver_status');
        $technicalFailure = data_get($previous->diagnostics, 'solver_result.summary.outcome') === 'TechnicalFailure';

        if ($this->recoveryService->canRecover($previous)) {
            return;
        }

        if (($outcome === 'unknown' && ! $technicalFailure) || $previous->candidate_state === 'Rejected') {
            return;
        }

        if ($outcome === 'model_invalid' && ! $technicalFailure && $previous->solver_version !== ScheduleGenerationRun::SolverVersion
            && config('tala_integrations.scheduling_solver.driver') !== 'local_stub') {
            return;
        }

        throw ValidationException::withMessages([
            'generation' => $technicalFailure
                ? 'Correct the recorded solver response or integration failure before generating again.'
                : match ($outcome) {
                    'optimal', 'feasible' => 'Review or reject the retained candidate, or change its source facts before generating again.',
                    'infeasible' => 'Correct the conflicting source facts before generating again.',
                    'model_invalid' => 'Correct the solver model defect before generating again.',
                    default => 'Retry a retryable failure, correct the solver integration, or correct the source facts before generating again.',
                },
        ]);
    }

    private function sourceFingerprint(ScheduleGenerationRun $run): string
    {
        $snapshot = is_array($run->input_snapshot) ? $run->input_snapshot : [];
        $snapshot = array_intersect_key($snapshot, array_flip([
            'contract_version', 'term', 'time_slots', 'scheduling_demands', 'rooms', 'faculty',
            'faculty_availability', 'student_cohort_groups', 'calendar_blocks', 'dated_exceptions',
            'fixed_assignments', 'constraint_profile',
        ]));

        return hash('sha256', json_encode($this->constraintInputs($snapshot), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string|int, mixed> $inputs
     * @return array<string|int, mixed>
     */
    private function constraintInputs(array $inputs): array
    {
        foreach ($inputs as $key => $value) {
            if (in_array($key, [
                'source_snapshot', 'label', 'name', 'code', 'course_code', 'demand_key',
                'authority', 'authority_reference', 'authority_date', 'declared_at', 'declaration_version',
                'faculty_availability_declaration_id', 'qualification_id', 'term_load_override_id',
                'calendar_event_id', 'resource_unavailability_id', 'term_dated_exception_id',
            ], true)) {
                unset($inputs[$key]);
            } elseif (is_array($value)) {
                $inputs[$key] = $this->constraintInputs($value);
            }
        }

        if (! array_is_list($inputs)) {
            ksort($inputs);
        }

        return $inputs;
    }
}
