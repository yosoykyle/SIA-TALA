<?php

namespace App\Actions\Scheduling;

use App\Actions\SystemAdministration\SchedulingAcceptanceScenarioCatalog;
use App\Models\CandidateScheduleRow;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SchedulingOperatingEnvelopeEnvironmentGuard
{
    public function __construct(
        private readonly SchedulingAcceptanceScenarioCatalog $scenarioCatalog,
    ) {}

    /**
     * @return array{
     *     driver:'cloud_run',
     *     url_host:string,
     *     revision:string,
     *     image_digest:string,
     *     configuration_id:string,
     *     cpu:int,
     *     memory:string,
     *     memory_gib:int,
     *     concurrency:int,
     *     request_timeout_seconds:int,
     *     solver_limit_seconds:int,
     *     worker_count:int,
     *     random_seed:int,
     *     min_instances:int,
     *     max_instances:int
     * }
     */
    public function assertSafe(string $scenario): array
    {
        $this->assertLocalReplaySafe($scenario);

        if (config('tala_integrations.scheduling_solver.driver') !== 'cloud_run') {
            throw new RuntimeException('TAL-96D5D requires the private cloud_run solver driver.');
        }

        if (config('queue.default') !== 'database') {
            throw new RuntimeException('TAL-96D5D requires the database queue boundary.');
        }

        $url = (string) config('tala_integrations.scheduling_solver.url');
        $audience = (string) config('tala_integrations.scheduling_solver.audience');
        $credentialsPath = (string) config('tala_integrations.scheduling_solver.credentials_path');
        $host = parse_url($url, PHP_URL_HOST);

        if (! str_starts_with($url, 'https://') || ! is_string($host) || $host === '') {
            throw new RuntimeException('TAL-96D5D requires a private HTTPS Cloud Run endpoint.');
        }

        if (! str_starts_with($audience, 'https://')) {
            throw new RuntimeException('TAL-96D5D requires an HTTPS Cloud Run audience.');
        }

        if ($credentialsPath === '' || ! is_file($credentialsPath) || ! is_readable($credentialsPath)) {
            throw new RuntimeException('TAL-96D5D requires a readable dedicated invoker credential file.');
        }

        $configured = config('tala_integrations.scheduling_solver.operating_envelope');

        if (! is_array($configured)) {
            throw new RuntimeException('TAL-96D5D operating-envelope metadata is not configured.');
        }

        $target = [
            'driver' => 'cloud_run',
            'url_host' => $host,
            'revision' => (string) ($configured['revision'] ?? ''),
            'image_digest' => (string) ($configured['image_digest'] ?? ''),
            'configuration_id' => (string) ($configured['configuration_id'] ?? ''),
            'cpu' => (int) ($configured['cpu'] ?? 0),
            'memory' => (string) ($configured['memory'] ?? ''),
            'memory_gib' => (int) ($configured['memory_gib'] ?? 0),
            'concurrency' => (int) ($configured['concurrency'] ?? 0),
            'request_timeout_seconds' => (int) ($configured['request_timeout_seconds'] ?? 0),
            'solver_limit_seconds' => (int) ($configured['solver_limit_seconds'] ?? 0),
            'worker_count' => (int) ($configured['worker_count'] ?? 0),
            'random_seed' => (int) ($configured['random_seed'] ?? 0),
            'min_instances' => (int) ($configured['min_instances'] ?? -1),
            'max_instances' => (int) ($configured['max_instances'] ?? 0),
        ];

        $approvedConfigurations = [
            'FINAL-CFG-02-MEM' => ['memory' => '16Gi', 'memory_gib' => 16, 'solver_limit_seconds' => 300],
        ];
        $configuration = $approvedConfigurations[$target['configuration_id']] ?? null;
        $validDigest = preg_match('/^sha256:[a-f0-9]{64}$/', $target['image_digest']) === 1;
        $validTarget = is_array($configuration)
            && $target['revision'] !== ''
            && $validDigest
            && $target['cpu'] === 8
            && $target['memory'] === $configuration['memory']
            && $target['memory_gib'] === $configuration['memory_gib']
            && $target['concurrency'] === 1
            && $target['request_timeout_seconds'] === 360
            && (int) config('tala_integrations.scheduling_solver.timeout_seconds') === 360
            && $target['solver_limit_seconds'] === $configuration['solver_limit_seconds']
            && $target['worker_count'] === 8
            && $target['random_seed'] === 20260718
            && $target['min_instances'] === 0
            && $target['max_instances'] === 2;

        if (! $validTarget) {
            throw new RuntimeException('The configured revision is outside the approved TAL-96D5D boundary.');
        }

        return $target;
    }

    /**
     * Guard the local parity-evidence path without requiring Cloud credentials.
     *
     * @return array<string, mixed>
     */
    public function assertLocalReplaySafe(string $scenario): array
    {
        $scenario = $this->scenarioCatalog->normalize($scenario);
        $manifest = $this->scenarioCatalog->manifest($scenario);

        if (! app()->environment('testing')) {
            throw new RuntimeException('TAL-96D5D is restricted to APP_ENV=testing.');
        }

        if (DB::connection()->getDriverName() !== 'mysql'
            || DB::connection()->getDatabaseName() !== 'test_tala_db') {
            throw new RuntimeException('TAL-96D5D requires MySQL test_tala_db.');
        }

        $this->assertExactScenario($manifest);
        $this->assertNoOfficialSchedulingWrites();

        return $manifest;
    }

    /** @param array<string, mixed> $manifest */
    private function assertExactScenario(array $manifest): void
    {
        $expected = $manifest['counts'];
        $scenarioCohorts = $this->scenarioCatalog->cohorts((string) $manifest['scenario']);
        $actual = [
            'students' => DB::table('student_profiles')->count(),
            'cohorts' => collect($scenarioCohorts)
                ->filter(fn (array $cohort, string $cohortCode): bool => StudentProfile::query()
                    ->where('student_number', 'like', $cohortCode.'-%')
                    ->exists())
                ->count(),
            'faculty' => User::query()->role('faculty')->count(),
            'offerings' => DB::table('term_offerings')->count(),
            'sections' => DB::table('sections')->count(),
            'scheduling_demands' => SchedulingDemand::query()->count(),
        ];

        if ($actual !== $expected) {
            throw new RuntimeException(
                'test_tala_db does not match the exact '.$manifest['scenario'].' scheduling scenario manifest.',
            );
        }
    }

    private function assertNoOfficialSchedulingWrites(): void
    {
        $counts = [
            ScheduleGenerationRun::query()->count(),
            CandidateScheduleRow::query()->count(),
            SectionMeeting::query()->count(),
            DB::table('jobs')->count(),
        ];

        if ($counts !== [0, 0, 0, 0]) {
            throw new RuntimeException(
                'TAL-96D5D requires no schedule runs, candidate rows, official meetings, or queued jobs before capture.',
            );
        }
    }
}
