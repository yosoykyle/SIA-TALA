<?php

namespace Tests\Feature;

use App\Actions\Integrations\SchedulingSolver\LocalStubSchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverTransportException;
use App\Actions\Scheduling\SchedulingBenchmarkDatasetFactory;
use App\Actions\Scheduling\SchedulingBenchmarkEnvironmentGuard;
use App\Actions\Scheduling\SchedulingBenchmarkSnapshotCapture;
use App\Models\CandidateScheduleRow;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\SectionMeeting;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class TAL96B3SchedulingBenchmarkTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotContains(DB::connection()->getDatabaseName(), ['tala_db', 'tala_test_codex']);

        $exitCode = Artisan::call('acceptance:seed-client-baseline');
        $this->assertSame(Command::SUCCESS, $exitCode, Artisan::output());
        $this->assertSame(54, SchedulingDemand::query()->count());
        $this->assertSame(0, ScheduleGenerationRun::query()->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_factory_builds_the_disclosed_deterministic_tiers_without_persisting_runs(): void
    {
        $capture = app(SchedulingBenchmarkSnapshotCapture::class)->capture();
        $factory = app(SchedulingBenchmarkDatasetFactory::class);

        $this->assertSame(0, ScheduleGenerationRun::query()->count());
        $this->assertSame(0, DB::table('jobs')->count());

        $expectedCompositions = [
            'reduced' => ['demands' => 27, 'faculty' => 6, 'rooms' => 3, 'time_slots' => 156],
            'representative' => ['demands' => 54, 'faculty' => 12, 'rooms' => 6, 'time_slots' => 156],
            'proportional-2x' => ['demands' => 108, 'faculty' => 24, 'rooms' => 12, 'time_slots' => 156],
            'contention-2x' => ['demands' => 108, 'faculty' => 12, 'rooms' => 6, 'time_slots' => 156],
            'proportional-4x' => ['demands' => 216, 'faculty' => 48, 'rooms' => 24, 'time_slots' => 156],
        ];

        foreach ($expectedCompositions as $tier => $expectedComposition) {
            $first = $factory->make($capture['snapshot'], $tier);
            $second = $factory->make($capture['snapshot'], $tier);

            $this->assertSame('tal94-demand-v2', $first['contract_version']);
            $this->assertSame('balanced_v1', data_get($first, 'constraint_profile.key'));
            $this->assertSame($expectedComposition, $factory->composition($first));
            $this->assertSame($factory->normalizedHash($first), $factory->normalizedHash($second));
            $this->assertCount(
                $expectedComposition['demands'],
                collect($first['scheduling_demands'])->pluck('scheduling_demand_id')->unique(),
            );
            $this->assertTierReferencesAreInternal($first);
        }

        $reduced = $factory->make($capture['snapshot'], 'reduced');
        $this->assertSame(
            ['COMPUTER_LABORATORY', 'LABORATORY', 'LECTURE_ROOM'],
            collect($reduced['rooms'])->pluck('room_type')->sort()->values()->all(),
        );
        $representativeDemands = collect($capture['snapshot']['scheduling_demands'])
            ->keyBy('scheduling_demand_id');

        foreach ($reduced['scheduling_demands'] as $demand) {
            $source = $representativeDemands->get($demand['scheduling_demand_id']);

            $this->assertIsArray($source);
            $this->assertSame($source['eligible_faculty_user_ids'], $demand['eligible_faculty_user_ids']);
            $this->assertSame($source['faculty_load_options'], $demand['faculty_load_options']);
            $this->assertSame($source['fixed_faculty_user_id'], $demand['fixed_faculty_user_id']);
        }

        $reducedFacultyIds = collect($reduced['faculty'])->pluck('faculty_id');
        $this->assertSame(
            collect($capture['snapshot']['faculty'])
                ->filter(fn (array $faculty): bool => $reducedFacultyIds->contains($faculty['faculty_id']))
                ->values()
                ->all(),
            $reduced['faculty'],
        );

        $this->assertSame('Reduced technical tier', $factory->definition('reduced')['label']);
        $this->assertFalse($factory->definition('reduced')['institutional_minimum']);
        $this->assertSame(47, $factory->definition('representative')['client_student_population']);
        $this->assertSame(6, $factory->definition('representative')['client_cohort_count']);

        $contention = $factory->make($capture['snapshot'], 'contention-2x');
        $this->assertSame(
            ['42.00'],
            collect($contention['faculty'])->pluck('max_allowed_units')->unique()->values()->all(),
        );
    }

    public function test_guard_rejects_any_runtime_outside_the_private_test_cloud_run_boundary(): void
    {
        config()->set('tala_integrations.scheduling_solver.driver', 'local_stub');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cloud_run');

        app(SchedulingBenchmarkEnvironmentGuard::class)->assertSafe();
    }

    public function test_guard_accepts_only_the_three_approved_resource_profiles(): void
    {
        $profiles = [
            'A' => ['cpu' => '1', 'memory' => '2Gi', 'worker_count' => 1],
            'B' => ['cpu' => '2', 'memory' => '4Gi', 'worker_count' => 2],
            'C' => ['cpu' => '4', 'memory' => '8Gi', 'worker_count' => 4],
        ];

        foreach ($profiles as $profile => $resources) {
            $this->configureCloudRunBenchmark($this->benchmarkMetadata([
                'profile' => $profile,
                ...$resources,
            ]));

            $target = app(SchedulingBenchmarkEnvironmentGuard::class)->assertSafe();

            $this->assertSame($profile, $target['profile']);
            $this->assertSame($resources['cpu'], $target['cpu']);
            $this->assertSame($resources['memory'], $target['memory']);
            $this->assertSame($resources['worker_count'], $target['worker_count']);
            $this->assertSame(0, $target['min_instances']);
            $this->assertSame(3, $target['max_instances']);
        }

        foreach ([30, 120, 240] as $solverLimitSeconds) {
            $this->configureCloudRunBenchmark($this->benchmarkMetadata([
                'solver_limit_seconds' => $solverLimitSeconds,
            ]));

            $this->assertSame(
                $solverLimitSeconds,
                app(SchedulingBenchmarkEnvironmentGuard::class)->assertSafe()['solver_limit_seconds'],
            );
        }

        $this->configureCloudRunBenchmark($this->benchmarkMetadata([
            'profile' => 'B',
            'cpu' => '1',
            'memory' => '4Gi',
            'worker_count' => 2,
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('approved TAL-96B3 boundary');

        app(SchedulingBenchmarkEnvironmentGuard::class)->assertSafe();
    }

    public function test_command_writes_only_sanitized_evidence_and_leaves_official_records_unchanged(): void
    {
        Storage::fake('local');
        config()->set([
            'queue.default' => 'database',
            'tala_integrations.scheduling_solver.driver' => 'cloud_run',
            'tala_integrations.scheduling_solver.url' => 'https://tagged-solver.example.test',
            'tala_integrations.scheduling_solver.audience' => 'https://solver.example.test',
            'tala_integrations.scheduling_solver.credentials_path' => __FILE__,
            'tala_integrations.scheduling_solver.timeout_seconds' => 300,
            'tala_integrations.scheduling_solver.connect_timeout_seconds' => 10,
            'tala_integrations.scheduling_solver.benchmark' => $this->benchmarkMetadata(),
        ]);
        $this->app->instance(SchedulingSolverClient::class, new LocalStubSchedulingSolverClient);

        $before = $this->officialRecordCounts();
        $exitCode = Artisan::call('scheduling:benchmark-cloud-run', [
            '--tiers' => 'representative',
            '--repetitions' => 10,
            '--output' => 'benchmarks/tal96b3-test.json',
            '--no-interaction' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exitCode, $output);
        $this->assertStringContainsString('TAL-96B3 benchmark evidence ready.', $output);
        $this->assertTrue(Storage::disk('local')->exists('benchmarks/tal96b3-test.json'));

        $reportJson = Storage::disk('local')->get('benchmarks/tal96b3-test.json');
        $report = json_decode($reportJson, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('tal96b3-v2', $report['benchmark_version']);
        $this->assertSame('tal94-demand-v2', $report['contract_version']);
        $this->assertSame('balanced_v1', $report['constraint_profile']);
        $this->assertSame('cloud_run', data_get($report, 'target.driver'));
        $this->assertSame('tagged-solver.example.test', data_get($report, 'target.url_host'));
        $this->assertSame('tala-scheduler-solver-b3-test', data_get($report, 'target.revision'));
        $this->assertSame('A', data_get($report, 'target.profile'));
        $this->assertSame('2Gi', data_get($report, 'target.memory'));
        $this->assertSame(10, data_get($report, 'tiers.representative.summary.accepted_run_count'));
        $this->assertSame(100.0, data_get($report, 'tiers.representative.summary.accepted_run_rate_percent'));
        $this->assertSame('representative', $report['largest_attempted_tested_tier']);
        $this->assertSame('Client-representative tier', data_get($report, 'tiers.representative.definition.label'));
        $this->assertSame(47, data_get($report, 'tiers.representative.definition.client_student_population'));
        $this->assertIsFloat(data_get($report, 'tiers.representative.summary.runtime_seconds.p95'));
        $this->assertIsArray(data_get($report, 'tiers.representative.summary.relative_optimality_gap'));
        $this->assertIsArray(data_get($report, 'tiers.representative.summary.relative_percentage_deviation'));
        $this->assertSame($before, $this->officialRecordCounts());
        $this->assertStringNotContainsString('example.test', str_replace('tagged-solver.example.test', '', $reportJson));
        $this->assertStringNotContainsString('credentials', mb_strtolower($reportJson));
        $this->assertStringNotContainsString('assignments', mb_strtolower($reportJson));
        $this->assertStringNotContainsString('scheduling_demands', mb_strtolower($reportJson));
    }

    public function test_command_requires_the_representative_gate(): void
    {
        config()->set([
            'tala_integrations.scheduling_solver.driver' => 'cloud_run',
            'tala_integrations.scheduling_solver.url' => 'https://tagged-solver.example.test',
            'tala_integrations.scheduling_solver.audience' => 'https://solver.example.test',
            'tala_integrations.scheduling_solver.credentials_path' => __FILE__,
            'tala_integrations.scheduling_solver.benchmark' => $this->benchmarkMetadata(),
        ]);

        $exitCode = Artisan::call('scheduling:benchmark-cloud-run', [
            '--tiers' => 'reduced',
            '--repetitions' => 1,
            '--no-interaction' => true,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('representative', Artisan::output());
    }

    public function test_command_retains_sanitized_evidence_when_the_health_probe_is_rejected(): void
    {
        Storage::fake('local');
        $this->configureCloudRunBenchmark();
        $this->app->instance(SchedulingSolverClient::class, new class implements SchedulingSolverClient
        {
            public function solve(array $snapshot): array
            {
                throw new RuntimeException('The solve method must not run after a failed health probe.');
            }

            public function probe(): array
            {
                return ['status' => 401, 'body' => 'unauthorized'];
            }
        });

        $exitCode = Artisan::call('scheduling:benchmark-cloud-run', [
            '--tiers' => 'representative',
            '--repetitions' => 1,
            '--output' => 'benchmarks/tal96b3-health-failure.json',
            '--no-interaction' => true,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertTrue(Storage::disk('local')->exists('benchmarks/tal96b3-health-failure.json'));
        $report = json_decode(
            Storage::disk('local')->get('benchmarks/tal96b3-health-failure.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame('health_probe_failed', $report['stop_reason']);
        $this->assertSame(401, data_get($report, 'health.status'));
        $this->assertSame([], $report['tiers']);
    }

    public function test_command_fails_closed_when_repeated_contention_outcomes_are_inconsistent(): void
    {
        Storage::fake('local');
        $this->configureCloudRunBenchmark();
        $this->app->instance(SchedulingSolverClient::class, new class(new LocalStubSchedulingSolverClient) implements SchedulingSolverClient
        {
            private int $contentionRun = 0;

            public function __construct(private readonly LocalStubSchedulingSolverClient $delegate) {}

            public function solve(array $snapshot): array
            {
                $result = $this->delegate->solve($snapshot);

                if (count($snapshot['scheduling_demands'] ?? []) === 108
                    && count($snapshot['faculty'] ?? []) === 12
                    && ++$this->contentionRun === 2) {
                    $result['solver_status'] = 'unknown';
                }

                return $result;
            }

            public function probe(): array
            {
                return $this->delegate->probe();
            }
        });

        $exitCode = Artisan::call('scheduling:benchmark-cloud-run', [
            '--tiers' => 'representative,contention-2x',
            '--repetitions' => 3,
            '--output' => 'benchmarks/tal96b3-contention-inconsistent.json',
            '--no-interaction' => true,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $report = json_decode(
            Storage::disk('local')->get('benchmarks/tal96b3-contention-inconsistent.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame('failed', $report['overall_status']);
        $this->assertSame('contention_result_inconsistent', $report['stop_reason']);
    }

    public function test_command_rejects_missing_or_mismatched_solver_telemetry(): void
    {
        Storage::fake('local');
        $this->configureCloudRunBenchmark();
        $this->app->instance(SchedulingSolverClient::class, new class(new LocalStubSchedulingSolverClient) implements SchedulingSolverClient
        {
            public function __construct(private readonly LocalStubSchedulingSolverClient $delegate) {}

            public function solve(array $snapshot): array
            {
                $result = $this->delegate->solve($snapshot);
                unset($result['solver_statistics']['candidate_count']);

                return $result;
            }

            public function probe(): array
            {
                return $this->delegate->probe();
            }
        });

        $exitCode = Artisan::call('scheduling:benchmark-cloud-run', [
            '--tiers' => 'representative',
            '--repetitions' => 1,
            '--output' => 'benchmarks/tal96b3-incomplete-telemetry.json',
            '--no-interaction' => true,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $report = json_decode(
            Storage::disk('local')->get('benchmarks/tal96b3-incomplete-telemetry.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $run = data_get($report, 'tiers.representative.runs.0');

        $this->assertSame('representative_gate_failed', $report['stop_reason']);
        $this->assertFalse($run['telemetry_complete']);
        $this->assertSame('compute_boundary', $run['failure_classification']);
        $this->assertSame([], $run['solver_statistics']);
    }

    public function test_command_accepts_approved_parallel_worker_telemetry(): void
    {
        Storage::fake('local');
        $profiles = [
            'B' => ['cpu' => '2', 'memory' => '4Gi', 'worker_count' => 2],
            'C' => ['cpu' => '4', 'memory' => '8Gi', 'worker_count' => 4],
        ];

        foreach ($profiles as $profile => $resources) {
            $this->configureCloudRunBenchmark($this->benchmarkMetadata([
                'profile' => $profile,
                ...$resources,
            ]));
            $this->app->instance(SchedulingSolverClient::class, new class($resources['worker_count'], new LocalStubSchedulingSolverClient) implements SchedulingSolverClient
            {
                public function __construct(
                    private readonly int $workerCount,
                    private readonly LocalStubSchedulingSolverClient $delegate,
                ) {}

                public function solve(array $snapshot): array
                {
                    $result = $this->delegate->solve($snapshot);
                    $result['solver_statistics']['worker_count'] = $this->workerCount;

                    return $result;
                }

                public function probe(): array
                {
                    return $this->delegate->probe();
                }
            });
            $outputPath = "benchmarks/tal96b3-profile-{$profile}.json";

            $exitCode = Artisan::call('scheduling:benchmark-cloud-run', [
                '--tiers' => 'representative',
                '--repetitions' => 1,
                '--output' => $outputPath,
                '--no-interaction' => true,
            ]);
            $report = json_decode(
                Storage::disk('local')->get($outputPath),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame(Command::SUCCESS, $exitCode, Artisan::output());
            $this->assertSame($profile, data_get($report, 'target.profile'));
            $this->assertSame($resources['worker_count'], data_get($report, 'tiers.representative.runs.0.solver_statistics.worker_count'));
            $this->assertTrue(data_get($report, 'tiers.representative.runs.0.telemetry_complete'));
        }
    }

    public function test_contention_infeasibility_requires_complete_telemetry(): void
    {
        Storage::fake('local');
        $this->configureCloudRunBenchmark();
        $this->app->instance(SchedulingSolverClient::class, new class(new LocalStubSchedulingSolverClient) implements SchedulingSolverClient
        {
            public function __construct(private readonly LocalStubSchedulingSolverClient $delegate) {}

            public function solve(array $snapshot): array
            {
                $result = $this->delegate->solve($snapshot);

                if (count($snapshot['scheduling_demands'] ?? []) === 108
                    && count($snapshot['faculty'] ?? []) === 12) {
                    $result['solver_status'] = 'infeasible';
                    $result['timeout'] = false;
                    unset($result['solver_statistics']['candidate_count']);
                }

                return $result;
            }

            public function probe(): array
            {
                return $this->delegate->probe();
            }
        });

        $exitCode = Artisan::call('scheduling:benchmark-cloud-run', [
            '--tiers' => 'representative,contention-2x',
            '--repetitions' => 1,
            '--output' => 'benchmarks/tal96b3-contention-telemetry.json',
            '--no-interaction' => true,
        ]);
        $report = json_decode(
            Storage::disk('local')->get('benchmarks/tal96b3-contention-telemetry.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertSame('contention_result_inconsistent', $report['stop_reason']);
        $this->assertFalse(data_get($report, 'tiers.contention-2x.runs.0.telemetry_complete'));
        $this->assertSame(
            'compute_boundary',
            data_get($report, 'tiers.contention-2x.runs.0.failure_classification'),
        );
    }

    public function test_command_distinguishes_higher_tier_model_and_compute_boundaries(): void
    {
        Storage::fake('local');
        $this->configureCloudRunBenchmark();
        $cases = [
            'infeasible' => ['timeout' => false, 'stop_reason' => 'higher_tier_model_boundary'],
            'unknown' => ['timeout' => true, 'stop_reason' => 'higher_tier_compute_boundary'],
        ];

        foreach ($cases as $solverStatus => $expected) {
            $this->app->instance(SchedulingSolverClient::class, new class($solverStatus, $expected['timeout'], new LocalStubSchedulingSolverClient) implements SchedulingSolverClient
            {
                public function __construct(
                    private readonly string $solverStatus,
                    private readonly bool $timedOut,
                    private readonly LocalStubSchedulingSolverClient $delegate,
                ) {}

                public function solve(array $snapshot): array
                {
                    $result = $this->delegate->solve($snapshot);

                    if (count($snapshot['scheduling_demands'] ?? []) === 108
                        && count($snapshot['faculty'] ?? []) === 24) {
                        $result['solver_status'] = $this->solverStatus;
                        $result['timeout'] = $this->timedOut;
                    }

                    return $result;
                }

                public function probe(): array
                {
                    return $this->delegate->probe();
                }
            });
            $outputPath = "benchmarks/tal96b3-{$solverStatus}-boundary.json";

            $exitCode = Artisan::call('scheduling:benchmark-cloud-run', [
                '--tiers' => 'representative,proportional-2x',
                '--repetitions' => 1,
                '--output' => $outputPath,
                '--no-interaction' => true,
            ]);
            $report = json_decode(
                Storage::disk('local')->get($outputPath),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame(Command::SUCCESS, $exitCode, Artisan::output());
            $this->assertSame('accepted', $report['overall_status']);
            $this->assertSame($expected['stop_reason'], $report['stop_reason']);
            $this->assertSame('representative', $report['largest_accepted_tested_tier']);
            $this->assertSame('proportional-2x', $report['largest_attempted_tested_tier']);
        }
    }

    public function test_command_retains_sanitized_evidence_when_a_pre_representative_tier_stops_early(): void
    {
        Storage::fake('local');
        $this->configureCloudRunBenchmark();
        $this->app->instance(SchedulingSolverClient::class, new class implements SchedulingSolverClient
        {
            public function solve(array $snapshot): array
            {
                throw SchedulingSolverTransportException::retryable(
                    SchedulingSolverTransportException::ClassificationServerError,
                    'Synthetic upstream failure.',
                    500,
                );
            }

            public function probe(): array
            {
                return ['status' => 200, 'body' => 'healthy'];
            }
        });

        $exitCode = Artisan::call('scheduling:benchmark-cloud-run', [
            '--tiers' => 'reduced,representative',
            '--repetitions' => 1,
            '--output' => 'benchmarks/tal96b3-early-boundary.json',
            '--no-interaction' => true,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertTrue(Storage::disk('local')->exists('benchmarks/tal96b3-early-boundary.json'));
        $report = json_decode(
            Storage::disk('local')->get('benchmarks/tal96b3-early-boundary.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame('reduced_gate_failed', $report['stop_reason']);
        $this->assertArrayHasKey('reduced', $report['tiers']);
        $this->assertArrayNotHasKey('representative', $report['tiers']);
        $this->assertSame(
            'infrastructure_failure',
            data_get($report, 'tiers.reduced.runs.0.failure_classification'),
        );
    }

    /** @return array{runs:int,candidates:int,meetings:int,jobs:int} */
    private function officialRecordCounts(): array
    {
        return [
            'runs' => ScheduleGenerationRun::query()->count(),
            'candidates' => CandidateScheduleRow::query()->count(),
            'meetings' => SectionMeeting::query()->count(),
            'jobs' => DB::table('jobs')->count(),
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private function assertTierReferencesAreInternal(array $snapshot): void
    {
        $facultyIds = collect($snapshot['faculty'])->pluck('faculty_id')->map(fn (mixed $id): int => (int) $id);
        $roomIds = collect($snapshot['rooms'])->pluck('room_id')->map(fn (mixed $id): int => (int) $id);
        $demandIds = collect($snapshot['scheduling_demands'])->pluck('scheduling_demand_id')->map(fn (mixed $id): int => (int) $id);
        $courseIds = collect($snapshot['subjects'])->pluck('course_id')->map(fn (mixed $id): int => (int) $id);
        $sectionIds = collect($snapshot['sections'])->pluck('section_id')->map(fn (mixed $id): int => (int) $id);
        $groupIds = collect($snapshot['section_delivery_groups'])->pluck('section_delivery_group_id')->map(fn (mixed $id): int => (int) $id);
        $offeringIds = collect($snapshot['term_offerings'])->pluck('term_offering_id')->map(fn (mixed $id): int => (int) $id);

        foreach ($snapshot['scheduling_demands'] as $demand) {
            $this->assertTrue($courseIds->contains((int) $demand['course_id']));
            $this->assertTrue($sectionIds->contains((int) $demand['section_id']));
            $this->assertTrue($groupIds->contains((int) $demand['section_delivery_group_id']));
            $this->assertTrue($offeringIds->contains((int) $demand['term_offering_id']));
            $this->assertEmpty(
                collect($demand['eligible_faculty_user_ids'] ?? [])
                    ->map(fn (mixed $id): int => (int) $id)
                    ->diff($facultyIds),
            );

            if (($demand['fixed_faculty_user_id'] ?? null) !== null) {
                $this->assertTrue($facultyIds->contains((int) $demand['fixed_faculty_user_id']));
            }

            if (($demand['fixed_room_id'] ?? null) !== null) {
                $this->assertTrue($roomIds->contains((int) $demand['fixed_room_id']));
            }
        }

        foreach ($snapshot['faculty_qualifications'] as $qualification) {
            $this->assertTrue($facultyIds->contains((int) $qualification['faculty_user_id']));
            $this->assertTrue($demandIds->contains((int) $qualification['scheduling_demand_id']));
            $this->assertTrue($courseIds->contains((int) $qualification['course_id']));
        }
    }

    /**
     * @param  array<string, int|string>  $overrides
     * @return array<string, int|string>
     */
    private function benchmarkMetadata(array $overrides = []): array
    {
        return [
            'revision' => 'tala-scheduler-solver-b3-test',
            'image_digest' => 'sha256:'.str_repeat('a', 64),
            'profile' => 'A',
            'cpu' => '1',
            'memory' => '2Gi',
            'concurrency' => 1,
            'request_timeout_seconds' => 300,
            'solver_limit_seconds' => 30,
            'worker_count' => 1,
            'random_seed' => 20260718,
            'min_instances' => 0,
            'max_instances' => 3,
            ...$overrides,
        ];
    }

    /** @param array<string, int|string>|null $metadata */
    private function configureCloudRunBenchmark(?array $metadata = null): void
    {
        config()->set([
            'queue.default' => 'database',
            'tala_integrations.scheduling_solver.driver' => 'cloud_run',
            'tala_integrations.scheduling_solver.url' => 'https://tagged-solver.example.test',
            'tala_integrations.scheduling_solver.audience' => 'https://solver.example.test',
            'tala_integrations.scheduling_solver.credentials_path' => __FILE__,
            'tala_integrations.scheduling_solver.timeout_seconds' => 300,
            'tala_integrations.scheduling_solver.connect_timeout_seconds' => 10,
            'tala_integrations.scheduling_solver.benchmark' => $metadata ?? $this->benchmarkMetadata(),
        ]);
    }
}
