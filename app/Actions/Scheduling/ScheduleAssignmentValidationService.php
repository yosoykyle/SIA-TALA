<?php

namespace App\Actions\Scheduling;

use App\Models\CandidateScheduleRow;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ScheduleAssignmentValidationService
{
    /** @var list<string> */
    private const ContractVersions = ['tal94-demand-v2', ScheduleGenerationRun::ContractVersion];

    /** @var list<string> */
    private const NativeStatuses = ['optimal', 'feasible', 'infeasible', 'model_invalid', 'unknown'];

    /** @var list<string> */
    private const UsableStatuses = ['optimal', 'feasible'];

    /** @var list<string> */
    private const ResponseKeys = [
        'solver_run_id',
        'solver_status',
        'candidate_schedule_id',
        'assignments',
        'hard_constraint_violations',
        'hard_violation_count',
        'soft_constraint_scores',
        'infeasible_reasons',
        'warnings',
        'runtime_seconds',
        'objective_score',
        'objective_details',
        'solver_statistics',
        'solver_version',
        'model_version',
        'generated_at',
        'assigned_count',
        'unassigned_count',
        'warning_count',
        'timeout',
    ];

    /** @var list<string> */
    private const SolverStatisticsKeys = [
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

    /** @var list<string> */
    private const SearchStageStatisticsKeys = [
        'status',
        'model_variable_count',
        'model_constraint_count',
        'no_overlap_constraint_count',
        'boolean_variable_count',
        'branch_count',
        'conflict_count',
        'deterministic_time_seconds',
        'wall_time_seconds',
    ];

    /** @var list<string> */
    private const AssignmentKeys = [
        'scheduling_demand_id',
        'term_offering_id',
        'section_id',
        'section_delivery_group_id',
        'subject_id',
        'course_component_id',
        'faculty_id',
        'faculty_user_id',
        'room_id',
        'day',
        'day_of_week',
        'start_time',
        'end_time',
        'starts_at',
        'ends_at',
        'time_slot_id',
        'time_block_reference',
        'time_block_key',
        'meeting_sequence',
        'meeting_pattern',
        'assignment_status',
        'violations',
        'warnings',
        'scores',
        'soft_constraint_scores',
    ];

    /** @var list<string> */
    private const OptionalAssignmentKeys = [
        'cohort_or_student_group_id',
        'cohort_or_student_group_ids',
    ];

    /**
     * @param  array<string, mixed>  $solverResult
     */
    public function validate(ScheduleGenerationRun $run, array $solverResult): ScheduleValidationResult
    {
        $snapshot = $this->arrayValue($run->getAttribute('input_snapshot'));
        $findings = [];

        if (array_key_exists('draft_rows', $solverResult)) {
            $findings[] = $this->finding(
                run: $run,
                code: 'legacy_draft_rows_not_allowed',
                constraint: 'solver_response_contract',
                message: 'The V2 solver response must use assignments and cannot include legacy draft_rows.',
                sourceField: 'draft_rows',
            );
        }

        if (! in_array(($snapshot['contract_version'] ?? null), self::ContractVersions, true)) {
            $findings[] = $this->finding(
                run: $run,
                code: 'input_snapshot_contract_mismatch',
                constraint: 'solver_response_contract',
                message: 'Result validation requires a supported immutable scheduling snapshot.',
                sourceField: 'input_snapshot.contract_version',
            );
        }

        $shapeFindings = $this->shapeFindings($run, $solverResult);
        $findings = [...$findings, ...$shapeFindings];
        $assignments = $this->listValue($solverResult['assignments'] ?? null);
        $metadata = $this->metadata($solverResult, $shapeFindings === []);

        if ($this->hasBlockingFindings($findings)) {
            return $this->result([], $findings, $metadata, $solverResult, $assignments);
        }

        $status = mb_strtolower((string) $solverResult['solver_status']);
        $snapshotRunId = $this->integerValue(Arr::get($snapshot, 'run_metadata.solver_run_id'));

        if ((int) $solverResult['solver_run_id'] !== (int) $run->id
            || $snapshotRunId !== (int) $run->id) {
            $findings[] = $this->finding(
                run: $run,
                code: 'solver_run_mismatch',
                constraint: 'solver_response_contract',
                message: 'The solver response does not belong to this immutable schedule run.',
                sourceField: 'solver_run_id',
            );
        }

        if ((string) $solverResult['model_version'] !== (string) ($snapshot['contract_version'] ?? null)) {
            $findings[] = $this->finding(
                run: $run,
                code: 'model_version_mismatch',
                constraint: 'solver_response_contract',
                message: 'The solver model version does not match the captured snapshot contract.',
                sourceField: 'model_version',
            );
        }

        if (($snapshot['contract_version'] ?? null) === ScheduleGenerationRun::ContractVersion) {
            $solverVersion = (string) ($solverResult['solver_version'] ?? '');
            $driver = (string) config('tala_integrations.scheduling_solver.driver');
            $solverIdentityMatches = $driver === 'local_stub'
                ? str_starts_with($solverVersion, 'local-stub-')
                : $solverVersion === ScheduleGenerationRun::SolverVersion;

            if (! $solverIdentityMatches) {
                $findings[] = $this->finding(
                    run: $run,
                    code: 'solver_version_mismatch',
                    constraint: 'solver_response_contract',
                    message: 'The solver identity does not match the configured timetable driver and contract family.',
                    sourceField: 'solver_version',
                );
            }
        }

        $findings = [...$findings, ...$this->counterFindings($run, $solverResult, $assignments)];
        $findings = [...$findings, ...$this->timeoutFindings($run, $status, (bool) $solverResult['timeout'])];
        $findings = [...$findings, ...$this->reportedWarningFindings($run, $solverResult['warnings'])];

        if (! in_array($status, self::UsableStatuses, true)) {
            $findings[] = $this->finding(
                run: $run,
                code: 'solver_'.$status,
                constraint: 'solver_status',
                message: $this->blockingStatusMessage($status),
                sourceField: 'solver_status',
            );
            $findings = [...$findings, ...$this->reportedReasonFindings($run, $solverResult)];

            return $this->result([], $findings, $metadata, $solverResult, $assignments);
        }

        if ($this->listValue($solverResult['hard_constraint_violations']) !== []) {
            $findings[] = $this->finding(
                run: $run,
                code: 'hard_violation_reported_for_solution',
                constraint: 'solver_status',
                message: 'A feasible or optimal response cannot report hard-constraint violations.',
                sourceField: 'hard_constraint_violations',
            );
        }

        $findings = [...$findings, ...$this->objectiveFindings($run, $snapshot, $solverResult)];
        [$candidateRows, $assignmentFindings] = $this->validateAssignments($run, $snapshot, $assignments);
        $findings = [...$findings, ...$assignmentFindings];

        return $this->result($candidateRows, $findings, $metadata, $solverResult, $assignments);
    }

    /**
     * Validate a complete assignment set against an explicitly supplied context.
     * Solver-envelope checks remain owned by validate().
     *
     * @param  array<string, mixed>  $snapshot
     * @param  list<array<string, mixed>>  $assignments
     */
    public function validateCandidateAssignments(
        ScheduleGenerationRun $run,
        array $snapshot,
        array $assignments,
    ): ScheduleValidationResult {
        [$candidateRows, $findings] = $this->validateAssignments($run, $snapshot, $assignments);

        return $this->result(
            candidateRows: $candidateRows,
            findings: $findings,
            metadata: [
                'validation_context' => 'current_authoritative_records',
                'validated_at' => now()->toIso8601String(),
            ],
            solverResult: [
                'assigned_count' => count($assignments),
                'unassigned_count' => 0,
                'hard_violation_count' => 0,
            ],
            assignments: $assignments,
        );
    }

    /**
     * @param  array<string, mixed>  $solverResult
     * @return list<array<string, mixed>>
     */
    private function shapeFindings(ScheduleGenerationRun $run, array $solverResult): array
    {
        $validator = Validator::make(['response' => $solverResult], [
            'response' => ['required', 'array:'.implode(',', self::ResponseKeys)],
            'response.solver_run_id' => ['required', 'integer'],
            'response.solver_status' => ['required', 'string', Rule::in(self::NativeStatuses)],
            'response.candidate_schedule_id' => ['required', 'string', 'max:255'],
            'response.assignments' => ['present', 'array', 'list'],
            'response.assignments.*' => [
                'array:'.implode(',', [...self::AssignmentKeys, ...self::OptionalAssignmentKeys]),
                'required_array_keys:'.implode(',', self::AssignmentKeys),
            ],
            'response.assignments.*.scheduling_demand_id' => ['nullable', 'integer'],
            'response.assignments.*.term_offering_id' => ['nullable', 'integer'],
            'response.assignments.*.section_id' => ['nullable', 'integer'],
            'response.assignments.*.section_delivery_group_id' => ['nullable', 'integer'],
            'response.assignments.*.cohort_or_student_group_id' => ['nullable', 'integer'],
            'response.assignments.*.cohort_or_student_group_ids' => ['sometimes', 'array'],
            'response.assignments.*.cohort_or_student_group_ids.*' => ['integer'],
            'response.assignments.*.subject_id' => ['nullable', 'integer'],
            'response.assignments.*.course_component_id' => ['nullable', 'integer'],
            'response.assignments.*.faculty_id' => ['nullable', 'integer'],
            'response.assignments.*.faculty_user_id' => ['nullable', 'integer'],
            'response.assignments.*.room_id' => ['nullable', 'integer'],
            'response.assignments.*.day' => ['nullable', 'integer'],
            'response.assignments.*.day_of_week' => ['nullable', 'integer'],
            'response.assignments.*.start_time' => ['nullable', 'date_format:H:i:s'],
            'response.assignments.*.end_time' => ['nullable', 'date_format:H:i:s'],
            'response.assignments.*.starts_at' => ['nullable', 'date_format:H:i:s'],
            'response.assignments.*.ends_at' => ['nullable', 'date_format:H:i:s'],
            'response.assignments.*.time_slot_id' => ['nullable', 'integer'],
            'response.assignments.*.time_block_reference' => ['nullable', 'string'],
            'response.assignments.*.time_block_key' => ['nullable', 'string'],
            'response.assignments.*.meeting_sequence' => ['nullable', 'integer', 'min:1'],
            'response.assignments.*.meeting_pattern' => ['nullable', 'string'],
            'response.assignments.*.assignment_status' => ['required', 'string', Rule::in(['ok', 'warning', 'conflict'])],
            'response.assignments.*.violations' => ['present', 'array', 'list'],
            'response.assignments.*.warnings' => ['present', 'array', 'list'],
            'response.assignments.*.scores' => ['present', 'array'],
            'response.assignments.*.soft_constraint_scores' => ['present', 'array'],
            'response.hard_constraint_violations' => ['present', 'array', 'list'],
            'response.hard_violation_count' => ['required', 'integer', 'min:0'],
            'response.soft_constraint_scores' => ['present', 'array'],
            'response.infeasible_reasons' => ['present', 'array', 'list'],
            'response.warnings' => ['present', 'array', 'list'],
            'response.runtime_seconds' => ['required', 'numeric', 'min:0'],
            'response.objective_score' => ['present', 'nullable', 'numeric'],
            'response.objective_details' => ['required', 'array'],
            'response.solver_statistics' => [
                'required',
                'array:'.implode(',', self::SolverStatisticsKeys),
                'required_array_keys:'.implode(',', self::SolverStatisticsKeys),
            ],
            'response.solver_statistics.ortools_version' => ['required', 'string', 'max:50'],
            'response.solver_statistics.input_demand_count' => ['required', 'integer', 'min:0'],
            'response.solver_statistics.input_faculty_count' => ['required', 'integer', 'min:0'],
            'response.solver_statistics.input_room_count' => ['required', 'integer', 'min:0'],
            'response.solver_statistics.input_time_slot_count' => ['required', 'integer', 'min:0'],
            'response.solver_statistics.candidate_count' => ['required', 'integer', 'min:0'],
            'response.solver_statistics.model_variable_count' => ['required', 'integer', 'min:0'],
            'response.solver_statistics.model_constraint_count' => ['required', 'integer', 'min:0'],
            'response.solver_statistics.no_overlap_constraint_count' => ['required', 'integer', 'min:0'],
            'response.solver_statistics.best_objective_bound' => ['present', 'nullable', 'numeric'],
            'response.solver_statistics.relative_optimality_gap' => ['present', 'nullable', 'numeric', 'min:0'],
            'response.solver_statistics.boolean_variable_count' => ['present', 'nullable', 'integer', 'min:0'],
            'response.solver_statistics.branch_count' => ['present', 'nullable', 'integer', 'min:0'],
            'response.solver_statistics.conflict_count' => ['present', 'nullable', 'integer', 'min:0'],
            'response.solver_statistics.deterministic_time_seconds' => ['present', 'nullable', 'numeric', 'min:0'],
            'response.solver_statistics.wall_time_seconds' => ['present', 'nullable', 'numeric', 'min:0'],
            'response.solver_statistics.worker_count' => ['required', 'integer', Rule::in([1, 2, 4, 8])],
            'response.solver_statistics.random_seed' => ['required', 'integer', Rule::in([20260718])],
            'response.solver_statistics.result_source' => [
                'required',
                'string',
                Rule::in(['none', 'feasibility_fallback', 'optimization', 'lexicographic']),
            ],
            'response.solver_statistics.search_stages' => [
                'required',
                'array:feasibility,optimization',
                'required_array_keys:feasibility,optimization',
            ],
            'response.solver_statistics.search_stages.feasibility' => [
                'required',
                'array:'.implode(',', self::SearchStageStatisticsKeys),
                'required_array_keys:'.implode(',', self::SearchStageStatisticsKeys),
            ],
            'response.solver_statistics.search_stages.optimization' => [
                'required',
                'array:'.implode(',', self::SearchStageStatisticsKeys),
                'required_array_keys:'.implode(',', self::SearchStageStatisticsKeys),
            ],
            'response.solver_statistics.search_stages.*.status' => [
                'required',
                'string',
                Rule::in(['optimal', 'feasible', 'infeasible', 'model_invalid', 'unknown', 'not_run']),
            ],
            'response.solver_statistics.search_stages.*.model_variable_count' => ['required', 'integer', 'min:0'],
            'response.solver_statistics.search_stages.*.model_constraint_count' => ['required', 'integer', 'min:0'],
            'response.solver_statistics.search_stages.*.no_overlap_constraint_count' => ['required', 'integer', 'min:0'],
            'response.solver_statistics.search_stages.*.boolean_variable_count' => ['present', 'nullable', 'integer', 'min:0'],
            'response.solver_statistics.search_stages.*.branch_count' => ['present', 'nullable', 'integer', 'min:0'],
            'response.solver_statistics.search_stages.*.conflict_count' => ['present', 'nullable', 'integer', 'min:0'],
            'response.solver_statistics.search_stages.*.deterministic_time_seconds' => ['present', 'nullable', 'numeric', 'min:0'],
            'response.solver_statistics.search_stages.*.wall_time_seconds' => ['required', 'numeric', 'min:0'],
            'response.solver_version' => ['required', 'string', 'max:255'],
            'response.model_version' => ['required', 'string', 'max:255'],
            'response.generated_at' => ['required', 'date'],
            'response.assigned_count' => ['required', 'integer', 'min:0'],
            'response.unassigned_count' => ['required', 'integer', 'min:0'],
            'response.warning_count' => ['required', 'integer', 'min:0'],
            'response.timeout' => ['required', 'boolean'],
        ]);

        $findings = [];

        foreach ($validator->errors()->messages() as $field => $messages) {
            foreach ($messages as $message) {
                $findings[] = $this->finding(
                    run: $run,
                    code: 'invalid_response_field',
                    constraint: 'solver_response_contract',
                    message: $message,
                    sourceField: str($field)->after('response.')->toString(),
                );
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $solverResult
     * @param  list<mixed>  $assignments
     * @return list<array<string, mixed>>
     */
    private function counterFindings(ScheduleGenerationRun $run, array $solverResult, array $assignments): array
    {
        $snapshot = $this->arrayValue($run->getAttribute('input_snapshot'));
        $expectedDemandCount = collect($this->listValue($snapshot['scheduling_demands'] ?? null))
            ->filter(fn (mixed $demand): bool => is_array($demand))
            ->count();
        $conflicts = collect($assignments)
            ->filter(fn (mixed $assignment): bool => is_array($assignment) && ($assignment['assignment_status'] ?? null) === 'conflict')
            ->count();
        $warnings = collect($assignments)
            ->filter(fn (mixed $assignment): bool => is_array($assignment) && ($assignment['assignment_status'] ?? null) === 'warning')
            ->count();
        $expected = [
            'assigned_count' => count($assignments) - $conflicts,
            'unassigned_count' => max(0, $expectedDemandCount - (count($assignments) - $conflicts)),
            'hard_violation_count' => $conflicts,
            'warning_count' => $warnings,
        ];
        $findings = [];

        foreach ($expected as $field => $value) {
            if ((int) $solverResult[$field] === $value) {
                continue;
            }

            $findings[] = $this->finding(
                run: $run,
                code: $field.'_mismatch',
                constraint: 'solver_response_contract',
                message: "The reported {$field} does not match the assignment payload.",
                sourceField: $field,
            );
        }

        return $findings;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function timeoutFindings(ScheduleGenerationRun $run, string $status, bool $timeout): array
    {
        $expectedTimeout = $status === 'unknown';

        if ($timeout === $expectedTimeout) {
            return [];
        }

        return [$this->finding(
            run: $run,
            code: 'timeout_semantics_mismatch',
            constraint: 'solver_status',
            message: 'The timeout flag does not match the native CP-SAT status.',
            sourceField: 'timeout',
        )];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $solverResult
     * @return list<array<string, mixed>>
     */
    private function objectiveFindings(ScheduleGenerationRun $run, array $snapshot, array $solverResult): array
    {
        $details = $this->arrayValue($solverResult['objective_details']);
        $terms = $this->arrayValue($details['terms'] ?? null);
        $profile = $this->arrayValue($snapshot['constraint_profile'] ?? null);
        $expectedWeights = $this->arrayValue($profile['soft_weights'] ?? null);
        $findings = [];

        if (($details['profile_key'] ?? null) !== ($profile['key'] ?? null)
            || $this->integerValue($details['profile_version'] ?? null) !== $this->integerValue($profile['version'] ?? null)) {
            $findings[] = $this->finding(
                run: $run,
                code: 'objective_profile_mismatch',
                constraint: 'solver_response_contract',
                message: 'Objective details do not identify the captured constraint profile.',
                sourceField: 'objective_details',
            );
        }

        if (($profile['key'] ?? null) === 'lexicographic_v1') {
            $expectedHierarchy = $this->listValue($profile['objective_hierarchy'] ?? null);
            $reportedHierarchy = $this->listValue($details['objective_hierarchy'] ?? null);
            $values = $this->arrayValue($details['values'] ?? null);
            $reportedValueKeys = array_keys($values);

            if ($reportedHierarchy !== $expectedHierarchy
                || array_diff($reportedValueKeys, $expectedHierarchy) !== []
                || array_diff($expectedHierarchy, $reportedValueKeys) !== []
                || array_key_exists('terms', $details)
                || array_key_exists('total', $details)
                || ($details['scalar_score'] ?? null) !== null
                || ($solverResult['objective_score'] ?? null) !== null) {
                $findings[] = $this->finding(
                    run: $run,
                    code: 'lexicographic_evidence_mismatch',
                    constraint: 'solver_response_contract',
                    message: 'Objective evidence must preserve the fixed six-level hierarchy without weights or a scalar accuracy score.',
                    sourceField: 'objective_details',
                );
            }

            return $findings;
        }

        foreach ($expectedWeights as $name => $expectedWeight) {
            if (! array_key_exists($name, $terms)) {
                $findings[] = $this->finding(
                    run: $run,
                    code: 'objective_term_missing',
                    constraint: 'solver_response_contract',
                    message: "Objective term {$name} is missing from the captured profile.",
                    sourceField: 'objective_details.terms.'.$name,
                );

                continue;
            }

            $term = $terms[$name];

            if (is_array($term)
                && is_numeric($expectedWeight)
                && is_numeric($term['weight'] ?? null)
                && ! $this->numbersMatch((float) $expectedWeight, (float) $term['weight'])) {
                $findings[] = $this->finding(
                    run: $run,
                    code: 'objective_weight_mismatch',
                    constraint: 'solver_response_contract',
                    message: "Objective term {$name} does not use the captured profile weight.",
                    sourceField: 'objective_details.terms.'.$name.'.weight',
                );
            }
        }

        foreach (array_diff_key($terms, $expectedWeights) as $name => $term) {
            $findings[] = $this->finding(
                run: $run,
                code: 'objective_term_unexpected',
                constraint: 'solver_response_contract',
                message: "Objective term {$name} is not part of the captured profile.",
                sourceField: 'objective_details.terms.'.$name,
            );
        }

        $weightedTotal = 0.0;

        foreach ($terms as $name => $term) {
            if (! is_array($term)
                || ! is_numeric($term['raw'] ?? null)
                || ! is_numeric($term['weight'] ?? null)
                || ! is_numeric($term['weighted'] ?? null)) {
                $findings[] = $this->finding(
                    run: $run,
                    code: 'objective_term_invalid',
                    constraint: 'solver_response_contract',
                    message: "Objective term {$name} is incomplete.",
                    sourceField: 'objective_details.terms.'.$name,
                );

                continue;
            }

            $expectedWeighted = (float) $term['raw'] * (float) $term['weight'];

            if (! $this->numbersMatch($expectedWeighted, (float) $term['weighted'])) {
                $findings[] = $this->finding(
                    run: $run,
                    code: 'objective_term_mismatch',
                    constraint: 'solver_response_contract',
                    message: "Objective term {$name} does not match raw multiplied by weight.",
                    sourceField: 'objective_details.terms.'.$name.'.weighted',
                );
            }

            $weightedTotal += (float) $term['weighted'];
        }

        if (! is_numeric($details['total'] ?? null)
            || ! $this->numbersMatch($weightedTotal, (float) $details['total'])) {
            $findings[] = $this->finding(
                run: $run,
                code: 'objective_total_mismatch',
                constraint: 'solver_response_contract',
                message: 'The objective detail total does not equal its weighted terms.',
                sourceField: 'objective_details.total',
            );
        }

        if (! is_numeric($solverResult['objective_score'])
            || ! is_numeric($details['total'] ?? null)
            || ! $this->numbersMatch((float) $solverResult['objective_score'], (float) $details['total'])) {
            $findings[] = $this->finding(
                run: $run,
                code: 'objective_score_mismatch',
                constraint: 'solver_response_contract',
                message: 'The objective score does not equal the objective detail total.',
                sourceField: 'objective_score',
            );
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<mixed>  $assignments
     * @return array{list<array<string,mixed>>,list<array<string,mixed>>}
     */
    private function validateAssignments(ScheduleGenerationRun $run, array $snapshot, array $assignments): array
    {
        $demands = collect($this->listValue($snapshot['scheduling_demands'] ?? null))
            ->filter(fn (mixed $demand): bool => is_array($demand) && $this->integerValue($demand['scheduling_demand_id'] ?? null) !== null)
            ->keyBy(fn (array $demand): int => (int) $demand['scheduling_demand_id']);
        $expectedKeys = $demands->flatMap(function (array $demand): array {
            $meetingCount = max(1, (int) ($demand['meeting_count'] ?? 1));

            return collect(range(1, $meetingCount))
                ->map(fn (int $sequence): string => $demand['scheduling_demand_id'].':'.$sequence)
                ->all();
        })->all();
        $candidateRows = [];
        $normalizedAssignments = [];
        $findings = [];
        $actualKeys = [];

        foreach ($assignments as $index => $assignment) {
            if (! is_array($assignment)) {
                continue;
            }

            $demandId = $this->integerValue($assignment['scheduling_demand_id'] ?? null);
            $sequence = $this->integerValue($assignment['meeting_sequence'] ?? null);

            if ($demandId === null || $sequence === null) {
                $findings[] = $this->finding(
                    run: $run,
                    code: 'missing_assignment_identity',
                    constraint: 'assign_every_ready_scheduling_demand_once',
                    message: "Assignment {$index} is missing its demand or meeting identity.",
                    sourceField: "assignments.{$index}",
                );

                continue;
            }

            $identity = $demandId.':'.$sequence;
            $actualKeys[] = $identity;

            if (! $demands->has($demandId)) {
                $findings[] = $this->finding(
                    run: $run,
                    code: 'unknown_scheduling_demand',
                    constraint: 'assign_every_ready_scheduling_demand_once',
                    message: 'The assignment references a demand outside the immutable run snapshot.',
                    demandId: $demandId,
                    meetingSequence: $sequence,
                    sourceField: 'scheduling_demand_id',
                );

                continue;
            }

            if (count(array_keys($actualKeys, $identity, true)) > 1) {
                $findings[] = $this->finding(
                    run: $run,
                    code: 'duplicate_assignment',
                    constraint: 'assign_every_ready_scheduling_demand_once',
                    message: 'The solver returned the same demand meeting more than once.',
                    demandId: $demandId,
                    meetingSequence: $sequence,
                    sourceType: 'scheduling_demand',
                    sourceId: $demandId,
                );
            }

            /** @var array<string, mixed> $demand */
            $demand = $demands->get($demandId);
            [$normalized, $rowFindings] = $this->validateAssignment($run, $snapshot, $demand, $assignment, $demandId, $sequence);
            $findings = [...$findings, ...$rowFindings];
            $normalizedAssignments[] = $normalized;
            $candidateRows[] = [
                'schedule_run_id' => $run->id,
                'scheduling_demand_id' => $demandId,
                'meeting_sequence' => $sequence,
                'faculty_user_id' => $normalized['faculty_user_id'],
                'room_id' => $normalized['room_id'],
                'day_of_week' => $normalized['day_of_week'],
                'starts_at' => $normalized['starts_at'],
                'ends_at' => $normalized['ends_at'],
                'time_block_key' => $normalized['time_block_key'],
                'status' => $normalized['status'],
                'scores' => $normalized['scores'],
                'warnings' => $normalized['warnings'] === [] ? null : $normalized['warnings'],
                'violations' => null,
                'override_authority' => null,
                'override_reason' => null,
            ];
        }

        foreach (array_diff($expectedKeys, array_unique($actualKeys)) as $missingKey) {
            [$demandId, $sequence] = array_map('intval', explode(':', $missingKey));
            $findings[] = $this->finding(
                run: $run,
                code: 'missing_assignment',
                constraint: 'assign_every_ready_scheduling_demand_once',
                message: 'The solver response does not cover every expected demand meeting.',
                demandId: $demandId,
                meetingSequence: $sequence,
                sourceType: 'scheduling_demand',
                sourceId: $demandId,
                sourceField: 'meeting_count',
            );
        }

        foreach (array_diff(array_unique($actualKeys), $expectedKeys) as $unexpectedKey) {
            [$demandId, $sequence] = array_map('intval', explode(':', $unexpectedKey));
            $findings[] = $this->finding(
                run: $run,
                code: 'unexpected_meeting_sequence',
                constraint: 'assign_every_ready_scheduling_demand_once',
                message: 'The solver returned an unexpected meeting sequence.',
                demandId: $demandId,
                meetingSequence: $sequence,
                sourceType: 'scheduling_demand',
                sourceId: $demandId,
                sourceField: 'meeting_count',
            );
        }

        $findings = [...$findings, ...$this->persistenceFindings($run, $demands->keys()->map(fn (mixed $id): int => (int) $id)->all())];
        $findings = [...$findings, ...$this->overlapFindings($run, $normalizedAssignments)];
        $findings = [...$findings, ...$this->modalityTransitionFindings($run, $normalizedAssignments)];
        $findings = [...$findings, ...$this->sameFacultyFindings($run, $demands->all(), $normalizedAssignments)];
        $findings = [...$findings, ...$this->facultyLoadFindings($run, $snapshot, $demands->all(), $normalizedAssignments)];

        return [$candidateRows, $findings];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $demand
     * @param  array<string, mixed>  $assignment
     * @return array{array<string,mixed>,list<array<string,mixed>>}
     */
    private function validateAssignment(
        ScheduleGenerationRun $run,
        array $snapshot,
        array $demand,
        array $assignment,
        int $demandId,
        int $sequence,
    ): array {
        $facultyId = $this->integerValue($assignment['faculty_user_id'] ?? null);
        $roomId = $this->integerValue($assignment['room_id'] ?? null);
        $day = $this->integerValue($assignment['day_of_week'] ?? null);
        $startsAt = $this->stringValue($assignment['starts_at'] ?? null);
        $endsAt = $this->stringValue($assignment['ends_at'] ?? null);
        $timeBlockKey = $this->stringValue($assignment['time_block_key'] ?? null);
        $warnings = $this->listValue($assignment['warnings'] ?? null);
        $findings = [];
        $identityFields = [
            'term_offering_id' => 'term_offering_id',
            'section_id' => 'section_id',
            'section_delivery_group_id' => 'section_delivery_group_id',
            'subject_id' => 'course_id',
            'course_component_id' => 'course_component_id',
        ];

        foreach ($identityFields as $assignmentField => $demandField) {
            if ($this->integerValue($assignment[$assignmentField] ?? null) === $this->integerValue($demand[$demandField] ?? null)) {
                continue;
            }

            $findings[] = $this->finding(
                run: $run,
                code: $assignmentField.'_mismatch',
                constraint: 'assignment_identity',
                message: "The assignment {$assignmentField} does not match its captured demand.",
                demandId: $demandId,
                meetingSequence: $sequence,
                sourceType: 'scheduling_demand',
                sourceId: $demandId,
                sourceField: $demandField,
            );
        }

        $demandCohortId = $this->integerValue(
            $demand['cohort_or_student_group_id'] ?? $demand['section_delivery_group_id'] ?? null,
        );
        $demandCohortIds = $this->integerList(
            $demand['cohort_or_student_group_ids'] ?? [$demandCohortId],
        );
        $assignmentCohortId = $this->integerValue($assignment['cohort_or_student_group_id'] ?? null);
        $assignmentCohortIds = $this->integerList(
            $assignment['cohort_or_student_group_ids'] ?? [$assignmentCohortId],
        );

        if (array_key_exists('cohort_or_student_group_id', $assignment)
            && $assignmentCohortId !== $demandCohortId) {
            $findings[] = $this->finding(
                run: $run,
                code: 'cohort_or_student_group_id_mismatch',
                constraint: 'assignment_identity',
                message: 'The assignment shared cohort identity does not match its captured demand.',
                demandId: $demandId,
                meetingSequence: $sequence,
                sourceType: 'scheduling_demand',
                sourceId: $demandId,
                sourceField: 'cohort_or_student_group_id',
            );
        }

        if (array_key_exists('cohort_or_student_group_ids', $assignment)
            && $assignmentCohortIds !== $demandCohortIds) {
            $findings[] = $this->finding(
                run: $run,
                code: 'cohort_or_student_group_ids_mismatch',
                constraint: 'assignment_identity',
                message: 'The assignment cohort memberships do not match the captured shared-class demand.',
                demandId: $demandId,
                meetingSequence: $sequence,
                sourceType: 'scheduling_demand',
                sourceId: $demandId,
                sourceField: 'cohort_or_student_group_ids',
            );
        }

        if (($demand['validation_state'] ?? SchedulingDemand::ValidationReadyForReview) !== SchedulingDemand::ValidationReadyForReview) {
            $findings[] = $this->finding(
                run: $run,
                code: 'scheduling_demand_not_ready',
                constraint: 'assign_every_ready_scheduling_demand_once',
                message: 'The current Scheduling Demand is no longer ready for scheduling.',
                demandId: $demandId,
                meetingSequence: $sequence,
                sourceType: 'scheduling_demand',
                sourceId: $demandId,
                sourceField: 'validation_state',
            );
        }

        if ($facultyId !== $this->integerValue($assignment['faculty_id'] ?? null)
            || $day !== $this->integerValue($assignment['day'] ?? null)
            || $startsAt !== $this->stringValue($assignment['start_time'] ?? null)
            || $endsAt !== $this->stringValue($assignment['end_time'] ?? null)
            || $timeBlockKey !== $this->stringValue($assignment['time_block_reference'] ?? null)) {
            $findings[] = $this->finding(
                run: $run,
                code: 'assignment_alias_mismatch',
                constraint: 'solver_response_contract',
                message: 'Canonical assignment fields do not match their V2 compatibility aliases.',
                demandId: $demandId,
                meetingSequence: $sequence,
                sourceField: 'assignments',
            );
        }

        if (! in_array($assignment['assignment_status'], ['ok', 'warning'], true)
            || $this->listValue($assignment['violations'] ?? null) !== []) {
            $findings[] = $this->finding(
                run: $run,
                code: 'assignment_hard_violation',
                constraint: 'solver_status',
                message: 'A feasible or optimal result contains a conflicting assignment.',
                demandId: $demandId,
                meetingSequence: $sequence,
                sourceType: 'scheduling_demand',
                sourceId: $demandId,
            );
        }

        if ($facultyId === null || $day === null || $startsAt === null || $endsAt === null) {
            $findings[] = $this->finding(
                run: $run,
                code: 'missing_assignment_fields',
                constraint: 'assignment_identity',
                message: 'A candidate assignment is missing faculty, day, start, or end fields.',
                demandId: $demandId,
                meetingSequence: $sequence,
                sourceType: 'scheduling_demand',
                sourceId: $demandId,
            );
        }

        $findings = [...$findings, ...$this->timeFindings($run, $snapshot, $demand, $assignment, $demandId, $sequence)];
        $findings = [...$findings, ...$this->fixedAssignmentFindings($run, $demand, $assignment, $demandId, $sequence)];
        $findings = [...$findings, ...$this->facultyEligibilityFindings($run, $demand, $facultyId, $demandId, $sequence)];
        $findings = [...$findings, ...$this->roomFindings($run, $snapshot, $demand, $roomId, $demandId, $sequence)];
        $findings = [...$findings, ...$this->calendarFindings($run, $snapshot, $facultyId, $roomId, $day, $startsAt, $endsAt, $demandId, $sequence)];

        foreach ($warnings as $warning) {
            if (! is_array($warning)) {
                continue;
            }

            $findings[] = $this->finding(
                run: $run,
                code: (string) ($warning['type'] ?? 'solver_assignment_warning'),
                constraint: 'soft_constraint',
                message: (string) ($warning['message'] ?? 'The solver reported an assignment warning.'),
                severity: 'warning',
                demandId: $demandId,
                meetingSequence: $sequence,
                sourceType: 'scheduling_demand',
                sourceId: $demandId,
            );
        }

        return [[
            'scheduling_demand_id' => $demandId,
            'meeting_sequence' => $sequence,
            'term_offering_id' => $this->integerValue($assignment['term_offering_id'] ?? null),
            'section_delivery_group_id' => $this->integerValue($assignment['section_delivery_group_id'] ?? null),
            'cohort_or_student_group_id' => $demandCohortId,
            'cohort_or_student_group_ids' => $demandCohortIds,
            'modality' => mb_strtoupper((string) ($demand['modality'] ?? '')),
            'faculty_user_id' => $facultyId,
            'room_id' => $roomId,
            'day_of_week' => $day,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'time_block_key' => $timeBlockKey,
            'status' => $assignment['assignment_status'] === 'warning' || $warnings !== []
                ? CandidateScheduleRow::StatusWarning
                : CandidateScheduleRow::StatusOk,
            'scores' => $this->arrayValue($assignment['scores'] ?? null),
            'warnings' => $warnings,
        ], $findings];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $demand
     * @param  array<string, mixed>  $assignment
     * @return list<array<string, mixed>>
     */
    private function timeFindings(
        ScheduleGenerationRun $run,
        array $snapshot,
        array $demand,
        array $assignment,
        int $demandId,
        int $sequence,
    ): array {
        $day = $this->integerValue($assignment['day_of_week'] ?? null);
        $startsAt = $this->timeSeconds($assignment['starts_at'] ?? null);
        $endsAt = $this->timeSeconds($assignment['ends_at'] ?? null);
        $term = $this->arrayValue($snapshot['term'] ?? null);
        $findings = [];

        if ($day === null || $startsAt === null || $endsAt === null || $endsAt <= $startsAt) {
            return [$this->finding(
                run: $run,
                code: 'invalid_assignment_time',
                constraint: 'calendar_validity',
                message: 'The assignment time range is incomplete or invalid.',
                demandId: $demandId,
                meetingSequence: $sequence,
                sourceType: 'term',
                sourceId: $run->term_id,
                sourceField: 'scheduling_day_starts_at',
            )];
        }

        if (($endsAt - $startsAt) !== ((int) ($demand['required_duration_minutes'] ?? 0) * 60)) {
            $findings[] = $this->finding(
                run: $run,
                code: 'assignment_duration_mismatch',
                constraint: 'contact_hour_completion',
                message: 'The assignment duration does not satisfy the captured demand duration.',
                demandId: $demandId,
                meetingSequence: $sequence,
                sourceType: 'scheduling_demand',
                sourceId: $demandId,
                sourceField: 'required_duration_minutes',
            );
        }

        $schedulingDays = collect($term['scheduling_days'] ?? [])->map(fn (mixed $value): int => (int) $value)->all();
        $dayStart = $this->timeSeconds($term['scheduling_day_starts_at'] ?? null);
        $dayEnd = $this->timeSeconds($term['scheduling_day_ends_at'] ?? null);

        if (! in_array($day, $schedulingDays, true)
            || $dayStart === null
            || $dayEnd === null
            || $startsAt < $dayStart
            || $endsAt > $dayEnd) {
            $findings[] = $this->finding(
                run: $run,
                code: 'assignment_outside_operating_grid',
                constraint: 'calendar_validity',
                message: 'The assignment falls outside the captured term operating grid.',
                demandId: $demandId,
                meetingSequence: $sequence,
                sourceType: 'term',
                sourceId: $run->term_id,
                sourceField: 'scheduling_days',
            );
        }

        $timeSlotId = $this->integerValue($assignment['time_slot_id'] ?? null);

        if ($timeSlotId === null) {
            $findings[] = $this->finding(
                run: $run,
                code: 'missing_time_slot',
                constraint: 'calendar_validity',
                message: 'Every assignment, including a fixed assignment, must reference its captured starting time slot.',
                demandId: $demandId,
                meetingSequence: $sequence,
                sourceType: 'term',
                sourceId: $run->term_id,
                sourceField: 'time_slots',
            );
        }

        if ($timeSlotId !== null) {
            $slot = collect($this->listValue($snapshot['time_slots'] ?? null))
                ->first(fn (mixed $item): bool => is_array($item) && (int) ($item['time_slot_id'] ?? 0) === $timeSlotId);

            if (! is_array($slot)
                || (int) ($slot['day_of_week'] ?? 0) !== $day
                || $this->stringValue($slot['starts_at'] ?? null) !== $this->stringValue($assignment['starts_at'] ?? null)
                || $this->stringValue($slot['time_block_key'] ?? null) !== $this->stringValue($assignment['time_block_key'] ?? null)) {
                $findings[] = $this->finding(
                    run: $run,
                    code: 'time_slot_mismatch',
                    constraint: 'calendar_validity',
                    message: 'The assignment time slot does not match the captured operating grid.',
                    demandId: $demandId,
                    meetingSequence: $sequence,
                    sourceType: 'term',
                    sourceId: $run->term_id,
                    sourceField: 'time_slots',
                );
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $demand
     * @param  array<string, mixed>  $assignment
     * @return list<array<string, mixed>>
     */
    private function fixedAssignmentFindings(
        ScheduleGenerationRun $run,
        array $demand,
        array $assignment,
        int $demandId,
        int $sequence,
    ): array {
        $fields = [
            'fixed_faculty_user_id' => ['assignment_field' => 'faculty_user_id', 'code' => 'fixed_faculty_mismatch'],
            'fixed_room_id' => ['assignment_field' => 'room_id', 'code' => 'fixed_room_mismatch'],
            'fixed_day_of_week' => ['assignment_field' => 'day_of_week', 'code' => 'fixed_day_mismatch'],
            'fixed_start_time' => ['assignment_field' => 'starts_at', 'code' => 'fixed_start_time_mismatch'],
        ];
        $findings = [];

        foreach ($fields as $fixedField => $rule) {
            $fixed = $demand[$fixedField] ?? null;

            if ($fixed === null || $fixed === '') {
                continue;
            }

            $actual = $assignment[$rule['assignment_field']] ?? null;
            $matches = str_contains($fixedField, '_time')
                ? $this->stringValue($fixed) === $this->stringValue($actual)
                : $this->integerValue($fixed) === $this->integerValue($actual);

            if ($matches) {
                continue;
            }

            $findings[] = $this->finding(
                run: $run,
                code: $rule['code'],
                constraint: 'respect_fixed_assignments',
                message: "The assignment does not preserve {$fixedField}.",
                demandId: $demandId,
                meetingSequence: $sequence,
                sourceType: 'scheduling_demand',
                sourceId: $demandId,
                sourceField: $fixedField,
            );
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $demand
     * @return list<array<string, mixed>>
     */
    private function facultyEligibilityFindings(
        ScheduleGenerationRun $run,
        array $demand,
        ?int $facultyId,
        int $demandId,
        int $sequence,
    ): array {
        $eligible = collect($demand['eligible_faculty_user_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($facultyId !== null && in_array($facultyId, $eligible, true)) {
            return [];
        }

        return [$this->finding(
            run: $run,
            code: 'faculty_not_eligible',
            constraint: 'respect_faculty_qualification_and_load',
            message: 'The assigned faculty is not eligible for the captured demand.',
            demandId: $demandId,
            meetingSequence: $sequence,
            sourceType: 'scheduling_demand',
            sourceId: $demandId,
            sourceField: 'eligible_faculty_user_ids',
        )];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $demand
     * @return list<array<string, mixed>>
     */
    private function roomFindings(
        ScheduleGenerationRun $run,
        array $snapshot,
        array $demand,
        ?int $roomId,
        int $demandId,
        int $sequence,
    ): array {
        $roomRequired = (bool) ($demand['room_required'] ?? false);

        if (! $roomRequired && $roomId === null) {
            return [];
        }

        $room = collect($this->listValue($snapshot['rooms'] ?? null))
            ->first(fn (mixed $item): bool => is_array($item) && (int) ($item['room_id'] ?? 0) === $roomId);
        $requiredFeatures = collect($demand['required_room_feature_keys'] ?? [])
            ->map(fn (mixed $feature): string => mb_strtoupper(trim((string) $feature)))
            ->filter()
            ->unique();

        if (! is_array($room)
            || ($demand['room_type_requirement'] ?? null) !== null && ($room['room_type'] ?? null) !== $demand['room_type_requirement']
            || (int) ($room['capacity'] ?? 0) < (int) ($demand['expected_count'] ?? 0)
            || $requiredFeatures->diff(collect($room['feature_keys'] ?? [])->map(fn (mixed $feature): string => mb_strtoupper(trim((string) $feature))))->isNotEmpty()) {
            return [$this->finding(
                run: $run,
                code: 'room_not_suitable',
                constraint: 'respect_room_capacity_type_and_features',
                message: 'The assigned room does not satisfy the captured room requirement.',
                demandId: $demandId,
                meetingSequence: $sequence,
                sourceType: $roomId === null ? 'scheduling_demand' : 'room',
                sourceId: $roomId ?? $demandId,
                sourceField: 'room_id',
            )];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    private function calendarFindings(
        ScheduleGenerationRun $run,
        array $snapshot,
        ?int $facultyId,
        ?int $roomId,
        ?int $day,
        ?string $startsAt,
        ?string $endsAt,
        int $demandId,
        int $sequence,
    ): array {
        $start = $this->timeSeconds($startsAt);
        $end = $this->timeSeconds($endsAt);

        if ($day === null || $start === null || $end === null) {
            return [];
        }

        $findings = [];

        foreach ($this->listValue($snapshot['calendar_blocks'] ?? null) as $block) {
            if (! is_array($block) || (int) ($block['day_of_week'] ?? 0) !== $day) {
                continue;
            }

            $blockRoom = $this->integerValue($block['room_id'] ?? null);
            $blockFaculty = $this->integerValue($block['faculty_user_id'] ?? null);

            if ($blockRoom !== null && $blockRoom !== $roomId) {
                continue;
            }

            if ($blockFaculty !== null && $blockFaculty !== $facultyId) {
                continue;
            }

            $blockStart = $this->timeSeconds($block['starts_at'] ?? null);
            $blockEnd = $this->timeSeconds($block['ends_at'] ?? null);

            if ($blockStart === null || $blockEnd === null || $start >= $blockEnd || $end <= $blockStart) {
                continue;
            }

            $findings[] = $this->finding(
                run: $run,
                code: 'calendar_block_overlap',
                constraint: 'respect_calendar_blocks',
                message: 'The assignment overlaps a captured recurring scheduling block.',
                demandId: $demandId,
                meetingSequence: $sequence,
                sourceType: 'calendar_event',
                sourceId: $this->integerValue($block['calendar_event_id'] ?? null),
                sourceField: 'starts_at',
            );
        }

        return $findings;
    }

    /**
     * @param  list<array<string, mixed>>  $assignments
     * @return list<array<string, mixed>>
     */
    private function overlapFindings(ScheduleGenerationRun $run, array $assignments): array
    {
        $findings = [];

        foreach ($assignments as $leftIndex => $left) {
            foreach (array_slice($assignments, $leftIndex + 1) as $right) {
                if (! $this->assignmentsOverlap($left, $right)) {
                    continue;
                }

                $checks = [
                    'faculty_overlap' => ['faculty_user_id', 'faculty_no_overlap'],
                    'room_overlap' => ['room_id', 'room_no_overlap'],
                    'delivery_group_overlap' => ['section_delivery_group_id', 'section_delivery_group_no_overlap'],
                ];

                foreach ($checks as $code => [$field, $constraint]) {
                    if (($left[$field] ?? null) === null || ($left[$field] ?? null) !== ($right[$field] ?? null)) {
                        continue;
                    }

                    $findings[] = $this->finding(
                        run: $run,
                        code: $code,
                        constraint: $constraint,
                        message: str($code)->replace('_', ' ')->headline()->append(' was found in the candidate set.')->toString(),
                        demandId: $this->integerValue($right['scheduling_demand_id'] ?? null),
                        meetingSequence: $this->integerValue($right['meeting_sequence'] ?? null),
                        sourceType: 'scheduling_demand',
                        sourceId: $this->integerValue($right['scheduling_demand_id'] ?? null),
                        sourceField: $field,
                    );
                }

                if (array_intersect(
                    $this->integerList($left['cohort_or_student_group_ids'] ?? []),
                    $this->integerList($right['cohort_or_student_group_ids'] ?? []),
                ) !== []) {
                    $findings[] = $this->finding(
                        run: $run,
                        code: 'cohort_overlap',
                        constraint: 'section_delivery_group_no_overlap',
                        message: 'Cohort overlap was found in the candidate set.',
                        demandId: $this->integerValue($right['scheduling_demand_id'] ?? null),
                        meetingSequence: $this->integerValue($right['meeting_sequence'] ?? null),
                        sourceType: 'scheduling_demand',
                        sourceId: $this->integerValue($right['scheduling_demand_id'] ?? null),
                        sourceField: 'cohort_or_student_group_ids',
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @param  list<array<string, mixed>>  $assignments
     * @return list<array<string, mixed>>
     */
    private function modalityTransitionFindings(ScheduleGenerationRun $run, array $assignments): array
    {
        $findings = [];

        foreach ($assignments as $leftIndex => $left) {
            foreach (array_slice($assignments, $leftIndex + 1) as $right) {
                if (array_intersect(
                    $this->integerList($left['cohort_or_student_group_ids'] ?? []),
                    $this->integerList($right['cohort_or_student_group_ids'] ?? []),
                ) === []
                    || ($left['day_of_week'] ?? null) !== ($right['day_of_week'] ?? null)
                    || ($left['modality'] ?? null) === ($right['modality'] ?? null)) {
                    continue;
                }

                $leftStart = $this->timeSeconds($left['starts_at'] ?? null);
                $leftEnd = $this->timeSeconds($left['ends_at'] ?? null);
                $rightStart = $this->timeSeconds($right['starts_at'] ?? null);
                $rightEnd = $this->timeSeconds($right['ends_at'] ?? null);

                if ($leftStart === null || $leftEnd === null || $rightStart === null || $rightEnd === null) {
                    continue;
                }

                $leftToRight = $rightStart - $leftEnd;
                $rightToLeft = $leftStart - $rightEnd;

                if (! (($leftToRight >= 0 && $leftToRight < 1800) || ($rightToLeft >= 0 && $rightToLeft < 1800))) {
                    continue;
                }

                $findings[] = $this->finding(
                    run: $run,
                    code: 'cohort_modality_transition_buffer',
                    constraint: 'cohort_modality_transition_buffer',
                    message: 'The cohort requires at least 30 minutes between Online and On-campus meetings.',
                    demandId: $this->integerValue($right['scheduling_demand_id'] ?? null),
                    meetingSequence: $this->integerValue($right['meeting_sequence'] ?? null),
                    sourceType: 'scheduling_demand',
                    sourceId: $this->integerValue($right['scheduling_demand_id'] ?? null),
                    sourceField: 'modality',
                );
            }
        }

        return $findings;
    }

    /**
     * @param  array<int, array<string, mixed>>  $demands
     * @param  list<array<string, mixed>>  $assignments
     * @return list<array<string, mixed>>
     */
    private function sameFacultyFindings(ScheduleGenerationRun $run, array $demands, array $assignments): array
    {
        $requiredGroups = collect($demands)
            ->filter(fn (array $demand): bool => (bool) ($demand['same_faculty_required'] ?? false))
            ->groupBy(fn (array $demand): string => $demand['term_offering_id'].':'.$demand['section_delivery_group_id']);
        $findings = [];

        foreach ($requiredGroups as $groupDemands) {
            if ($groupDemands->count() < 2) {
                continue;
            }

            $demandIds = $groupDemands->pluck('scheduling_demand_id')->map(fn (mixed $id): int => (int) $id);
            $groupAssignments = collect($assignments)
                ->filter(fn (array $assignment): bool => $demandIds->contains((int) $assignment['scheduling_demand_id']));

            if ($groupAssignments->pluck('faculty_user_id')->filter()->unique()->count() <= 1) {
                continue;
            }

            $demandId = (int) $groupAssignments->last()['scheduling_demand_id'];
            $findings[] = $this->finding(
                run: $run,
                code: 'same_faculty_mismatch',
                constraint: 'same_faculty_requirement',
                message: 'Linked components marked same-faculty-required use different faculty assignments.',
                demandId: $demandId,
                meetingSequence: 1,
                sourceType: 'scheduling_demand',
                sourceId: $demandId,
                sourceField: 'same_faculty_required',
            );
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<int, array<string, mixed>>  $demands
     * @param  list<array<string, mixed>>  $assignments
     * @return list<array<string, mixed>>
     */
    private function facultyLoadFindings(ScheduleGenerationRun $run, array $snapshot, array $demands, array $assignments): array
    {
        $limits = collect($this->listValue($snapshot['faculty'] ?? null))
            ->filter(fn (mixed $row): bool => is_array($row) && $this->integerValue($row['faculty_id'] ?? null) !== null)
            ->keyBy(fn (array $row): int => (int) $row['faculty_id']);
        $findings = [];

        foreach (collect($assignments)->groupBy('faculty_user_id') as $facultyId => $facultyAssignments) {
            $loadGroups = [];

            foreach ($facultyAssignments as $assignment) {
                $demand = $demands[(int) $assignment['scheduling_demand_id']] ?? null;

                if (! is_array($demand)) {
                    continue;
                }

                $key = $demand['term_offering_id'].':'.$demand['section_delivery_group_id'];
                $loadGroups[$key] = max((float) ($loadGroups[$key] ?? 0), (float) ($demand['load_units'] ?? 0));
            }

            $limit = $limits->get((int) $facultyId);
            $maxAllowed = is_array($limit) ? $limit['max_allowed_units'] ?? null : null;

            if (is_numeric($maxAllowed) && array_sum($loadGroups) <= (float) $maxAllowed) {
                continue;
            }

            $first = $facultyAssignments->first();
            $demandId = is_array($first) ? (int) $first['scheduling_demand_id'] : null;
            $source = $this->loadSource($demands, (int) $facultyId, $run->term_id);
            $findings[] = $this->finding(
                run: $run,
                code: 'faculty_load_exceeded',
                constraint: 'respect_faculty_qualification_and_load',
                message: 'The deduplicated candidate teaching load exceeds the captured faculty limit.',
                demandId: $demandId,
                meetingSequence: 1,
                sourceType: $source['type'],
                sourceId: $source['id'],
                sourceField: 'max_allowed_units',
            );
        }

        return $findings;
    }

    /**
     * @param  array<int, array<string, mixed>>  $demands
     * @return array{type:string,id:int|null}
     */
    private function loadSource(array $demands, int $facultyId, int $termId): array
    {
        foreach ($demands as $demand) {
            foreach ($demand['faculty_load_options'] ?? [] as $option) {
                if (! is_array($option) || (int) ($option['faculty_user_id'] ?? 0) !== $facultyId) {
                    continue;
                }

                $overrideId = $this->integerValue($option['term_load_override_id'] ?? null);

                if ($overrideId !== null) {
                    return ['type' => 'faculty_term_load_override', 'id' => $overrideId];
                }
            }
        }

        return ['type' => 'term', 'id' => $termId];
    }

    /**
     * @param  list<int>  $demandIds
     * @return list<array<string, mixed>>
     */
    private function persistenceFindings(ScheduleGenerationRun $run, array $demandIds): array
    {
        $existing = SchedulingDemand::query()
            ->whereKey($demandIds)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return collect(array_diff($demandIds, $existing))
            ->map(fn (int $demandId): array => $this->finding(
                run: $run,
                code: 'missing_persistence_source',
                constraint: 'candidate_persistence',
                message: 'A captured Scheduling Demand no longer exists for candidate-row persistence.',
                demandId: $demandId,
                meetingSequence: 1,
                sourceType: 'scheduling_demand',
                sourceId: $demandId,
                sourceField: 'id',
            ))
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $candidateRows
     * @param  list<array<string, mixed>>  $findings
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $solverResult
     * @param  list<mixed>  $assignments
     */
    private function result(
        array $candidateRows,
        array $findings,
        array $metadata,
        array $solverResult,
        array $assignments,
    ): ScheduleValidationResult {
        $blockingCount = collect($findings)->where('severity', 'blocking')->count();
        $passes = $blockingCount === 0;
        $summary = [
            'status' => $passes ? 'accepted' : 'blocked',
            'solver_row_count' => count($assignments),
            'candidate_row_count' => $passes ? count($candidateRows) : 0,
            'ok_count' => $passes ? collect($candidateRows)->where('status', CandidateScheduleRow::StatusOk)->count() : 0,
            'warning_count' => $passes ? collect($candidateRows)->where('status', CandidateScheduleRow::StatusWarning)->count() : (int) ($solverResult['warning_count'] ?? 0),
            'conflict_count' => collect($assignments)->where('assignment_status', 'conflict')->count(),
            'rejected_count' => $blockingCount,
            'rejected_rows' => collect($findings)
                ->where('severity', 'blocking')
                ->map(fn (array $finding): array => [
                    'reason' => $finding['code'],
                    'message' => $finding['message'],
                ])
                ->values()
                ->all(),
            'assigned_count' => $this->integerValue($solverResult['assigned_count'] ?? null),
            'unassigned_count' => $this->integerValue($solverResult['unassigned_count'] ?? null),
            'hard_violation_count' => $this->integerValue($solverResult['hard_violation_count'] ?? null),
        ];

        return new ScheduleValidationResult(
            candidateRows: $passes ? $candidateRows : [],
            findings: $findings,
            metadata: $metadata,
            summary: $summary,
        );
    }

    /**
     * @param  array<string, mixed>  $solverResult
     * @return array<string, mixed>
     */
    private function metadata(array $solverResult, bool $shapeIsValid): array
    {
        return [
            'solver_status' => $solverResult['solver_status'] ?? null,
            'candidate_schedule_id' => $solverResult['candidate_schedule_id'] ?? null,
            'solver_version' => $solverResult['solver_version'] ?? null,
            'model_version' => $solverResult['model_version'] ?? null,
            'runtime_ms' => is_numeric($solverResult['runtime_seconds'] ?? null)
                ? (int) round((float) $solverResult['runtime_seconds'] * 1000)
                : null,
            'objective_score' => $solverResult['objective_score'] ?? null,
            'generated_at' => $solverResult['generated_at'] ?? null,
            'solver_statistics' => $shapeIsValid
                ? $this->arrayValue($solverResult['solver_statistics'] ?? null)
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $solverResult
     * @return list<array<string, mixed>>
     */
    private function reportedReasonFindings(ScheduleGenerationRun $run, array $solverResult): array
    {
        $reasons = [
            ...$this->listValue($solverResult['hard_constraint_violations'] ?? null),
            ...$this->listValue($solverResult['infeasible_reasons'] ?? null),
        ];

        return collect($reasons)
            ->filter(fn (mixed $reason): bool => is_array($reason))
            ->unique(fn (array $reason): string => ($reason['type'] ?? '').':'.($reason['message'] ?? ''))
            ->map(fn (array $reason): array => $this->finding(
                run: $run,
                code: (string) ($reason['type'] ?? 'solver_reason'),
                constraint: 'solver_status',
                message: (string) ($reason['message'] ?? 'The solver reported a blocking reason.'),
                sourceField: 'infeasible_reasons',
            ))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reportedWarningFindings(ScheduleGenerationRun $run, mixed $warnings): array
    {
        return collect($this->listValue($warnings))
            ->filter(fn (mixed $warning): bool => is_array($warning))
            ->map(fn (array $warning): array => $this->finding(
                run: $run,
                code: (string) ($warning['type'] ?? 'solver_warning'),
                constraint: 'soft_constraint',
                message: (string) ($warning['message'] ?? 'The solver reported a warning.'),
                severity: 'warning',
                sourceField: 'warnings',
            ))
            ->values()
            ->all();
    }

    private function blockingStatusMessage(string $status): string
    {
        return match ($status) {
            'infeasible' => 'CP-SAT proved that the captured model has no feasible solution.',
            'model_invalid' => 'CP-SAT rejected the captured model as invalid.',
            'unknown' => 'CP-SAT stopped without finding or disproving a feasible solution.',
            default => 'The solver did not return a usable candidate solution.',
        };
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function assignmentsOverlap(array $left, array $right): bool
    {
        $leftStart = $this->timeSeconds($left['starts_at'] ?? null);
        $leftEnd = $this->timeSeconds($left['ends_at'] ?? null);
        $rightStart = $this->timeSeconds($right['starts_at'] ?? null);
        $rightEnd = $this->timeSeconds($right['ends_at'] ?? null);

        return $left['day_of_week'] !== null
            && $left['day_of_week'] === $right['day_of_week']
            && $leftStart !== null
            && $leftEnd !== null
            && $rightStart !== null
            && $rightEnd !== null
            && $leftStart < $rightEnd
            && $leftEnd > $rightStart;
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(
        ScheduleGenerationRun $run,
        string $code,
        string $constraint,
        string $message,
        string $severity = 'blocking',
        ?int $demandId = null,
        ?int $meetingSequence = null,
        string $sourceType = 'schedule_run',
        ?int $sourceId = null,
        ?string $sourceField = null,
    ): array {
        return [
            'code' => $code,
            'severity' => $severity,
            'constraint' => $constraint,
            'message' => $message,
            'scheduling_demand_id' => $demandId,
            'meeting_sequence' => $meetingSequence,
            'source_type' => $sourceType,
            'source_id' => $sourceId ?? ($sourceType === 'schedule_run' ? (int) $run->id : null),
            'source_field' => $sourceField,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     */
    private function hasBlockingFindings(array $findings): bool
    {
        return collect($findings)->contains(fn (array $finding): bool => ($finding['severity'] ?? null) === 'blocking');
    }

    private function numbersMatch(float $left, float $right): bool
    {
        return abs($left - $right) < 0.0001;
    }

    private function integerValue(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function timeSeconds(mixed $value): ?int
    {
        $time = $this->stringValue($value);

        if ($time === null || preg_match('/^(\d{2}):(\d{2}):(\d{2})$/', $time, $matches) !== 1) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];
        $second = (int) $matches[3];

        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        return ($hour * 3600) + ($minute * 60) + $second;
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return list<mixed>
     */
    private function listValue(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }

    /** @return list<int> */
    private function integerList(mixed $value): array
    {
        return collect($this->listValue($value))
            ->filter(fn (mixed $item): bool => is_numeric($item) && (int) $item > 0)
            ->map(fn (mixed $item): int => (int) $item)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
