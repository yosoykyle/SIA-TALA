<?php

namespace App\Actions\Scheduling;

use App\Actions\Integrations\SchedulingSolver\SchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverTransportException;
use App\Models\ScheduleGenerationRun;

final class SchedulingBenchmarkRunner
{
    /** @var list<string> */
    private const UsableStatuses = ['optimal', 'feasible'];

    /** @var list<string> */
    private const NormalCapacityTiers = [
        'reduced',
        'representative',
        'proportional-2x',
        'proportional-4x',
    ];

    /** @var list<string> */
    private const SolverStatisticKeys = [
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
    ];

    public function __construct(
        private readonly SchedulingSolverClient $solverClient,
        private readonly ScheduleAssignmentValidationService $validationService,
        private readonly SchedulingBenchmarkDatasetFactory $datasetFactory,
    ) {}

    /**
     * @param  array<string, array<string, mixed>>  $datasets
     * @param  array<string, int|string>  $target
     * @return array<string, mixed>
     */
    public function run(
        ScheduleGenerationRun $representativeRun,
        array $datasets,
        int $repetitions,
        array $target,
    ): array {
        $report = [
            'benchmark_version' => 'tal96b3-v2',
            'generated_at' => now()->toIso8601String(),
            'contract_version' => 'tal94-demand-v2',
            'constraint_profile' => 'balanced_v1',
            'target' => $target,
            'repetitions' => $repetitions,
            'health' => $this->probe(),
            'tiers' => [],
            'largest_accepted_tested_tier' => null,
            'largest_attempted_tested_tier' => null,
            'stop_reason' => null,
            'overall_status' => 'failed',
        ];

        if ($report['health']['accepted'] !== true) {
            $report['stop_reason'] = 'health_probe_failed';

            return $report;
        }

        $representativeAccepted = false;
        $experimentFailed = false;
        foreach ($datasets as $tier => $snapshot) {
            $report['largest_attempted_tested_tier'] = $tier;
            $runs = [];
            $tierRun = clone $representativeRun;
            $tierRun->setAttribute('input_snapshot', $snapshot);

            foreach (range(1, $repetitions) as $iteration) {
                $run = $this->runOnce($tierRun, $snapshot, $tier, $iteration, $target);
                $runs[] = $run;

                if (($run['fatal'] ?? false) === true) {
                    break;
                }
            }

            $runs = $this->withSameTierRelativePercentageDeviation($runs);
            $summary = $this->summarize($tier, $runs, $repetitions);
            $report['tiers'][$tier] = [
                'definition' => $this->datasetFactory->definition($tier),
                'snapshot_hash_sha256' => $this->datasetFactory->normalizedHash($snapshot),
                'input_composition' => $this->datasetFactory->evidenceComposition($snapshot),
                'runs' => $runs,
                'summary' => $summary,
            ];

            if ($tier === 'representative') {
                $representativeAccepted = ($summary['outcome'] ?? null) === 'accepted';

                if (! $representativeAccepted) {
                    $report['stop_reason'] = 'representative_gate_failed';
                    $experimentFailed = true;
                    break;
                }
            }

            if ($tier === 'reduced' && ($summary['outcome'] ?? null) !== 'accepted') {
                $report['stop_reason'] = 'reduced_gate_failed';
                $experimentFailed = true;
                break;
            }

            if (($summary['outcome'] ?? null) === 'accepted'
                && in_array($tier, self::NormalCapacityTiers, true)) {
                $report['largest_accepted_tested_tier'] = $tier;
            }

            if ($tier === 'contention-2x'
                && ! in_array($summary['outcome'] ?? null, ['accepted', 'diagnostic_infeasible'], true)) {
                $report['stop_reason'] = 'contention_result_inconsistent';
                $experimentFailed = true;
                break;
            }

            if ($tier !== 'contention-2x' && ($summary['outcome'] ?? null) !== 'accepted') {
                $report['stop_reason'] = match ($summary['outcome']) {
                    'model_boundary' => 'higher_tier_model_boundary',
                    'compute_boundary' => 'higher_tier_compute_boundary',
                    'infrastructure_failure' => 'higher_tier_infrastructure_failure',
                    default => 'fatal_solver_or_validation_failure',
                };
                $experimentFailed = in_array(
                    $summary['outcome'],
                    ['infrastructure_failure', 'fatal_failure', 'capacity_boundary'],
                    true,
                );
                break;
            }
        }

        if ($report['stop_reason'] === null) {
            $report['stop_reason'] = 'selected_tiers_completed';
        }

        if ($representativeAccepted && ! $experimentFailed) {
            $report['overall_status'] = 'accepted';
        }

        return $report;
    }

    /** @return array{status:int|null,accepted:bool,failure:array<string,mixed>|null,client_elapsed_ms:float} */
    private function probe(): array
    {
        $startedAt = hrtime(true);

        try {
            $probe = $this->solverClient->probe();

            return [
                'status' => $probe['status'],
                'accepted' => $probe['status'] === 200,
                'failure' => null,
                'client_elapsed_ms' => $this->elapsedMilliseconds($startedAt),
            ];
        } catch (SchedulingSolverTransportException $exception) {
            return [
                'status' => $exception->statusCode(),
                'accepted' => false,
                'failure' => $exception->safeDiagnostics(),
                'client_elapsed_ms' => $this->elapsedMilliseconds($startedAt),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, int|string>  $target
     * @return array<string, mixed>
     */
    private function runOnce(
        ScheduleGenerationRun $run,
        array $snapshot,
        string $tier,
        int $iteration,
        array $target,
    ): array {
        $startedAt = hrtime(true);

        try {
            $solverResult = $this->solverClient->solve($snapshot);
        } catch (SchedulingSolverTransportException $exception) {
            return [
                'iteration' => $iteration,
                'solver_status' => null,
                'accepted' => false,
                'diagnostic_contention_result' => false,
                'fatal' => $this->isFatalTransportFailure($exception),
                'telemetry_complete' => false,
                'failure_classification' => 'infrastructure_failure',
                'solver_statistics' => [],
                'failure' => $exception->safeDiagnostics(),
                'client_elapsed_ms' => $this->elapsedMilliseconds($startedAt),
            ];
        }

        $clientElapsedMilliseconds = $this->elapsedMilliseconds($startedAt);
        $validation = $this->validationService->validate($run, $solverResult);
        $blockingCodes = collect($validation->blockingFindings())
            ->pluck('code')
            ->filter(fn (mixed $code): bool => is_string($code))
            ->values()
            ->all();
        $nonPersistenceBlockingCodes = array_values(array_filter(
            $blockingCodes,
            fn (string $code): bool => $code !== 'missing_persistence_source',
        ));
        $demandCount = $this->datasetFactory->composition($snapshot)['demands'];
        $assignedCount = $this->integer($solverResult['assigned_count'] ?? null);
        $unassignedCount = $this->integer($solverResult['unassigned_count'] ?? null);
        $hardViolationCount = $this->integer($solverResult['hard_violation_count'] ?? null);
        $solverStatus = mb_strtolower((string) ($solverResult['solver_status'] ?? ''));
        $timedOut = ($solverResult['timeout'] ?? null) === true;
        $laravelHardConstraintsPass = $nonPersistenceBlockingCodes === [];
        $solverStatistics = $this->solverStatistics(
            $solverResult['solver_statistics'] ?? null,
            $target,
            $snapshot,
        );
        $telemetryComplete = $solverStatistics !== null;
        $coveragePercent = $demandCount === 0
            ? 0.0
            : round(($assignedCount / $demandCount) * 100, 4);
        $accepted = in_array($solverStatus, self::UsableStatuses, true)
            && $coveragePercent === 100.0
            && $assignedCount === $demandCount
            && $unassignedCount === 0
            && $hardViolationCount === 0
            && ! $timedOut
            && $laravelHardConstraintsPass
            && $telemetryComplete;
        $diagnosticContentionResult = $tier === 'contention-2x'
            && $solverStatus === 'infeasible'
            && ! $timedOut
            && $telemetryComplete;
        $failureClassification = match (true) {
            $accepted => null,
            ! $telemetryComplete, $timedOut, $solverStatus === 'unknown' => 'compute_boundary',
            $solverStatus === 'infeasible' => 'model_boundary',
            $solverStatus === 'model_invalid' => 'fixture_failure',
            in_array($solverStatus, self::UsableStatuses, true) && ! $laravelHardConstraintsPass => 'validation_failure',
            default => 'solver_failure',
        };
        $fatal = in_array($failureClassification, ['fixture_failure', 'validation_failure', 'solver_failure'], true);

        return [
            'iteration' => $iteration,
            'solver_status' => $solverStatus,
            'assigned_count' => $assignedCount,
            'unassigned_count' => $unassignedCount,
            'demand_coverage_percent' => $coveragePercent,
            'hard_violation_count' => $hardViolationCount,
            'timeout' => $timedOut,
            'runtime_seconds' => $this->number($solverResult['runtime_seconds'] ?? null),
            'client_elapsed_ms' => $clientElapsedMilliseconds,
            'objective_score' => $this->number($solverResult['objective_score'] ?? null),
            'objective_details' => $this->objectiveDetails($solverResult['objective_details'] ?? null),
            'solver_statistics' => $solverStatistics ?? [],
            'telemetry_complete' => $telemetryComplete,
            'failure_classification' => $failureClassification,
            'laravel_hard_constraints_pass' => $laravelHardConstraintsPass,
            'persistence_validation' => in_array('missing_persistence_source', $blockingCodes, true)
                ? 'not_applicable_to_in_memory_scaled_ids'
                : 'passed',
            'blocking_finding_codes' => $nonPersistenceBlockingCodes,
            'accepted' => $accepted,
            'diagnostic_contention_result' => $diagnosticContentionResult,
            'fatal' => $fatal,
            'failure' => null,
        ];
    }

    private function isFatalTransportFailure(SchedulingSolverTransportException $exception): bool
    {
        return in_array($exception->classification(), [
            SchedulingSolverTransportException::ClassificationClientError,
            SchedulingSolverTransportException::ClassificationConfiguration,
            SchedulingSolverTransportException::ClassificationCredential,
            SchedulingSolverTransportException::ClassificationMalformedResponse,
            SchedulingSolverTransportException::ClassificationUnexpected,
        ], true);
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return list<array<string, mixed>>
     */
    private function withSameTierRelativePercentageDeviation(array $runs): array
    {
        $objectives = collect($runs)
            ->pluck('objective_score')
            ->filter(fn (mixed $objective): bool => is_numeric($objective))
            ->map(fn (mixed $objective): float => (float) $objective);
        $bestObservedObjective = $objectives->isEmpty() ? null : $objectives->max();

        return array_map(function (array $run) use ($bestObservedObjective): array {
            $objective = $run['objective_score'] ?? null;
            $run['relative_percentage_deviation'] = $bestObservedObjective === null || ! is_numeric($objective)
                ? null
                : round(
                    (($bestObservedObjective - (float) $objective) / max(1.0, abs($bestObservedObjective))) * 100,
                    4,
                );

            return $run;
        }, $runs);
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return array<string, mixed>
     */
    private function summarize(string $tier, array $runs, int $expectedRepetitions): array
    {
        $acceptedRunCount = collect($runs)->where('accepted', true)->count();
        $diagnosticCount = collect($runs)->where('diagnostic_contention_result', true)->count();
        $runtimeSeconds = collect($runs)
            ->pluck('runtime_seconds')
            ->filter(fn (mixed $runtime): bool => is_numeric($runtime))
            ->map(fn (mixed $runtime): float => (float) $runtime)
            ->sort()
            ->values()
            ->all();
        $objectives = collect($runs)
            ->pluck('objective_score')
            ->filter(fn (mixed $objective): bool => is_numeric($objective))
            ->map(fn (mixed $objective): float => (float) $objective)
            ->all();
        $relativeOptimalityGaps = collect($runs)
            ->map(fn (array $run): mixed => data_get($run, 'solver_statistics.relative_optimality_gap'))
            ->filter(fn (mixed $gap): bool => is_numeric($gap))
            ->map(fn (mixed $gap): float => (float) $gap)
            ->sort()
            ->values()
            ->all();
        $relativePercentageDeviations = collect($runs)
            ->pluck('relative_percentage_deviation')
            ->filter(fn (mixed $deviation): bool => is_numeric($deviation))
            ->map(fn (mixed $deviation): float => (float) $deviation)
            ->sort()
            ->values()
            ->all();
        $outcome = match (true) {
            $acceptedRunCount === $expectedRepetitions => 'accepted',
            $tier === 'contention-2x' && $diagnosticCount === $expectedRepetitions => 'diagnostic_infeasible',
            collect($runs)->where('failure_classification', 'infrastructure_failure')->isNotEmpty() => 'infrastructure_failure',
            collect($runs)->where('failure_classification', 'compute_boundary')->isNotEmpty() => 'compute_boundary',
            collect($runs)->where('failure_classification', 'model_boundary')->count() === count($runs) => 'model_boundary',
            collect($runs)->where('fatal', true)->isNotEmpty() => 'fatal_failure',
            default => 'capacity_boundary',
        };

        return [
            'outcome' => $outcome,
            'completed_run_count' => count($runs),
            'accepted_run_count' => $acceptedRunCount,
            'accepted_run_rate_percent' => round(($acceptedRunCount / $expectedRepetitions) * 100, 2),
            'solver_status_counts' => collect($runs)->countBy('solver_status')->all(),
            'failure_classification_counts' => collect($runs)
                ->pluck('failure_classification')
                ->filter()
                ->countBy()
                ->all(),
            'best_observed_objective' => $objectives === [] ? null : max($objectives),
            'relative_optimality_gap' => $this->descriptiveStatistics($relativeOptimalityGaps),
            'relative_percentage_deviation' => $this->descriptiveStatistics($relativePercentageDeviations),
            'runtime_seconds' => $this->descriptiveStatistics($runtimeSeconds),
            'client_elapsed_ms' => $this->descriptiveStatistics(
                collect($runs)
                    ->pluck('client_elapsed_ms')
                    ->filter(fn (mixed $elapsed): bool => is_numeric($elapsed))
                    ->map(fn (mixed $elapsed): float => (float) $elapsed)
                    ->sort()
                    ->values()
                    ->all(),
            ),
        ];
    }

    /**
     * @param  list<float>  $values
     * @return array{min:float|null,median:float|null,p95:float|null,max:float|null,mean:float|null}
     */
    private function descriptiveStatistics(array $values): array
    {
        if ($values === []) {
            return [
                'min' => null,
                'median' => null,
                'p95' => null,
                'max' => null,
                'mean' => null,
            ];
        }

        $count = count($values);
        $middle = intdiv($count, 2);
        $median = $count % 2 === 0
            ? ($values[$middle - 1] + $values[$middle]) / 2
            : $values[$middle];

        return [
            'min' => round(min($values), 6),
            'median' => round($median, 6),
            'p95' => round($this->percentile($values, 0.95), 6),
            'max' => round(max($values), 6),
            'mean' => round(array_sum($values) / $count, 6),
        ];
    }

    /** @param list<float> $sortedValues */
    private function percentile(array $sortedValues, float $percentile): float
    {
        $position = (count($sortedValues) - 1) * $percentile;
        $lowerIndex = (int) floor($position);
        $upperIndex = (int) ceil($position);

        if ($lowerIndex === $upperIndex) {
            return $sortedValues[$lowerIndex];
        }

        $weight = $position - $lowerIndex;

        return $sortedValues[$lowerIndex]
            + (($sortedValues[$upperIndex] - $sortedValues[$lowerIndex]) * $weight);
    }

    /** @return array<string, mixed> */
    private function objectiveDetails(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return [
            'profile_key' => $value['profile_key'] ?? null,
            'profile_version' => $value['profile_version'] ?? null,
            'terms' => is_array($value['terms'] ?? null) ? $value['terms'] : [],
            'total' => $value['total'] ?? null,
        ];
    }

    /**
     * @param  array<string, int|string>  $target
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|null
     */
    private function solverStatistics(mixed $value, array $target, array $snapshot): ?array
    {
        if (! is_array($value)
            || array_diff(self::SolverStatisticKeys, array_keys($value)) !== []
            || array_diff(array_keys($value), self::SolverStatisticKeys) !== []) {
            return null;
        }

        $integerKeys = [
            'input_demand_count',
            'input_faculty_count',
            'input_room_count',
            'input_time_slot_count',
            'candidate_count',
            'model_variable_count',
            'model_constraint_count',
            'no_overlap_constraint_count',
            'worker_count',
            'random_seed',
        ];
        $nullableIntegerKeys = ['boolean_variable_count', 'branch_count', 'conflict_count'];
        $nullableNumberKeys = [
            'best_objective_bound',
            'relative_optimality_gap',
            'deterministic_time_seconds',
            'wall_time_seconds',
        ];

        if (! is_string($value['ortools_version']) || trim($value['ortools_version']) === '') {
            return null;
        }

        foreach ($integerKeys as $key) {
            if (! is_int($value[$key]) || $value[$key] < 0) {
                return null;
            }
        }

        foreach ($nullableIntegerKeys as $key) {
            if ($value[$key] !== null && (! is_int($value[$key]) || $value[$key] < 0)) {
                return null;
            }
        }

        foreach ($nullableNumberKeys as $key) {
            if ($value[$key] !== null && ! is_int($value[$key]) && ! is_float($value[$key])) {
                return null;
            }
        }

        $composition = $this->datasetFactory->composition($snapshot);

        if ($value['input_demand_count'] !== $composition['demands']
            || $value['input_faculty_count'] !== $composition['faculty']
            || $value['input_room_count'] !== $composition['rooms']
            || $value['input_time_slot_count'] !== $composition['time_slots']
            || $value['worker_count'] !== (int) $target['worker_count']
            || $value['random_seed'] !== (int) $target['random_seed']) {
            return null;
        }

        return $value;
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
