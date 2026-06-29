<?php

namespace App\Actions\Scheduling;

use App\Models\CandidateScheduleRow;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleCloudResultIngestor
{
    /**
     * @param  array<string, mixed>  $solverResult
     * @return array<string, mixed>
     */
    public function ingest(ScheduleGenerationRun $run, array $solverResult): array
    {
        return DB::transaction(function () use ($run, $solverResult): array {
            /** @var ScheduleGenerationRun $lockedRun */
            $lockedRun = ScheduleGenerationRun::query()
                ->lockForUpdate()
                ->findOrFail($run->id);

            if (($this->inputSnapshot($lockedRun)['contract_version'] ?? null) !== 'tal61-demand-v1') {
                throw ValidationException::withMessages([
                    'input_snapshot' => 'Solver result ingestion requires a TAL-61 demand input snapshot.',
                ]);
            }

            if (in_array($lockedRun->status, [
                ScheduleGenerationRun::StatusPublished,
                ScheduleGenerationRun::StatusSuperseded,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Published or superseded schedule runs cannot ingest new solver results.',
                ]);
            }

            CandidateScheduleRow::query()
                ->where('schedule_run_id', $lockedRun->id)
                ->delete();

            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $assignments = $this->assignments($solverResult);
            $summary = $this->emptySummary($timestamp, count($assignments));
            $blockingSolverOutcome = $this->blockingSolverOutcome($solverResult, $assignments);

            if ($blockingSolverOutcome !== null) {
                $summary['status'] = 'blocked';
                $summary['blocked_reason'] = $blockingSolverOutcome;

                $this->updateRunFromResult($lockedRun, $solverResult, $summary, ScheduleGenerationRun::StatusBlocked);
                $run->refresh();

                return $summary;
            }

            $acceptedRows = collect();

            foreach ($assignments as $index => $rawRow) {
                if (! is_array($rawRow)) {
                    $summary['rejected_count']++;
                    $summary['rejected_rows'][] = [
                        'index' => $index,
                        'reason' => 'assignment_payload_must_be_an_object',
                    ];

                    continue;
                }

                $prepared = $this->prepareRow($lockedRun, $rawRow, $acceptedRows);

                if ($prepared['rejected_reason'] !== null) {
                    $summary['rejected_count']++;
                    $summary['rejected_rows'][] = [
                        'index' => $index,
                        'reason' => $prepared['rejected_reason'],
                    ];

                    continue;
                }

                CandidateScheduleRow::query()->create($prepared['payload']);

                $status = $prepared['payload']['status'];
                $summary['candidate_row_count']++;
                $summary[$status.'_count']++;
                $acceptedRows->push($prepared['payload']);
            }

            $nextStatus = $summary['conflict_count'] > 0 || $summary['rejected_count'] > 0
                ? ScheduleGenerationRun::StatusBlocked
                : ScheduleGenerationRun::StatusUnderReview;

            if ($summary['candidate_row_count'] === 0) {
                $summary['status'] = 'blocked';
                $summary['blocked_reason'] = 'no_accepted_candidate_rows';
                $nextStatus = ScheduleGenerationRun::StatusBlocked;
            }

            $this->updateRunFromResult($lockedRun, $solverResult, $summary, $nextStatus);
            $run->refresh();

            return $summary;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $solverResult
     * @return list<mixed>
     */
    private function assignments(array $solverResult): array
    {
        if (is_array($solverResult['assignments'] ?? null)) {
            return array_values($solverResult['assignments']);
        }

        if (is_array($solverResult['draft_rows'] ?? null)) {
            return array_values($solverResult['draft_rows']);
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $rawRow
     * @param  Collection<int, array<string, mixed>>  $acceptedRows
     * @return array{payload:array<string, mixed>,rejected_reason:string|null}
     */
    private function prepareRow(ScheduleGenerationRun $run, array $rawRow, Collection $acceptedRows): array
    {
        $demandId = $this->integerValue($rawRow['scheduling_demand_id'] ?? null);

        if ($demandId === null) {
            return $this->rejected('missing_scheduling_demand_identifier');
        }

        $snapshotDemand = $this->snapshotDemand($run, $demandId);

        if ($snapshotDemand === null) {
            return $this->rejected('scheduling_demand_not_in_input_snapshot');
        }

        $demand = SchedulingDemand::query()
            ->whereKey($demandId)
            ->where('validation_state', SchedulingDemand::ValidationReadyForReview)
            ->first();

        if (! $demand instanceof SchedulingDemand) {
            return $this->rejected('scheduling_demand_not_ready_for_review');
        }

        $payload = [
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demandId,
            'meeting_sequence' => max(1, $this->integerValue($rawRow['meeting_sequence'] ?? null) ?? 1),
            'faculty_user_id' => $this->integerValue($rawRow['faculty_user_id'] ?? $rawRow['faculty_id'] ?? null),
            'room_id' => $this->integerValue($rawRow['room_id'] ?? null),
            'day_of_week' => $this->integerValue($rawRow['day_of_week'] ?? $rawRow['day'] ?? null),
            'starts_at' => $this->timeValue($rawRow['starts_at'] ?? $rawRow['start_time'] ?? null),
            'ends_at' => $this->timeValue($rawRow['ends_at'] ?? $rawRow['end_time'] ?? null),
            'time_block_key' => $this->stringValue($rawRow['time_block_key'] ?? $rawRow['time_block_reference'] ?? null),
            'scores' => $this->arrayOrNull($rawRow['scores'] ?? $rawRow['soft_constraint_scores'] ?? null),
            'warnings' => $this->arrayOrNull($rawRow['warnings'] ?? $rawRow['warning_payload'] ?? null),
            'violations' => $this->arrayOrNull($rawRow['violations'] ?? $rawRow['hard_constraint_violations'] ?? $rawRow['conflict_payload'] ?? null),
            'override_authority' => null,
            'override_reason' => null,
        ];

        $violations = $this->violationsFor($payload, $rawRow, $snapshotDemand, $acceptedRows);
        $warnings = $payload['warnings'] ?? [];

        if ($violations !== []) {
            $payload['violations'] = $violations;
        }

        $payload['status'] = $this->statusFor($rawRow, $violations, $warnings);

        return [
            'payload' => $payload,
            'rejected_reason' => null,
        ];
    }

    /**
     * @return array{payload:array<string, mixed>,rejected_reason:string}
     */
    private function rejected(string $reason): array
    {
        return [
            'payload' => [],
            'rejected_reason' => $reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $rawRow
     * @param  array<string, mixed>  $snapshotDemand
     * @param  Collection<int, array<string, mixed>>  $acceptedRows
     * @return list<array<string, mixed>>
     */
    private function violationsFor(array $payload, array $rawRow, array $snapshotDemand, Collection $acceptedRows): array
    {
        $violations = $this->payloadItems($payload['violations'] ?? []);

        if (($rawRow['assignment_status'] ?? $rawRow['status'] ?? null) === CandidateScheduleRow::StatusConflict) {
            $violations[] = $this->violation('solver_reported_conflict', 'The solver marked this assignment as conflicting.');
        }

        foreach (['faculty_user_id', 'day_of_week', 'starts_at', 'ends_at'] as $field) {
            if ($payload[$field] === null) {
                $violations[] = $this->violation('missing_'.$field, "Missing required {$field}.");
            }
        }

        if ($payload['day_of_week'] !== null && ($payload['day_of_week'] < 1 || $payload['day_of_week'] > 7)) {
            $violations[] = $this->violation('invalid_day_of_week', 'Schedule day must be from Monday to Sunday.');
        }

        if ($payload['starts_at'] !== null && $payload['ends_at'] !== null && $payload['starts_at'] >= $payload['ends_at']) {
            $violations[] = $this->violation('invalid_time_range', 'End time must be after the start time.');
        }

        if (($snapshotDemand['room_required'] ?? false) === true && $payload['room_id'] === null) {
            $violations[] = $this->violation('missing_required_room', 'A room is required for this Face-to-Face assignment.');
        }

        if ($payload['faculty_user_id'] !== null) {
            $eligibleFaculty = collect($snapshotDemand['eligible_faculty_user_ids'] ?? [])
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            if (! in_array((int) $payload['faculty_user_id'], $eligibleFaculty, true)) {
                $violations[] = $this->violation('faculty_not_in_demand_eligibility', 'The assigned faculty is not part of the Scheduling Demand eligibility snapshot.');
            }
        }

        foreach ($acceptedRows as $acceptedRow) {
            if (! $this->overlaps($payload, $acceptedRow)) {
                continue;
            }

            if ((int) $acceptedRow['scheduling_demand_id'] === (int) $payload['scheduling_demand_id']) {
                $violations[] = $this->violation('duplicate_demand_assignment_overlap', 'The solver proposed overlapping rows for the same Scheduling Demand.');
            }

            if ((int) $acceptedRow['faculty_user_id'] === (int) $payload['faculty_user_id']) {
                $violations[] = $this->violation('internal_faculty_overlap', 'The solver proposed overlapping rows for the same faculty.');
            }

            if ($payload['room_id'] !== null && (int) ($acceptedRow['room_id'] ?? 0) === (int) $payload['room_id']) {
                $violations[] = $this->violation('internal_room_overlap', 'The solver proposed overlapping rows for the same room.');
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function overlaps(array $left, array $right): bool
    {
        return $left['day_of_week'] !== null
            && $left['day_of_week'] === $right['day_of_week']
            && $left['starts_at'] !== null
            && $left['ends_at'] !== null
            && $left['starts_at'] < $right['ends_at']
            && $left['ends_at'] > $right['starts_at'];
    }

    /**
     * @param  array<string, mixed>  $rawRow
     * @param  list<array<string, mixed>>  $violations
     * @param  array<mixed>  $warnings
     */
    private function statusFor(array $rawRow, array $violations, array $warnings): string
    {
        if ($violations !== []) {
            return CandidateScheduleRow::StatusConflict;
        }

        if (($rawRow['assignment_status'] ?? $rawRow['status'] ?? null) === CandidateScheduleRow::StatusWarning || $warnings !== []) {
            return CandidateScheduleRow::StatusWarning;
        }

        return CandidateScheduleRow::StatusOk;
    }

    /**
     * @param  array<string, mixed>  $solverResult
     * @param  list<mixed>  $assignments
     */
    private function blockingSolverOutcome(array $solverResult, array $assignments): ?string
    {
        $status = $this->stringValue($solverResult['solver_status'] ?? null);
        $normalizedStatus = $status !== null ? mb_strtolower($status) : null;

        if ($status === null) {
            return 'missing_solver_status';
        }

        if (! in_array($normalizedStatus, ['optimal', 'feasible', 'partial', 'infeasible', 'local_stub', 'ok'], true)) {
            return 'unsupported_solver_status';
        }

        if ((bool) ($solverResult['timeout'] ?? false)) {
            return 'solver_timeout';
        }

        if ($assignments === []) {
            return 'missing_assignments';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function updateRunFromResult(
        ScheduleGenerationRun $run,
        array $solverResult,
        array $summary,
        string $status,
    ): void {
        $diagnostics = $this->arrayValue($run->getAttribute('diagnostics'));
        $diagnostics['solver_result'] = [
            'solver_status' => $solverResult['solver_status'] ?? null,
            'candidate_schedule_id' => $solverResult['candidate_schedule_id'] ?? null,
            'summary' => $summary,
            'warnings' => $solverResult['warnings'] ?? [],
            'infeasible_reasons' => $solverResult['infeasible_reasons'] ?? [],
        ];

        $run->forceFill([
            'status' => $status,
            'solver_version' => (string) ($solverResult['solver_version'] ?? $run->solver_version ?? 'unknown'),
            'model_version' => $solverResult['model_version'] ?? $run->model_version,
            'runtime_ms' => $this->runtimeMs($solverResult),
            'objective_value' => $solverResult['objective_score'] ?? $run->objective_value,
            'candidate_key' => $solverResult['candidate_schedule_id'] ?? $run->candidate_key,
            'diagnostics' => $diagnostics,
        ])->save();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function snapshotDemand(ScheduleGenerationRun $run, int $demandId): ?array
    {
        $snapshot = $this->inputSnapshot($run);

        $demand = collect($snapshot['scheduling_demands'] ?? [])
            ->first(fn (mixed $item): bool => is_array($item) && (int) ($item['scheduling_demand_id'] ?? 0) === $demandId);

        return is_array($demand) ? $demand : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(CarbonImmutable $timestamp, int $solverRowCount): array
    {
        return [
            'status' => 'ingested',
            'ingested_at' => $timestamp->toIso8601String(),
            'solver_row_count' => $solverRowCount,
            'candidate_row_count' => 0,
            'ok_count' => 0,
            'warning_count' => 0,
            'conflict_count' => 0,
            'rejected_count' => 0,
            'rejected_rows' => [],
        ];
    }

    private function runtimeMs(array $solverResult): ?int
    {
        if (($solverResult['runtime_seconds'] ?? null) !== null) {
            return (int) round(((float) $solverResult['runtime_seconds']) * 1000);
        }

        if (($solverResult['solve_time_ms'] ?? null) !== null) {
            return (int) $solverResult['solve_time_ms'];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function inputSnapshot(ScheduleGenerationRun $run): array
    {
        return $this->arrayValue($run->getAttribute('input_snapshot'));
    }

    private function integerValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function timeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $time = (string) $value;
        $time = strlen($time) === 5 ? $time.':00' : $time;

        return strlen($time) > 8 ? substr($time, 0, 8) : $time;
    }

    /**
     * @return array<mixed>|null
     */
    private function arrayOrNull(mixed $value): ?array
    {
        if ($value === null || $value === []) {
            return null;
        }

        return is_array($value) ? $value : [$value];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function payloadItems(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $items = $payload['items'] ?? $payload;

        return is_array($items) ? array_values($items) : [];
    }

    /**
     * @return array{type:string,message:string}
     */
    private function violation(string $type, string $message): array
    {
        return [
            'type' => $type,
            'message' => $message,
        ];
    }
}
