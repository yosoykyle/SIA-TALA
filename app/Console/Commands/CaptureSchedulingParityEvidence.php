<?php

namespace App\Console\Commands;

use App\Actions\Scheduling\ScheduleAssignmentValidationService;
use App\Actions\Scheduling\SchedulingOperatingEnvelopeEnvironmentGuard;
use App\Actions\Scheduling\SchedulingOperatingEnvelopeSnapshotCapture;
use App\Actions\Scheduling\SchedulingParityEvidenceBuilder;
use App\Actions\Scheduling\SchedulingScenarioFeasibilityWitnessBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class CaptureSchedulingParityEvidence extends Command
{
    protected $signature = 'scheduling:capture-parity-evidence
        {scenario : Exact MIN, MIDDLE, or MAX fixture currently loaded in test_tala_db}
        {--output= : Private local-disk JSON evidence path}';

    protected $description = 'Capture a local-only replayable TAL-96D5D scheduling parity artifact without invoking CP-SAT or Cloud Run.';

    public function handle(
        SchedulingOperatingEnvelopeEnvironmentGuard $environmentGuard,
        SchedulingOperatingEnvelopeSnapshotCapture $snapshotCapture,
        SchedulingScenarioFeasibilityWitnessBuilder $witnessBuilder,
        ScheduleAssignmentValidationService $assignmentValidator,
        SchedulingParityEvidenceBuilder $evidenceBuilder,
    ): int {
        try {
            $scenario = mb_strtoupper(trim((string) $this->argument('scenario')));
            $environmentGuard->assertLocalReplaySafe($scenario);
            $capture = $snapshotCapture->capture($scenario);
            $assignments = $witnessBuilder->build($capture['snapshot']);
            $validation = $assignmentValidator->validateCandidateAssignments(
                $capture['run'],
                $capture['snapshot'],
                $assignments,
            );

            if (! $validation->passes()) {
                throw new RuntimeException('The deterministic witness failed independent Laravel validation.');
            }

            $artifact = $evidenceBuilder->build($capture, $assignments, $validation);
            $allowlistedValidation = $assignmentValidator->validateCandidateAssignments(
                $capture['run'],
                $artifact['snapshot'],
                $artifact['assignments'],
            );

            if (! $allowlistedValidation->passes()) {
                throw new RuntimeException('The allowlisted parity payload failed Laravel validation before storage.');
            }

            $artifact = $evidenceBuilder->build($capture, $assignments, $allowlistedValidation);
            $path = $this->outputPath($scenario);
            $written = Storage::disk('local')->put(
                $path,
                json_encode($artifact, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
            );

            if (! $written) {
                throw new RuntimeException('The private parity artifact could not be written.');
            }

            $stored = json_decode(Storage::disk('local')->get($path), true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($stored)) {
                throw new RuntimeException('The stored parity artifact is not a valid object.');
            }

            if (! $evidenceBuilder->hasValidPayloadHash($stored)) {
                throw new RuntimeException('The stored parity artifact failed its read-back integrity check.');
            }

            $storedValidation = $assignmentValidator->validateCandidateAssignments(
                $capture['run'],
                $stored['snapshot'] ?? [],
                $stored['assignments'] ?? [],
            );
            $storedAssignments = is_array($stored['assignments'] ?? null)
                ? $stored['assignments']
                : [];
            $storedFindingCodes = collect($storedValidation->findings())
                ->pluck('code')
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();

            if (! $storedValidation->passes()
                || data_get($stored, 'laravel_validation.passes') !== true
                || data_get($stored, 'laravel_validation.assignment_count') !== count($storedAssignments)
                || data_get($stored, 'laravel_validation.finding_codes') !== $storedFindingCodes) {
                throw new RuntimeException('The stored parity artifact failed independent Laravel replay validation.');
            }

            $this->info("TAL-96D5D {$scenario} private parity evidence ready.");
            $this->line('snapshot_sha256='.$stored['snapshot_sha256']);
            $this->line('assignment_sha256='.$stored['assignment_sha256']);
            $this->line('assignment_count='.count($storedAssignments));
            $this->line('evidence_path='.$path);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function outputPath(string $scenario): string
    {
        $configured = trim((string) $this->option('output'));

        if ($configured !== '') {
            if (! str_starts_with($configured, 'benchmarks/')) {
                throw new RuntimeException('Parity evidence must be stored below the private benchmarks directory.');
            }

            return $configured;
        }

        $timestamp = CarbonImmutable::now(config('app.timezone'))->format('Ymd-His');

        return 'benchmarks/tal96d5d-parity-'.mb_strtolower($scenario)."-{$timestamp}.json";
    }
}
