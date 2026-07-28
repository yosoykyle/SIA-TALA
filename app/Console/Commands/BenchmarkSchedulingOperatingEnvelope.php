<?php

namespace App\Console\Commands;

use App\Actions\Scheduling\SchedulingOperatingEnvelopeCostEstimator;
use App\Actions\Scheduling\SchedulingOperatingEnvelopeEnvironmentGuard;
use App\Actions\Scheduling\SchedulingOperatingEnvelopeRunner;
use App\Actions\Scheduling\SchedulingOperatingEnvelopeSnapshotCapture;
use App\Actions\Scheduling\SchedulingScenarioFeasibilityAudit;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Captures one exact TAL-96D scenario and sends only explicitly authorized
 * requests to a private zero-traffic Cloud Run solver revision.
 */
final class BenchmarkSchedulingOperatingEnvelope extends Command
{
    protected $signature = 'scheduling:benchmark-operating-envelope
        {scenario : Exact MIN, MIDDLE, or MAX fixture currently loaded in test_tala_db}
        {--repetitions=1 : Number of requests for this invocation; allowed range is 1 to 3}
        {--output= : Private local-disk JSON evidence path}';

    protected $description = 'Run a guarded TAL-96D5D Cloud Run operating-envelope measurement for one exact population scenario.';

    public function handle(
        SchedulingOperatingEnvelopeEnvironmentGuard $environmentGuard,
        SchedulingOperatingEnvelopeSnapshotCapture $snapshotCapture,
        SchedulingOperatingEnvelopeRunner $runner,
        SchedulingOperatingEnvelopeCostEstimator $costEstimator,
        SchedulingScenarioFeasibilityAudit $feasibilityAudit,
    ): int {
        try {
            $repetitions = filter_var($this->option('repetitions'), FILTER_VALIDATE_INT);

            if (! is_int($repetitions) || $repetitions < 1 || $repetitions > 3) {
                $this->error('TAL-96D5D repetitions must be between 1 and 3 per invocation.');

                return self::FAILURE;
            }

            $scenario = mb_strtoupper(trim((string) $this->argument('scenario')));
            $target = $environmentGuard->assertSafe($scenario);
            $capture = $snapshotCapture->capture($scenario);
            $scenarioAudit = $feasibilityAudit->assess($capture['snapshot']);

            if (! $scenarioAudit['passes_necessary_conditions']) {
                throw new \RuntimeException(
                    'The fixture failed the TAL-96D5D local necessary-condition audit; no Cloud request was sent.',
                );
            }

            $result = $runner->run(
                run: $capture['run'],
                snapshot: $capture['snapshot'],
                target: $target,
                composition: $capture['composition'],
                evidenceLabels: $capture['evidence_labels'],
                repetitions: $repetitions,
            );
            $report = [
                'benchmark_version' => 'tal96d5d-v3',
                'contract_version' => $capture['snapshot']['contract_version'],
                'generated_at' => CarbonImmutable::now(config('app.timezone'))->toIso8601String(),
                'scenario' => $scenario,
                'manifest' => $capture['manifest'],
                'composition' => $capture['composition'],
                'snapshot_sha256' => $capture['snapshot_sha256'],
                'scenario_feasibility_audit' => $scenarioAudit,
                'target' => $target,
                'cost_assumptions' => $costEstimator->assumptions(),
                'probe' => $result['probe'],
                'runs' => $result['runs'],
                'summary' => $result['summary'],
                'interpretation' => [
                    'capacity_claim' => 'This report is evidence for the disclosed fixture and revision only; it is not an absolute institutional or solver ceiling.',
                    'accuracy' => 'TALA reports constraint-valid solution quality and coverage, not an ML-style accuracy percentage.',
                    'cost' => 'The estimate is a gross request-based proxy until reconciled with Cloud Billing export data.',
                ],
            ];
            $path = $this->outputPath($scenario);
            $encoded = json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            Storage::disk('local')->put($path, $encoded.PHP_EOL);
            $stored = json_decode(Storage::disk('local')->get($path), true, 512, JSON_THROW_ON_ERROR);

            if (($stored['benchmark_version'] ?? null) !== 'tal96d5d-v3'
                || ($stored['scenario'] ?? null) !== $scenario
                || ($stored['snapshot_sha256'] ?? null) !== $capture['snapshot_sha256']) {
                throw new \RuntimeException('The stored TAL-96D5D evidence failed its read-back integrity check.');
            }

            $this->info("TAL-96D5D {$scenario} operating-envelope evidence ready.");
            $this->line('configuration='.$target['configuration_id']);
            $this->line('snapshot_sha256='.$capture['snapshot_sha256']);
            $this->line('attempted_requests='.$result['summary']['attempted_run_count']);
            $this->line('accepted_requests='.$result['summary']['accepted_run_count']);
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
            return $configured;
        }

        $timestamp = CarbonImmutable::now(config('app.timezone'))->format('Ymd-His');

        return 'benchmarks/tal96d5d-'.mb_strtolower($scenario)."-{$timestamp}.json";
    }
}
