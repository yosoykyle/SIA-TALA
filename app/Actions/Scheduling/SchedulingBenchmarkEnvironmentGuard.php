<?php

namespace App\Actions\Scheduling;

use App\Models\CandidateScheduleRow;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionMeeting;
use Database\Seeders\ClientAlignedAcceptanceBaselineSeeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SchedulingBenchmarkEnvironmentGuard
{
    public function __construct(
        private readonly ClientAlignedAcceptanceBaselineSeeder $baselineSeeder,
    ) {}

    /**
     * @return array{
     *     driver:string,
     *     url_host:string,
     *     revision:string,
     *     image_digest:string,
     *     profile:string,
     *     cpu:string,
     *     memory:string,
     *     concurrency:int,
     *     request_timeout_seconds:int,
     *     solver_limit_seconds:int,
     *     worker_count:int,
     *     random_seed:int,
     *     min_instances:int,
     *     max_instances:int
     * }
     */
    public function assertSafe(): array
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('The scheduling benchmark requires APP_ENV=testing.');
        }

        $connection = DB::connection();

        if ($connection->getDriverName() !== 'mysql'
            || $connection->getDatabaseName() !== 'test_tala_db') {
            throw new RuntimeException('The scheduling benchmark requires MySQL test_tala_db.');
        }

        if (config('tala_integrations.scheduling_solver.driver') !== 'cloud_run') {
            throw new RuntimeException('The scheduling benchmark requires the private cloud_run driver.');
        }

        if (config('queue.default') !== 'database') {
            throw new RuntimeException('The scheduling benchmark requires the database queue connection.');
        }

        $url = $this->assertConfigured('url');
        $this->assertConfigured('audience');
        $credentialsPath = $this->assertConfigured('credentials_path');

        if (! is_file($credentialsPath)) {
            throw new RuntimeException('The configured scheduling solver credential reference does not exist.');
        }

        if ($this->baselineSeeder->state() !== ClientAlignedAcceptanceBaselineSeeder::StateComplete) {
            throw new RuntimeException('The complete TAL-96B1 client acceptance baseline is required.');
        }

        $this->assertNoOfficialSchedulingWrites();

        $urlHost = parse_url($url, PHP_URL_HOST);

        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! is_string($urlHost) || $urlHost === '') {
            throw new RuntimeException('The scheduling benchmark requires a valid HTTPS Cloud Run target URL.');
        }

        $revision = $this->benchmarkString('revision');
        $imageDigest = $this->benchmarkString('image_digest');
        $profile = $this->benchmarkString('profile');
        $cpu = $this->benchmarkString('cpu');
        $memory = $this->benchmarkString('memory');
        $concurrency = $this->benchmarkInteger('concurrency');
        $requestTimeoutSeconds = $this->benchmarkInteger('request_timeout_seconds');
        $solverLimitSeconds = $this->benchmarkInteger('solver_limit_seconds');
        $workerCount = $this->benchmarkInteger('worker_count');
        $randomSeed = $this->benchmarkInteger('random_seed');
        $minInstances = $this->benchmarkInteger('min_instances');
        $maxInstances = $this->benchmarkInteger('max_instances');

        if (preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $revision) !== 1
            || preg_match('/^sha256:[a-f0-9]{64}$/', $imageDigest) !== 1) {
            throw new RuntimeException('The scheduling benchmark revision or immutable image digest is malformed.');
        }

        $approvedProfiles = [
            'A' => ['cpu' => '1', 'memory' => '2Gi', 'worker_count' => 1],
            'B' => ['cpu' => '2', 'memory' => '4Gi', 'worker_count' => 2],
            'C' => ['cpu' => '4', 'memory' => '8Gi', 'worker_count' => 4],
        ];
        $approvedProfile = $approvedProfiles[$profile] ?? null;

        if ($approvedProfile === null
            || $cpu !== $approvedProfile['cpu']
            || $memory !== $approvedProfile['memory']
            || $concurrency !== 1
            || $requestTimeoutSeconds !== 300
            || ! in_array($solverLimitSeconds, [30, 120, 240], true)
            || $workerCount !== $approvedProfile['worker_count']
            || $randomSeed !== 20260718
            || $minInstances !== 0
            || $maxInstances !== 3) {
            throw new RuntimeException('The scheduling benchmark resource or deterministic solver configuration is outside the approved TAL-96B3 boundary.');
        }

        return [
            'driver' => 'cloud_run',
            'url_host' => $urlHost,
            'revision' => $revision,
            'image_digest' => $imageDigest,
            'profile' => $profile,
            'cpu' => $cpu,
            'memory' => $memory,
            'concurrency' => $concurrency,
            'request_timeout_seconds' => $requestTimeoutSeconds,
            'solver_limit_seconds' => $solverLimitSeconds,
            'worker_count' => $workerCount,
            'random_seed' => $randomSeed,
            'min_instances' => $minInstances,
            'max_instances' => $maxInstances,
        ];
    }

    public function assertNoOfficialSchedulingWrites(): void
    {
        if (ScheduleGenerationRun::query()->exists()
            || CandidateScheduleRow::query()->exists()
            || SectionMeeting::query()->exists()
            || DB::table('jobs')->exists()) {
            throw new RuntimeException('Clear scheduling runs, candidates, meetings, and queued jobs before benchmarking.');
        }
    }

    private function assertConfigured(string $key): string
    {
        $value = trim((string) config("tala_integrations.scheduling_solver.{$key}"));

        if ($value === '') {
            throw new RuntimeException("The scheduling solver {$key} is not configured.");
        }

        return $value;
    }

    private function benchmarkString(string $key): string
    {
        $value = trim((string) config("tala_integrations.scheduling_solver.benchmark.{$key}"));

        if ($value === '') {
            throw new RuntimeException("The scheduling benchmark {$key} metadata is not configured.");
        }

        return $value;
    }

    private function benchmarkInteger(string $key): int
    {
        $value = config("tala_integrations.scheduling_solver.benchmark.{$key}");

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException("The scheduling benchmark {$key} metadata is not a valid integer.");
        }

        return (int) $value;
    }
}
