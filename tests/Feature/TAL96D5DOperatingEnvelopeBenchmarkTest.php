<?php

namespace Tests\Feature;

use App\Actions\Integrations\SchedulingSolver\LocalStubSchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverClient;
use App\Actions\Scheduling\ScheduleAssignmentValidationService;
use App\Actions\Scheduling\SchedulingOperatingEnvelopeCostEstimator;
use App\Actions\Scheduling\SchedulingOperatingEnvelopeEnvironmentGuard;
use App\Actions\Scheduling\SchedulingOperatingEnvelopeSnapshotCapture;
use App\Actions\SystemAdministration\SchedulingAcceptanceScenarioCatalog;
use App\Models\CandidateScheduleRow;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\SectionMeeting;
use Database\Seeders\ClientAlignedAcceptanceBaselineSeeder;
use Database\Seeders\SchedulingAcceptanceScenarioSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class TAL96D5DOperatingEnvelopeBenchmarkTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertSame(Command::SUCCESS, Artisan::call('acceptance:seed-scheduling-scenario', [
            'scenario' => SchedulingAcceptanceScenarioCatalog::Middle,
        ]), Artisan::output());
        $this->assertSame(270, DB::table('student_profiles')->count());
        $this->assertSame(77, SchedulingDemand::query()->count());
        $this->assertSame(0, ScheduleGenerationRun::query()->count());
        $this->assertSame(0, CandidateScheduleRow::query()->count());
        $this->assertSame(0, SectionMeeting::query()->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_guard_accepts_only_the_approved_final_configuration(): void
    {
        $this->configureOperatingEnvelope();

        $target = app(SchedulingOperatingEnvelopeEnvironmentGuard::class)->assertSafe('MIDDLE');

        $this->assertSame('FINAL-CFG-02-MEM', $target['configuration_id']);
        $this->assertSame('16Gi', $target['memory']);
        $this->assertSame(16, $target['memory_gib']);
        $this->assertSame(300, $target['solver_limit_seconds']);
        $this->assertSame(8, $target['cpu']);
        $this->assertSame(8, $target['worker_count']);
        $this->assertSame(1, $target['concurrency']);
        $this->assertSame(360, $target['request_timeout_seconds']);
        $this->assertSame(0, $target['min_instances']);
        $this->assertSame(2, $target['max_instances']);

        $this->configureOperatingEnvelope(['concurrency' => 2]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('approved TAL-96D5D boundary');

        app(SchedulingOperatingEnvelopeEnvironmentGuard::class)->assertSafe('MIDDLE');
    }

    public function test_guard_rejects_the_retired_eight_gib_final_configuration(): void
    {
        $this->configureOperatingEnvelope([
            'configuration_id' => 'FINAL-CFG-01',
            'memory' => '8Gi',
            'memory_gib' => 8,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('approved TAL-96D5D boundary');

        app(SchedulingOperatingEnvelopeEnvironmentGuard::class)->assertSafe('MIDDLE');
    }

    public function test_local_parity_guard_does_not_require_cloud_transport_or_credentials(): void
    {
        config()->set('tala_integrations.scheduling_solver.driver', 'local_stub');
        config()->set('tala_integrations.scheduling_solver.credentials_path', '');

        $manifest = app(SchedulingOperatingEnvelopeEnvironmentGuard::class)
            ->assertLocalReplaySafe('MIDDLE');

        $this->assertSame('MIDDLE', $manifest['scenario']);
        $this->assertSame(77, data_get($manifest, 'counts.scheduling_demands'));
    }

    public function test_local_parity_command_writes_replayable_private_evidence_without_cloud_or_official_writes(): void
    {
        Storage::fake('local');
        config()->set('tala_integrations.scheduling_solver.driver', 'local_stub');
        config()->set('tala_integrations.scheduling_solver.credentials_path', '');
        $before = $this->officialRecordCounts();

        $exitCode = Artisan::call('scheduling:capture-parity-evidence', [
            'scenario' => 'MIDDLE',
            '--output' => 'benchmarks/tal96d5d-parity-middle-test.json',
            '--no-interaction' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode, Artisan::output());
        $this->assertTrue(Storage::disk('local')->exists('benchmarks/tal96d5d-parity-middle-test.json'));
        $artifactJson = Storage::disk('local')->get('benchmarks/tal96d5d-parity-middle-test.json');
        $artifact = json_decode($artifactJson, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('tal96d5d-parity-v2', $artifact['evidence_version']);
        $this->assertSame('MIDDLE', $artifact['scenario']);
        $this->assertTrue(data_get($artifact, 'laravel_validation.passes'));
        $this->assertSame(77, data_get($artifact, 'laravel_validation.assignment_count'));
        $this->assertCount(77, $artifact['assignments']);
        $this->assertSame(
            ['ok'],
            array_values(array_unique(array_column($artifact['assignments'], 'assignment_status'))),
        );

        $capture = app(SchedulingOperatingEnvelopeSnapshotCapture::class)->capture('MIDDLE');
        $storedValidation = app(ScheduleAssignmentValidationService::class)->validateCandidateAssignments(
            $capture['run'],
            $artifact['snapshot'],
            $artifact['assignments'],
        );

        $this->assertTrue($storedValidation->passes());
        $this->assertSame($before, $this->officialRecordCounts());
        $this->assertStringNotContainsString('@example.test', $artifactJson);
        $this->assertStringNotContainsString('credentials', mb_strtolower($artifactJson));
    }

    public function test_guard_rejects_transport_timeout_that_does_not_match_the_final_target(): void
    {
        $this->configureOperatingEnvelope();
        config()->set('tala_integrations.scheduling_solver.timeout_seconds', 300);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('approved TAL-96D5D boundary');

        app(SchedulingOperatingEnvelopeEnvironmentGuard::class)->assertSafe('MIDDLE');
    }

    public function test_guard_rejects_the_quota_incompatible_three_instance_ceiling(): void
    {
        $this->configureOperatingEnvelope(['max_instances' => 3]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('approved TAL-96D5D boundary');

        app(SchedulingOperatingEnvelopeEnvironmentGuard::class)->assertSafe('MIDDLE');
    }

    public function test_command_writes_sanitized_exact_scenario_evidence_without_official_writes(): void
    {
        Storage::fake('local');
        $this->configureOperatingEnvelope();
        $this->app->instance(SchedulingSolverClient::class, new class implements SchedulingSolverClient
        {
            public function solve(array $snapshot): array
            {
                $result = (new LocalStubSchedulingSolverClient)->solve($snapshot);
                $result['solver_statistics']['worker_count'] = 8;

                return $result;
            }

            public function probe(): array
            {
                return ['status' => 200, 'body' => 'ok'];
            }
        });

        $before = $this->officialRecordCounts();
        $exitCode = Artisan::call('scheduling:benchmark-operating-envelope', [
            'scenario' => 'MIDDLE',
            '--repetitions' => 1,
            '--output' => 'benchmarks/tal96d5d-middle-test.json',
            '--no-interaction' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode, Artisan::output());
        $this->assertTrue(Storage::disk('local')->exists('benchmarks/tal96d5d-middle-test.json'));

        $reportJson = Storage::disk('local')->get('benchmarks/tal96d5d-middle-test.json');
        $report = json_decode($reportJson, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('tal96d5d-v3', $report['benchmark_version']);
        $this->assertSame('tal94-demand-v2', $report['contract_version']);
        $this->assertSame('MIDDLE', $report['scenario']);
        $this->assertSame(270, data_get($report, 'manifest.counts.students'));
        $this->assertSame(77, data_get($report, 'manifest.counts.scheduling_demands'));
        $this->assertSame(77, data_get($report, 'composition.demands'));
        $this->assertSame('FINAL-CFG-02-MEM', data_get($report, 'target.configuration_id'));
        $this->assertSame(16, data_get($report, 'target.memory_gib'));
        $this->assertSame('solver.example.test', data_get($report, 'target.url_host'));
        $this->assertSame('client_elapsed_proxy', data_get($report, 'cost_assumptions.measurement_basis'));
        $this->assertSame(1, data_get($report, 'summary.attempted_run_count'));
        $this->assertSame(0, data_get($report, 'summary.accepted_run_count'));
        $this->assertEquals(0.0, data_get($report, 'summary.accepted_run_rate_percent'));
        $this->assertGreaterThan(0.0, data_get($report, 'probe.cost.gross_request_cost_usd'));
        $this->assertSame(
            data_get($report, 'probe.cost.gross_request_cost_usd'),
            data_get($report, 'summary.gross_probe_cost_usd'),
        );
        $this->assertSame(
            data_get($report, 'runs.0.cost.gross_request_cost_usd'),
            data_get($report, 'summary.gross_solver_request_cost_usd'),
        );
        $this->assertEqualsWithDelta(
            data_get($report, 'summary.gross_probe_cost_usd')
                + data_get($report, 'summary.gross_solver_request_cost_usd'),
            data_get($report, 'summary.gross_experiment_cost_usd'),
            0.0000000001,
        );
        $this->assertSame('infeasible', data_get($report, 'runs.0.result_classification'));
        $this->assertFalse(data_get($report, 'runs.0.operationally_valid'));
        $this->assertFalse(data_get($report, 'runs.0.meets_strict_study_acceptance'));
        $this->assertFalse(data_get($report, 'runs.0.laravel_hard_constraints_pass'));
        $this->assertTrue(data_get($report, 'runs.0.telemetry_complete'));
        $this->assertSame('none', data_get($report, 'runs.0.solver_statistics.result_source'));
        $this->assertSame('infeasible', data_get($report, 'runs.0.solver_statistics.search_stages.feasibility.status'));
        $this->assertSame('not_run', data_get($report, 'runs.0.solver_statistics.search_stages.optimization.status'));
        $this->assertEquals(0.0, data_get($report, 'runs.0.solver_statistics.search_stages.feasibility.wall_time_seconds'));
        $this->assertEquals(0.0, data_get($report, 'runs.0.solver_statistics.search_stages.optimization.wall_time_seconds'));
        $this->assertSame(0, data_get($report, 'runs.0.assignment_evidence.assignment_count'));
        $this->assertIsArray(data_get($report, 'runs.0.assignment_evidence.section_timetables'));
        $this->assertIsArray(data_get($report, 'scenario_feasibility_audit.room_type_capacity'));
        $this->assertIsString($report['snapshot_sha256']);
        $this->assertSame(64, mb_strlen($report['snapshot_sha256']));
        $this->assertSame($before, $this->officialRecordCounts());
        $this->assertStringNotContainsString('credentials', mb_strtolower($reportJson));
        $this->assertStringContainsString('assignment_evidence', mb_strtolower($reportJson));
        $this->assertArrayNotHasKey('scheduling_demands', $report);
        $this->assertStringNotContainsString('registrar.demo@example.test', $reportJson);
    }

    public function test_command_rejects_more_than_the_per_invocation_request_cap(): void
    {
        $this->configureOperatingEnvelope();

        $exitCode = Artisan::call('scheduling:benchmark-operating-envelope', [
            'scenario' => 'MIDDLE',
            '--repetitions' => 4,
            '--no-interaction' => true,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('between 1 and 3', Artisan::output());
    }

    public function test_unknown_result_does_not_create_false_timetable_projection(): void
    {
        Storage::fake('local');
        $this->configureOperatingEnvelope();
        $this->app->instance(SchedulingSolverClient::class, new class implements SchedulingSolverClient
        {
            public function solve(array $snapshot): array
            {
                $result = (new LocalStubSchedulingSolverClient)->solve($snapshot);
                $demandCount = count($snapshot['scheduling_demands'] ?? []);
                $result['solver_status'] = 'unknown';
                $result['assignments'] = [];
                $result['hard_constraint_violations'] = [];
                $result['hard_violation_count'] = 0;
                $result['soft_constraint_scores'] = [
                    'assigned_count' => 0,
                    'conflict_count' => 0,
                ];
                $result['infeasible_reasons'] = [];
                $result['warnings'] = [[
                    'type' => 'search_limit',
                    'message' => 'The feasibility search ended before a complete timetable was found or disproved.',
                ]];
                $result['runtime_seconds'] = 300.0;
                $result['objective_score'] = null;
                $result['solver_statistics']['worker_count'] = 8;
                $result['solver_statistics']['best_objective_bound'] = null;
                $result['solver_statistics']['relative_optimality_gap'] = null;
                $result['solver_statistics']['result_source'] = 'none';
                $result['solver_statistics']['search_stages']['feasibility']['status'] = 'unknown';
                $result['solver_statistics']['search_stages']['optimization'] = [
                    'status' => 'not_run',
                    'model_variable_count' => 0,
                    'model_constraint_count' => 0,
                    'no_overlap_constraint_count' => 0,
                    'boolean_variable_count' => null,
                    'branch_count' => null,
                    'conflict_count' => null,
                    'deterministic_time_seconds' => null,
                    'wall_time_seconds' => 0.0,
                ];
                $result['assigned_count'] = 0;
                $result['unassigned_count'] = $demandCount;
                $result['warning_count'] = 0;
                $result['timeout'] = true;

                return $result;
            }

            public function probe(): array
            {
                return ['status' => 200, 'body' => 'ok'];
            }
        });

        $exitCode = Artisan::call('scheduling:benchmark-operating-envelope', [
            'scenario' => 'MIDDLE',
            '--repetitions' => 1,
            '--output' => 'benchmarks/tal96d5d-unknown-test.json',
            '--no-interaction' => true,
        ]);
        $report = json_decode(
            Storage::disk('local')->get('benchmarks/tal96d5d-unknown-test.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(Command::SUCCESS, $exitCode, Artisan::output());
        $this->assertSame('unknown_timed_out', data_get($report, 'runs.0.result_classification'));
        $this->assertSame(0, data_get($report, 'runs.0.assigned_count'));
        $this->assertSame(77, data_get($report, 'runs.0.unassigned_count'));
        $this->assertSame(0, data_get($report, 'runs.0.assignment_evidence.assignment_count'));
        $this->assertSame([], data_get($report, 'runs.0.assignment_evidence.section_timetables'));
        $this->assertSame([], data_get($report, 'runs.0.assignment_evidence.faculty_timetables'));
        $this->assertSame([], data_get($report, 'runs.0.assignment_evidence.room_timetables'));
    }

    public function test_exact_middle_capture_is_deterministic_and_non_persistent(): void
    {
        $before = $this->officialRecordCounts();
        $capture = app(SchedulingOperatingEnvelopeSnapshotCapture::class);
        $first = $capture->capture('MIDDLE');
        $second = $capture->capture('MIDDLE');

        $this->assertSame('MIDDLE', $first['manifest']['scenario']);
        $this->assertSame(77, $first['composition']['demands']);
        $this->assertSame($first['composition'], $second['composition']);
        $this->assertSame($first['snapshot_sha256'], $second['snapshot_sha256']);
        $this->assertSame($before, $this->officialRecordCounts());
    }

    public function test_normalized_hash_ignores_volatile_capture_metadata_but_not_solver_inputs(): void
    {
        $capture = app(SchedulingOperatingEnvelopeSnapshotCapture::class);
        $snapshot = [
            'contract_version' => 'tal94-demand-v2',
            'captured_at' => '2026-07-27T19:46:38+08:00',
            'run_metadata' => [
                'solver_run_id' => 101,
                'requested_by' => 7,
            ],
            'term' => [
                'term_id' => 9,
                'scheduling_slot_minutes' => 30,
            ],
        ];
        $sameSolverInputs = $snapshot;
        $sameSolverInputs['captured_at'] = '2026-07-27T19:53:25+08:00';
        $sameSolverInputs['run_metadata']['solver_run_id'] = 102;
        $changedSolverInputs = $sameSolverInputs;
        $changedSolverInputs['term']['scheduling_slot_minutes'] = 60;

        $this->assertSame(
            $capture->normalizedHash($snapshot),
            $capture->normalizedHash($sameSolverInputs),
        );
        $this->assertNotSame(
            $capture->normalizedHash($snapshot),
            $capture->normalizedHash($changedSolverInputs),
        );
    }

    public function test_cost_estimator_uses_the_disclosed_request_based_proxy(): void
    {
        $estimator = app(SchedulingOperatingEnvelopeCostEstimator::class);
        $assumptions = $estimator->assumptions();
        $estimate = $estimator->estimate(
            elapsedMilliseconds: 1_001.0,
            cpu: 8,
            memoryGib: 16,
        );
        $fullBudgetEstimate = $estimator->estimate(
            elapsedMilliseconds: 300_000.0,
            cpu: 8,
            memoryGib: 16,
        );

        $this->assertSame('request_based', $assumptions['billing_mode']);
        $this->assertSame('085C-A237-027A', $assumptions['cpu_sku_id']);
        $this->assertSame('600C-3782-6708', $assumptions['memory_sku_id']);
        $this->assertSame('2DA5-55D3-E679', $assumptions['request_sku_id']);
        $this->assertSame('https://cloud.google.com/skus/sku-groups/cloud-run', $assumptions['sku_source_url']);
        $this->assertSame(0.000011244, $assumptions['cpu_per_vcpu_second_usd']);
        $this->assertSame(0.000001235, $assumptions['memory_per_gib_second_usd']);
        $this->assertSame(0.40, $assumptions['request_per_million_usd']);
        $this->assertSame(1.1, $estimate['billable_seconds_proxy']);
        $this->assertEqualsWithDelta(0.0001206832, $estimate['gross_compute_usd'], 0.000000001);
        $this->assertEqualsWithDelta(0.0000004, $estimate['request_usd'], 0.000000001);
        $this->assertEqualsWithDelta(0.0001210832, $estimate['gross_request_cost_usd'], 0.000000001);
        $this->assertSame('client_elapsed_proxy', $estimate['measurement_basis']);
        $this->assertEqualsWithDelta(0.032914, $fullBudgetEstimate['gross_request_cost_usd'], 0.000000001);
    }

    public function test_middle_fixture_state_verifies_only_the_representative_login_passwords(): void
    {
        Hash::shouldReceive('check')
            ->times(7)
            ->with('password', \Mockery::type('string'))
            ->andReturnTrue();

        $state = app(SchedulingAcceptanceScenarioSeeder::class)
            ->forScenario('MIDDLE')
            ->state();

        $this->assertSame(ClientAlignedAcceptanceBaselineSeeder::StateComplete, $state);
    }

    /** @param array<string, int|string> $overrides */
    private function configureOperatingEnvelope(array $overrides = []): void
    {
        config()->set([
            'queue.default' => 'database',
            'tala_integrations.scheduling_solver.driver' => 'cloud_run',
            'tala_integrations.scheduling_solver.url' => 'https://solver.example.test',
            'tala_integrations.scheduling_solver.audience' => 'https://solver.example.test',
            'tala_integrations.scheduling_solver.credentials_path' => __FILE__,
            'tala_integrations.scheduling_solver.timeout_seconds' => 360,
            'tala_integrations.scheduling_solver.connect_timeout_seconds' => 10,
            'tala_integrations.scheduling_solver.operating_envelope' => [
                ...$this->operatingEnvelopeMetadata(),
                ...$overrides,
            ],
        ]);
    }

    /** @return array<string, int|string|array<string, float|int|string>> */
    private function operatingEnvelopeMetadata(): array
    {
        return [
            'revision' => 'tala-scheduler-solver-d5d-target-test',
            'image_digest' => 'sha256:'.str_repeat('a', 64),
            'configuration_id' => 'FINAL-CFG-02-MEM',
            'cpu' => 8,
            'memory' => '16Gi',
            'memory_gib' => 16,
            'concurrency' => 1,
            'request_timeout_seconds' => 360,
            'solver_limit_seconds' => 300,
            'worker_count' => 8,
            'random_seed' => 20260718,
            'min_instances' => 0,
            'max_instances' => 2,
            'pricing' => [
                'region' => 'asia-southeast1',
                'effective_date' => '2026-07-27',
                'billing_mode' => 'request_based',
                'cpu_sku_id' => '085C-A237-027A',
                'memory_sku_id' => '600C-3782-6708',
                'request_sku_id' => '2DA5-55D3-E679',
                'source_url' => 'https://cloud.google.com/run/pricing',
                'sku_source_url' => 'https://cloud.google.com/skus/sku-groups/cloud-run',
                'cpu_per_vcpu_second_usd' => 0.000011244,
                'memory_per_gib_second_usd' => 0.000001235,
                'request_per_million_usd' => 0.40,
                'billing_quantum_ms' => 100,
            ],
        ];
    }

    /** @return array<string, int> */
    private function officialRecordCounts(): array
    {
        return [
            'runs' => ScheduleGenerationRun::query()->count(),
            'candidates' => CandidateScheduleRow::query()->count(),
            'meetings' => SectionMeeting::query()->count(),
            'jobs' => DB::table('jobs')->count(),
        ];
    }
}
