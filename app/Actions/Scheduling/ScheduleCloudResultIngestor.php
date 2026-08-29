<?php

namespace App\Actions\Scheduling;

use App\Models\CandidateScheduleRow;
use App\Models\ScheduleGenerationRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ScheduleCloudResultIngestor
{
    public function __construct(
        private readonly ScheduleAssignmentValidationService $validator,
    ) {}

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

            if (in_array($lockedRun->status, [
                ScheduleGenerationRun::StatusPublished,
                ScheduleGenerationRun::StatusSuperseded,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Published or superseded schedule runs cannot ingest new solver results.',
                ]);
            }

            $validationStartedAt = hrtime(true);
            $validation = $this->validator->validate($lockedRun, $solverResult);
            $validationMs = max(0, (int) round((hrtime(true) - $validationStartedAt) / 1_000_000));
            $preservedCount = CandidateScheduleRow::query()
                ->where('schedule_run_id', $lockedRun->id)
                ->count();
            $summary = [
                ...$validation->summary(),
                'validation_ms' => $validationMs,
                'ingested_at' => CarbonImmutable::now(config('app.timezone'))->toIso8601String(),
                'preserved_candidate_row_count' => $validation->passes() ? 0 : $preservedCount,
            ];

            if (! $validation->passes()) {
                $this->updateRun(
                    run: $lockedRun,
                    solverResult: $solverResult,
                    validation: $validation,
                    summary: $summary,
                    status: ScheduleGenerationRun::StatusBlocked,
                    replaceCandidateKey: false,
                );
                $run->refresh();

                return $summary;
            }

            if ($lockedRun->status === ScheduleGenerationRun::StatusUnderReview
                && $preservedCount > 0) {
                throw ValidationException::withMessages([
                    'status' => 'This immutable candidate result has already been retained for review.',
                ]);
            }

            $snapshot = $lockedRun->input_snapshot;
            $operation = is_array($snapshot['operation'] ?? null) ? $snapshot['operation'] : [];
            $source = is_array($operation['source_candidate'] ?? null) ? $operation['source_candidate'] : [];
            $baseline = collect(is_array($source['assignments'] ?? null) ? $source['assignments'] : [])
                ->filter(fn (mixed $assignment): bool => is_array($assignment))
                ->keyBy(fn (array $assignment): string => $assignment['scheduling_demand_id'].':'.($assignment['meeting_sequence'] ?? 1));

            foreach ($validation->candidateRows() as $candidateRow) {
                $identity = $candidateRow['scheduling_demand_id'].':'.$candidateRow['meeting_sequence'];
                $previous = $baseline->get($identity);

                if (($operation['kind'] ?? null) === 'repair' && is_array($previous)) {
                    $candidateRow['supersedes_candidate_row_id'] = $previous['candidate_row_id'] ?? null;
                    $candidateRow['change_type'] = $this->assignmentChanged($candidateRow, $previous)
                        ? (((int) $candidateRow['scheduling_demand_id'] === (int) ($operation['requested_assignment']['scheduling_demand_id'] ?? 0)) ? 'RequestedRepair' : 'RepairImpact')
                        : 'Unchanged';
                    $candidateRow['override_authority'] = $operation['authority_reference'] ?? null;
                    $candidateRow['override_reason'] = $operation['reason'] ?? null;
                }

                CandidateScheduleRow::query()->create($candidateRow);
            }

            $this->updateRun(
                run: $lockedRun,
                solverResult: $solverResult,
                validation: $validation,
                summary: $summary,
                status: ScheduleGenerationRun::StatusUnderReview,
                replaceCandidateKey: true,
            );
            $run->refresh();

            return $summary;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $previous
     */
    private function assignmentChanged(array $candidate, array $previous): bool
    {
        return (int) $candidate['day_of_week'] !== (int) ($previous['day_of_week'] ?? $previous['day'] ?? 0)
            || (string) $candidate['starts_at'] !== (string) ($previous['starts_at'] ?? $previous['start_time'] ?? '')
            || (string) $candidate['ends_at'] !== (string) ($previous['ends_at'] ?? $previous['end_time'] ?? '')
            || (int) $candidate['faculty_user_id'] !== (int) ($previous['faculty_user_id'] ?? $previous['faculty_id'] ?? 0)
            || ($candidate['room_id'] !== null ? (int) $candidate['room_id'] : null) !== (($previous['room_id'] ?? null) !== null ? (int) $previous['room_id'] : null);
    }

    /**
     * @param  array<string, mixed>  $solverResult
     * @param  array<string, mixed>  $summary
     */
    private function updateRun(
        ScheduleGenerationRun $run,
        array $solverResult,
        ScheduleValidationResult $validation,
        array $summary,
        string $status,
        bool $replaceCandidateKey,
    ): void {
        $metadata = $validation->metadata();
        $diagnostics = $this->arrayValue($run->getAttribute('diagnostics'));
        $diagnostics['solver_result'] = [
            'solver_status' => $metadata['solver_status'],
            'candidate_schedule_id' => $metadata['candidate_schedule_id'],
            'generated_at' => $metadata['generated_at'],
            'solver_statistics' => $metadata['solver_statistics'],
            'summary' => $summary,
            'findings' => $validation->findings(),
            'soft_constraint_scores' => $solverResult['soft_constraint_scores'] ?? [],
            'objective_details' => $solverResult['objective_details'] ?? [],
            'warnings' => $solverResult['warnings'] ?? [],
            'infeasible_reasons' => $solverResult['infeasible_reasons'] ?? [],
        ];
        $attributes = [
            'status' => $status,
            'solver_version' => is_string($metadata['solver_version']) && $metadata['solver_version'] !== ''
                ? $metadata['solver_version']
                : $run->solver_version,
            'model_version' => is_string($metadata['model_version']) ? $metadata['model_version'] : null,
            'runtime_ms' => $metadata['runtime_ms'],
            'objective_value' => is_numeric($metadata['objective_score']) ? $metadata['objective_score'] : null,
            'quality_measures' => is_array(data_get($solverResult, 'objective_details.values'))
                ? data_get($solverResult, 'objective_details.values')
                : null,
            'diagnostics' => $diagnostics,
        ];

        if ($replaceCandidateKey) {
            $attributes['candidate_key'] = $metadata['candidate_schedule_id'];
        }

        $run->forceFill($attributes)->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
