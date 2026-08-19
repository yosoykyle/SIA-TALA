<?php

namespace App\Actions\Scheduling;

use App\Actions\Integrations\SchedulingSolver\SchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverTransportException;
use App\Models\ScheduleGenerationRun;

final class SchedulingOperatingEnvelopeRunner
{
    private const AcceptedStatuses = ['feasible', 'optimal'];

    public function __construct(
        private readonly SchedulingSolverClient $solverClient,
        private readonly ScheduleAssignmentValidationService $validationService,
        private readonly SchedulingOperatingEnvelopeCostEstimator $costEstimator,
        private readonly SchedulingOperatingEnvelopeTimetableBuilder $timetableBuilder,
        private readonly SchedulingOperatingEnvelopeStudyAcceptance $studyAcceptance,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, int|string>  $target
     * @param  array<string, int>  $composition
     * @param  array{sections?:array<int|string,string>,faculty?:array<int|string,string>}  $evidenceLabels
     * @return array{probe:array<string,mixed>,runs:list<array<string,mixed>>,summary:array<string,mixed>}
     */
    public function run(
        ScheduleGenerationRun $run,
        array $snapshot,
        array $target,
        array $composition,
        array $evidenceLabels,
        int $repetitions,
    ): array {
        $probe = $this->probe($target);
        $runs = [];

        if ($probe['accepted']) {
            foreach (range(1, $repetitions) as $iteration) {
                $runs[] = $this->runOnce(
                    run: $run,
                    snapshot: $snapshot,
                    target: $target,
                    composition: $composition,
                    evidenceLabels: $evidenceLabels,
                    iteration: $iteration,
                );
            }
        }

        return [
            'probe' => $probe,
            'runs' => $runs,
            'summary' => $this->summarize($runs, $repetitions, $probe),
        ];
    }

    /**
     * @param  array<string, int|string>  $target
     * @return array{status:int|null,accepted:bool,failure:array<string,mixed>|null,client_elapsed_ms:float,cost:array<string,mixed>}
     */
    private function probe(array $target): array
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->solverClient->probe();
            $accepted = $response['status'] === 200;
            $elapsedMilliseconds = $this->elapsedMilliseconds($startedAt);

            return [
                'status' => $response['status'],
                'accepted' => $accepted,
                'failure' => $accepted ? null : [
                    'classification' => 'health_probe_rejected',
                    'retryable' => false,
                    'status_code' => $response['status'],
                ],
                'client_elapsed_ms' => $elapsedMilliseconds,
                'cost' => $this->costEstimator->estimate(
                    $elapsedMilliseconds,
                    (int) $target['cpu'],
                    (int) $target['memory_gib'],
                ),
            ];
        } catch (SchedulingSolverTransportException $exception) {
            $elapsedMilliseconds = $this->elapsedMilliseconds($startedAt);

            return [
                'status' => null,
                'accepted' => false,
                'failure' => $exception->safeDiagnostics(),
                'client_elapsed_ms' => $elapsedMilliseconds,
                'cost' => $this->costEstimator->estimate(
                    $elapsedMilliseconds,
                    (int) $target['cpu'],
                    (int) $target['memory_gib'],
                ),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, int|string>  $target
     * @param  array<string, int>  $composition
     * @param  array{sections?:array<int|string,string>,faculty?:array<int|string,string>}  $evidenceLabels
     * @return array<string, mixed>
     */
    private function runOnce(
        ScheduleGenerationRun $run,
        array $snapshot,
        array $target,
        array $composition,
        array $evidenceLabels,
        int $iteration,
    ): array {
        $startedAt = hrtime(true);

        try {
            $solverResult = $this->solverClient->solve($snapshot);
        } catch (SchedulingSolverTransportException $exception) {
            $elapsedMilliseconds = $this->elapsedMilliseconds($startedAt);

            return [
                'iteration' => $iteration,
                'solver_status' => null,
                'result_classification' => 'infrastructure_failure',
                'accepted' => false,
                'client_elapsed_ms' => $elapsedMilliseconds,
                'cost' => $this->costEstimator->estimate(
                    $elapsedMilliseconds,
                    (int) $target['cpu'],
                    (int) $target['memory_gib'],
                ),
                'failure' => $exception->safeDiagnostics(),
            ];
        }

        $elapsedMilliseconds = $this->elapsedMilliseconds($startedAt);
        $validation = $this->validationService->validate($run, $solverResult);
        $blockingCodes = collect($validation->blockingFindings())
            ->pluck('code')
            ->filter(fn (mixed $code): bool => is_string($code))
            ->reject(fn (string $code): bool => $code === 'missing_persistence_source')
            ->values()
            ->all();
        $solverStatus = mb_strtolower((string) ($solverResult['solver_status'] ?? ''));
        $assignedCount = $this->integer($solverResult['assigned_count'] ?? null);
        $unassignedCount = $this->integer($solverResult['unassigned_count'] ?? null);
        $hardViolationCount = $this->integer($solverResult['hard_violation_count'] ?? null);
        $timedOut = ($solverResult['timeout'] ?? null) === true;
        $statistics = $this->solverStatistics($solverResult['solver_statistics'] ?? null);
        $assignments = collect($solverResult['assignments'] ?? [])
            ->filter(fn (mixed $assignment): bool => is_array($assignment))
            ->values()
            ->all();
        $requiredAssignmentCount = 0;

        foreach ($snapshot['scheduling_demands'] ?? [] as $demand) {
            if (is_array($demand)) {
                $requiredAssignmentCount += max(1, $this->integer($demand['meeting_count'] ?? 1));
            }
        }
        $telemetryComplete = $this->telemetryMatches(
            statistics: $statistics,
            composition: $composition,
            target: $target,
        );
        $operationallyValid = in_array($solverStatus, self::AcceptedStatuses, true)
            && $assignedCount === $requiredAssignmentCount
            && $unassignedCount === 0
            && $hardViolationCount === 0
            && ! $timedOut
            && $blockingCodes === []
            && $telemetryComplete;
        $relativeGap = $this->number($statistics['relative_optimality_gap'] ?? null);
        $meetsStrictStudyAcceptance = $this->studyAcceptance->meetsStrictRule(
            operationallyValid: $operationallyValid,
            solverStatus: $solverStatus,
            relativeGap: $relativeGap,
        );
        $classification = match (true) {
            $operationallyValid => $solverStatus,
            $timedOut, $solverStatus === 'unknown' => 'unknown_timed_out',
            $solverStatus === 'infeasible' => 'infeasible',
            $solverStatus === 'model_invalid' => 'model_invalid',
            in_array($solverStatus, self::AcceptedStatuses, true) => 'validation_failure',
            default => 'solver_failure',
        };
        $projectionAssignments = in_array($solverStatus, self::AcceptedStatuses, true)
            && $assignedCount > 0
            ? $assignments
            : [];

        return [
            'iteration' => $iteration,
            'solver_status' => $solverStatus,
            'result_classification' => $classification,
            'accepted' => $operationallyValid,
            'operationally_valid' => $operationallyValid,
            'meets_strict_study_acceptance' => $meetsStrictStudyAcceptance,
            'assigned_count' => $assignedCount,
            'unassigned_count' => $unassignedCount,
            'demand_coverage_percent' => $requiredAssignmentCount === 0
                ? 0.0
                : round(($assignedCount / $requiredAssignmentCount) * 100, 4),
            'hard_violation_count' => $hardViolationCount,
            'laravel_hard_constraints_pass' => $blockingCodes === [],
            'blocking_finding_codes' => $blockingCodes,
            'telemetry_complete' => $telemetryComplete,
            'timeout' => $timedOut,
            'runtime_seconds' => $this->number($solverResult['runtime_seconds'] ?? null),
            'client_elapsed_ms' => $elapsedMilliseconds,
            'objective_score' => $this->number($solverResult['objective_score'] ?? null),
            'best_objective_bound' => $this->number($statistics['best_objective_bound'] ?? null),
            'relative_optimality_gap' => $relativeGap,
            'solver_statistics' => $statistics,
            'assignment_evidence' => $this->timetableBuilder->build(
                snapshot: $snapshot,
                assignments: $projectionAssignments,
                labels: $evidenceLabels,
            ),
            'cost' => $this->costEstimator->estimate(
                $elapsedMilliseconds,
                (int) $target['cpu'],
                (int) $target['memory_gib'],
            ),
            'failure' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @param  array{accepted:bool,cost:array<string,mixed>}  $probe
     * @return array<string, mixed>
     */
    private function summarize(array $runs, int $requestedRuns, array $probe): array
    {
        $acceptedRuns = array_values(array_filter(
            $runs,
            fn (array $run): bool => ($run['accepted'] ?? false) === true,
        ));
        $strictRuns = array_values(array_filter(
            $runs,
            fn (array $run): bool => ($run['meets_strict_study_acceptance'] ?? false) === true,
        ));
        $elapsed = array_values(array_filter(array_map(
            fn (array $run): ?float => $this->number($run['client_elapsed_ms'] ?? null),
            $runs,
        ), fn (?float $value): bool => $value !== null));
        sort($elapsed);
        $solverRequestCost = array_sum(array_map(
            fn (array $run): float => (float) data_get($run, 'cost.gross_request_cost_usd', 0.0),
            $runs,
        ));
        $probeCost = (float) data_get($probe, 'cost.gross_request_cost_usd', 0.0);
        $classifications = collect($runs)
            ->countBy(fn (array $run): string => (string) ($run['result_classification'] ?? 'not_attempted'))
            ->all();

        return [
            'requested_run_count' => $requestedRuns,
            'attempted_run_count' => count($runs),
            'accepted_run_count' => count($acceptedRuns),
            'accepted_run_rate_percent' => count($runs) === 0
                ? 0.0
                : round((count($acceptedRuns) / count($runs)) * 100, 2),
            'strict_study_acceptance_count' => count($strictRuns),
            'strict_study_acceptance_rate_percent' => count($runs) === 0
                ? 0.0
                : round((count($strictRuns) / count($runs)) * 100, 2),
            'probe_accepted' => $probe['accepted'],
            'result_classifications' => $classifications,
            'client_elapsed_ms' => [
                'median' => $this->percentile($elapsed, 50),
                'p95' => $this->percentile($elapsed, 95),
            ],
            'gross_probe_cost_usd' => round($probeCost, 10),
            'gross_solver_request_cost_usd' => round($solverRequestCost, 10),
            'gross_experiment_cost_usd' => round($probeCost + $solverRequestCost, 10),
        ];
    }

    /** @return array<string, mixed> */
    private function solverStatistics(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $keys = [
            'ortools_version',
            'input_demand_count',
            'input_faculty_count',
            'input_room_count',
            'input_time_slot_count',
            'candidate_count',
            'model_variable_count',
            'model_constraint_count',
            'no_overlap_constraint_count',
            'best_objective_bound',
            'relative_optimality_gap',
            'boolean_variable_count',
            'branch_count',
            'conflict_count',
            'deterministic_time_seconds',
            'wall_time_seconds',
            'worker_count',
            'random_seed',
            'result_source',
            'search_stages',
        ];

        return collect($value)->only($keys)->all();
    }

    /**
     * @param  array<string, mixed>  $statistics
     * @param  array<string, int>  $composition
     * @param  array<string, int|string>  $target
     */
    private function telemetryMatches(array $statistics, array $composition, array $target): bool
    {
        $requiredKeys = [
            'candidate_count',
            'model_variable_count',
            'model_constraint_count',
            'best_objective_bound',
            'relative_optimality_gap',
            'worker_count',
            'random_seed',
        ];

        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $statistics)) {
                return false;
            }
        }

        return $this->integer($statistics['input_demand_count'] ?? null) === $composition['demands']
            && $this->integer($statistics['input_faculty_count'] ?? null) === $composition['faculty']
            && $this->integer($statistics['input_room_count'] ?? null) === $composition['rooms']
            && $this->integer($statistics['input_time_slot_count'] ?? null) === $composition['time_slots']
            && $this->integer($statistics['worker_count'] ?? null) === (int) $target['worker_count']
            && $this->integer($statistics['random_seed'] ?? null) === (int) $target['random_seed'];
    }

    /** @param list<float> $values */
    private function percentile(array $values, int $percentile): ?float
    {
        if ($values === []) {
            return null;
        }

        $index = (int) ceil(($percentile / 100) * count($values)) - 1;

        return round($values[max(0, $index)], 3);
    }

    private function elapsedMilliseconds(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 3);
    }

    private function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
