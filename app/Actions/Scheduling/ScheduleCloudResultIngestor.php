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

            $validation = $this->validator->validate($lockedRun, $solverResult);
            $preservedCount = CandidateScheduleRow::query()
                ->where('schedule_run_id', $lockedRun->id)
                ->count();
            $summary = [
                ...$validation->summary(),
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

            CandidateScheduleRow::query()
                ->where('schedule_run_id', $lockedRun->id)
                ->delete();

            foreach ($validation->candidateRows() as $candidateRow) {
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
