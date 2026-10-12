<?php

namespace Tests\Feature;

use App\Actions\Integrations\SchedulingSolver\SchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverRequest;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverResponse;
use App\Actions\Scheduling\ScheduleGenerationService;
use App\Actions\Scheduling\TermCoverageProjection;
use App\Jobs\ScheduleSolverDispatchJob;
use App\Models\CandidateScheduleRow;
use App\Models\PublishedTimetableVersion;
use App\Models\ScheduleGenerationRun;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\Issue58MultiProgramExplorationSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class Issue58MultiProgramExplorationSeederTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
    }

    public function test_hypothetical_whole_term_seed_is_additive_idempotent_and_contains_shared_source_evidence(): void
    {
        $actor = $this->registrar();
        $sentinel = Term::factory()->create();
        $sentinelBefore = $sentinel->fresh()->getRawOriginal();
        $studentCount = StudentProfile::query()->count();
        $prefix = Issue58MultiProgramExplorationSeeder::Prefix.'-TEST-'.$actor->id;
        $seeder = app(Issue58MultiProgramExplorationSeeder::class);
        $ids = $seeder->ensure($actor, $prefix);
        $term = Term::query()->findOrFail($ids['term_id']);
        $coverage = app(TermCoverageProjection::class)->forTerm($term);
        $programIds = $term->termOfferings()->with('curriculumEntry.curriculumVersion')->get()
            ->pluck('curriculumEntry.curriculumVersion.program_id')->unique()->all();
        $rows = collect($coverage['rows'])->filter(fn (array $row): bool => in_array($row['program_id'], $programIds, true));
        $this->assertCount(9, $rows);
        $this->assertSame(3, $coverage['summary']['program_count']);
        $this->assertSame(9, $coverage['summary']['section_count']);
        $this->assertSame(9, $coverage['summary']['class_count']);
        $this->assertSame(18, $coverage['summary']['meeting_count']);
        $mathSecond = $rows->first(fn (array $row): bool => str_contains($row['program_name'], 'Math') && $row['year_level'] === 'Second Year');
        $this->assertSame(2, $mathSecond['class_count']);
        $draft = ScheduleGenerationRun::query()->findOrFail($ids['draft_run_id']);
        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $draft->status);
        $this->assertCount(18, $draft->candidateRows);
        $this->assertTrue($draft->diagnostics['synthetic_review']);
        $this->assertNull($draft->runtime_ms);
        $this->assertNull($draft->quality_measures);
        $this->assertStringContainsString('No solver or optimization was run', data_get($draft->diagnostics, 'solver_result.warnings.0'));
        $version = PublishedTimetableVersion::query()->findOrFail($ids['published_version_id']);
        $this->assertCount(18, $version->meetings);
        $counts = ['terms' => Term::query()->count(), 'runs' => ScheduleGenerationRun::query()->count(), 'users' => User::query()->count()];
        $this->assertSame($ids, $seeder->ensure($actor, $prefix));
        $this->assertSame($counts, ['terms' => Term::query()->count(), 'runs' => ScheduleGenerationRun::query()->count(), 'users' => User::query()->count()]);
        $this->assertSame($sentinelBefore, $sentinel->fresh()->getRawOriginal());
        $this->assertSame($studentCount, StudentProfile::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_seed_refuses_a_non_guarded_configuration_before_creating_any_term(): void
    {
        $actor = $this->registrar();
        $count = Term::query()->count();
        config()->set('mail.default', 'log');
        try {
            app(Issue58MultiProgramExplorationSeeder::class)->ensure($actor);
            $this->fail('Unsafe fixture configuration must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('testing/mysql/test_tala_db', $exception->getMessage());
        }
        $this->assertSame($count, Term::query()->count());
    }

    public function test_opt_in_real_local_cp_sat_result_survives_whole_term_ingestion_and_validation(): void
    {
        if (getenv('TALA_VERIFY_LOCAL_CP_SAT') !== '1') {
            $this->markTestSkipped('Run explicitly with the pinned local Python solver; ordinary tests never invoke optimization.');
        }

        $python = base_path('cloud/scheduler-solver/.venv/Scripts/python.exe');
        $this->assertFileExists($python);
        $actor = $this->registrar();
        $fixture = app(Issue58MultiProgramExplorationSeeder::class)->ensure($actor, Issue58MultiProgramExplorationSeeder::Prefix.'-REAL-'.Str::lower(Str::random(8)));
        $term = Term::findOrFail($fixture['term_id']);
        config()->set('tala_integrations.scheduling_solver.driver', 'cloud_run');
        $run = app(ScheduleGenerationService::class)->generate($term, $actor);
        Queue::assertPushed(ScheduleSolverDispatchJob::class, fn (ScheduleSolverDispatchJob $job): bool => $job->scheduleGenerationRunId === $run->id);
        $capturedHash = $run->input_hash;

        $client = new class($python) implements SchedulingSolverClient
        {
            public ?string $failure = null;

            public function __construct(private readonly string $python) {}

            public function solve(SchedulingSolverRequest $request): SchedulingSolverResponse
            {
                try {
                    return $this->execute($request);
                } catch (\Throwable $exception) {
                    $this->failure = $exception::class.': '.$exception->getMessage();
                    throw $exception;
                }
            }

            private function execute(SchedulingSolverRequest $request): SchedulingSolverResponse
            {
                $script = <<<'PY'
                import json, sys
                from tala_solver.server import create_app
                response = create_app().test_client().post('/solve', data=sys.stdin.buffer.read(), headers={
                    'Content-Type': 'application/json',
                    'X-TALA-Solver-Request-ID': sys.argv[1],
                    'X-TALA-Snapshot-SHA256': sys.argv[2],
                })
                print('__TALA_RESULT__' + json.dumps({'status': response.status_code, 'payload': response.get_json(), 'timings': response.headers.get('X-TALA-Solver-Phase-Timings')}))
                PY;
                $started = hrtime(true);
                $process = Process::path(base_path('cloud/scheduler-solver'))
                    ->env(['SOLVER_WORKER_COUNT' => '1', 'SOLVER_REQUEST_BUDGET_SECONDS' => '60'])
                    ->input($request->json())->timeout(80)
                    ->run([$this->python, '-c', $script, $request->requestId(), $request->snapshotSha256()]);
                $process->throw();
                $marker = strrpos($process->output(), '__TALA_RESULT__');
                if ($marker === false) {
                    throw new RuntimeException('Local solver response marker is missing.');
                }
                $response = json_decode(substr($process->output(), $marker + strlen('__TALA_RESULT__')), true, flags: JSON_THROW_ON_ERROR);
                if ($response['status'] !== 200) {
                    throw new RuntimeException('Local solver returned HTTP '.$response['status'].': '.($response['payload']['code'] ?? 'typed failure'));
                }

                return new SchedulingSolverResponse($response['payload'], null, [
                    'authentication_ms' => 0,
                    'transport_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
                    'decode_ms' => 0,
                ], SchedulingSolverResponse::parseSolverPhaseTimings($response['timings']));
            }

            public function probe(): array
            {
                throw new RuntimeException('This opt-in adapter only executes the isolated solve contract.');
            }
        };
        $this->app->instance(SchedulingSolverClient::class, $client);
        $this->app->call([new ScheduleSolverDispatchJob($run->id), 'handle']);
        $run->refresh();

        $this->assertSame($capturedHash, $run->input_hash);
        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $run->status, $client->failure ?? json_encode($run->diagnostics, JSON_THROW_ON_ERROR));
        $this->assertSame(18, $run->candidateRows()->count());
        $this->assertContains(data_get($run->diagnostics, 'solver_result.solver_status'), ['optimal', 'feasible']);
        $this->assertSame(0, $run->candidateRows()->whereNotIn('status', CandidateScheduleRow::committableStatuses())->count());
        $this->assertSame(0, data_get($run->diagnostics, 'solver_result.summary.rejected_count'));
        $this->assertNotSame('synthetic-review-no-solver', $run->solver_version);
        $this->assertGreaterThan(0, $run->runtime_ms);
        $this->assertSame(3, app(TermCoverageProjection::class)->forTerm($term)['summary']['program_count']);
        Mail::assertNothingOutgoing();

        if ($reportPath = getenv('TALA_LOCAL_SOLVER_REPORT')) {
            file_put_contents($reportPath, json_encode([
                'execution' => 'Real local CP-SAT through Flask request handling and Laravel dispatch/ingestion; no Cloud Run or provider call',
                'database' => DB::connection()->getDatabaseName(),
                'fixture' => 'Isolated three-program nine-class eighteen-meeting transaction; rolled back after test',
                'solver_version' => $run->solver_version,
                'solver_status' => data_get($run->diagnostics, 'solver_result.solver_status'),
                'runtime_ms' => $run->runtime_ms,
                'candidate_rows' => $run->candidateRows()->count(),
                'validation_summary' => data_get($run->diagnostics, 'solver_result.summary'),
                'validation_findings' => data_get($run->diagnostics, 'solver_result.findings'),
                'objective_details' => data_get($run->diagnostics, 'solver_result.objective_details'),
                'dispatch' => data_get($run->diagnostics, 'solver_dispatch'),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }

    private function registrar(): User
    {
        foreach ([User::StaffRoleRegistrar, User::StaffRoleFaculty] as $name) {
            Role::findOrCreate($name, 'web');
        }
        $role = Role::findByName(User::StaffRoleRegistrar, 'web');
        $role->givePermissionTo(Permission::findOrCreate('manage-schedules', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $actor = User::factory()->create(['status' => User::StatusActive]);
        $actor->assignRole($role);

        return $actor;
    }
}
