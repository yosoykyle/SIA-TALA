<?php

namespace App\Console\Commands;

use App\Actions\Scheduling\SchedulingBenchmarkDatasetFactory;
use App\Actions\Scheduling\SchedulingBenchmarkEnvironmentGuard;
use App\Actions\Scheduling\SchedulingBenchmarkRunner;
use App\Actions\Scheduling\SchedulingBenchmarkSnapshotCapture;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use Throwable;

final class BenchmarkSchedulingSolver extends Command
{
    /** @var string */
    protected $signature = 'scheduling:benchmark-cloud-run
        {--tiers=reduced,representative,proportional-2x,contention-2x,proportional-4x : Comma-separated deterministic benchmark tiers}
        {--repetitions=3 : Sequential repetitions per selected tier}
        {--output= : Relative JSON path under private local benchmark storage}';

    /** @var string */
    protected $description = 'Run the guarded TAL-96B3 Cloud Run scheduling benchmark';

    public function handle(
        SchedulingBenchmarkEnvironmentGuard $environmentGuard,
        SchedulingBenchmarkSnapshotCapture $snapshotCapture,
        SchedulingBenchmarkDatasetFactory $datasetFactory,
        SchedulingBenchmarkRunner $benchmarkRunner,
    ): int {
        try {
            $tiers = $this->selectedTiers();
            $repetitions = $this->repetitions();
            $outputPath = $this->outputPath();

            if (! in_array('representative', $tiers, true)) {
                $this->error('The representative tier is required as the TAL-96B3 acceptance gate.');

                return self::FAILURE;
            }

            $target = $environmentGuard->assertSafe();
            $capture = $snapshotCapture->capture();
            $datasets = $datasetFactory->makeMany($capture['snapshot'], $tiers);
            $report = $benchmarkRunner->run(
                representativeRun: $capture['run'],
                datasets: $datasets,
                repetitions: $repetitions,
                target: $target,
            );
            $environmentGuard->assertNoOfficialSchedulingWrites();
            $this->writeReport($outputPath, $report);

            if (($report['overall_status'] ?? null) !== 'accepted') {
                $this->error('TAL-96B3 benchmark stopped at '.$report['stop_reason'].'.');
                $this->line("Sanitized evidence: {$outputPath}");

                return self::FAILURE;
            }

            $this->info('TAL-96B3 benchmark evidence ready.');
            $this->line('Largest accepted tested tier: '.($report['largest_accepted_tested_tier'] ?? 'none'));
            $this->line("Sanitized evidence: {$outputPath}");

            return self::SUCCESS;
        } catch (RuntimeException|JsonException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('TAL-96B3 benchmark failed before sanitized evidence could be completed.');

            return self::FAILURE;
        }
    }

    /** @return list<string> */
    private function selectedTiers(): array
    {
        $value = $this->option('tiers');

        if (! is_string($value)) {
            throw new RuntimeException('The benchmark tiers option must be a comma-separated string.');
        }

        $tiers = collect(explode(',', $value))
            ->map(fn (string $tier): string => trim($tier))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $unknownTiers = array_values(array_diff($tiers, SchedulingBenchmarkDatasetFactory::Tiers));

        if ($tiers === [] || $unknownTiers !== []) {
            throw new RuntimeException('The benchmark tiers option contains an unknown or empty tier.');
        }

        return $tiers;
    }

    private function repetitions(): int
    {
        $value = $this->option('repetitions');

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException('Benchmark repetitions must be an integer from 1 through 10.');
        }

        $repetitions = (int) $value;

        if ($repetitions < 1 || $repetitions > 10) {
            throw new RuntimeException('Benchmark repetitions must be an integer from 1 through 10.');
        }

        return $repetitions;
    }

    private function outputPath(): string
    {
        $value = $this->option('output');
        $path = is_string($value) && trim($value) !== ''
            ? str_replace('\\', '/', trim($value))
            : 'benchmarks/tal96b3/tal96b3-'.now()->format('Ymd-His').'.json';

        if (! str_starts_with($path, 'benchmarks/')
            || str_contains($path, '..')
            || str_contains($path, ':')
            || ! str_ends_with(mb_strtolower($path), '.json')) {
            throw new RuntimeException('Benchmark output must be a relative JSON path below benchmarks/.');
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $report
     *
     * @throws JsonException
     */
    private function writeReport(string $path, array $report): void
    {
        $json = json_encode(
            $report,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );

        if (! Storage::disk('local')->put($path, $json)) {
            throw new RuntimeException('The sanitized benchmark evidence could not be written to private storage.');
        }

        $decoded = json_decode(Storage::disk('local')->get($path), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)
                || ($decoded['benchmark_version'] ?? null) !== 'tal96b3-v2'
                || ! is_array($decoded['tiers'] ?? null)
                || ! is_array($decoded['health'] ?? null)
                || ! array_key_exists('largest_attempted_tested_tier', $decoded)
            || ! is_string($decoded['stop_reason'] ?? null)
            || ! in_array($decoded['overall_status'] ?? null, ['accepted', 'failed'], true)
            || ($decoded['overall_status'] === 'accepted'
                && ! isset($decoded['tiers']['representative']))) {
            Storage::disk('local')->delete($path);

            throw new RuntimeException('The sanitized benchmark evidence failed its read-back validation.');
        }
    }
}
