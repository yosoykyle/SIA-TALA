<?php

namespace Tests\Feature;

use App\Actions\Integrations\SchedulingSolver\SchedulingSolverClient;
use App\Actions\Scheduling\ScheduleAssignmentValidationService;
use App\Actions\Scheduling\ScheduleCloudResultIngestor;
use App\Actions\Scheduling\ScheduleGenerationService;
use App\Actions\Scheduling\SchedulePublishService;
use App\Actions\Scheduling\ScheduleSolverSnapshotService;
use App\Filament\Pages\FacultySchedule;
use App\Jobs\ScheduleSolverDispatchJob;
use App\Models\CandidateScheduleRow;
use App\Models\OperationalEvent;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

final class TAL96B2RealLoopbackSchedulingDemoReadinessTest extends TestCase
{
    use DatabaseTransactions;

    private const ContractVersion = 'tal94-demand-v2';

    private const SolverVersion = 'cloud-cp-sat-tal94-demand-v2';

    public function test_b1_baseline_completes_the_real_solver_publication_and_faculty_projection(): void
    {
        $mode = trim((string) getenv('TALA_96B2_ACCEPTANCE_MODE'));

        if ($mode === '' && (string) getenv('TALA_96B2_REAL_LOOPBACK') === '1') {
            $mode = 'local_http';
        }

        if (! in_array($mode, ['local_http', 'cloud_run'], true)) {
            $this->markTestSkipped('Set TALA_96B2_ACCEPTANCE_MODE to local_http or cloud_run for guarded real-service acceptance.');
        }

        $solverUrl = $mode === 'local_http'
            ? (trim((string) getenv('TALA_96B2_SOLVER_URL')) ?: 'http://127.0.0.1:8080')
            : $this->requiredSetting('TALA_96B2_SOLVER_URL');
        $audience = $mode === 'cloud_run'
            ? $this->requiredSetting('TALA_96B2_SOLVER_AUDIENCE')
            : null;
        $credentialsPath = $mode === 'cloud_run'
            ? $this->requiredSetting('TALA_96B2_SOLVER_CREDENTIALS')
            : null;

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotContains(DB::connection()->getDatabaseName(), ['tala_db', 'tala_test_codex']);
        $this->assertSame(0, ScheduleGenerationRun::query()->count(), 'Clear scheduling demo runs before real-loopback acceptance.');
        $this->assertSame(0, DB::table('jobs')->count(), 'Clear the database queue before real-loopback acceptance.');

        if ($credentialsPath !== null) {
            $this->assertFileExists($credentialsPath);
        }

        $exitCode = Artisan::call('acceptance:seed-client-baseline');
        $this->assertSame(Command::SUCCESS, $exitCode, Artisan::output());

        config()->set([
            'queue.default' => 'database',
            'mail.default' => 'array',
            'tala_integrations.scheduling_solver.driver' => $mode,
            'tala_integrations.scheduling_solver.url' => $solverUrl,
            'tala_integrations.scheduling_solver.audience' => $audience,
            'tala_integrations.scheduling_solver.credentials_path' => $credentialsPath,
            'tala_integrations.scheduling_solver.timeout_seconds' => 300,
            'tala_integrations.scheduling_solver.connect_timeout_seconds' => 10,
        ]);
        $this->app->forgetInstance(SchedulingSolverClient::class);
        Queue::fake();
        Mail::fake();

        $solverClient = app(SchedulingSolverClient::class);
        $probe = $solverClient->probe();
        $this->assertSame(200, $probe['status']);
        $this->assertStringContainsString('tal94-demand-v2', $probe['body']);

        $term = Term::query()->where('type', Term::TypeSecondSemester)->sole();
        $registrar = User::query()->where('email', 'registrar.demo@example.test')->sole();
        $expectedCoverage = SchedulingDemand::query()
            ->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id))
            ->orderBy('id')
            ->get()
            ->flatMap(fn (SchedulingDemand $demand): array => collect(range(1, $demand->meeting_count))
                ->map(fn (int $sequence): string => $demand->id.':'.$sequence)
                ->all())
            ->values()
            ->all();

        $run = app(ScheduleGenerationService::class)->generate($term, $registrar);
        $this->assertSame(ScheduleGenerationRun::StatusQueued, $run->status);
        Queue::assertPushed(ScheduleSolverDispatchJob::class);

        $snapshot = $run->input_snapshot;
        $encodedSnapshot = json_encode(
            $snapshot,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
        $snapshotHash = hash('sha256', $encodedSnapshot);
        $results = [];

        foreach (range(1, 3) as $iteration) {
            $result = $solverClient->solve($snapshot);
            $this->assertContains($result['solver_status'], ['optimal', 'feasible'], "Representative run {$iteration} was not usable.");
            $this->assertSame(54, $result['assigned_count']);
            $this->assertSame(0, $result['unassigned_count']);
            $this->assertSame(0, $result['hard_violation_count']);
            $this->assertSame(1, data_get($result, 'solver_statistics.worker_count'));
            $this->assertSame(20260718, data_get($result, 'solver_statistics.random_seed'));
            $this->assertSame(54, data_get($result, 'solver_statistics.input_demand_count'));
            $validation = app(ScheduleAssignmentValidationService::class)->validate($run, $result);
            $this->assertTrue(
                $validation->passes(),
                json_encode($validation->findings(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            );
            $results[] = $result;
        }

        $solutionHashes = collect($results)
            ->map(fn (array $result): string => hash('sha256', json_encode([
                'solver_status' => $result['solver_status'],
                'assignments' => $result['assignments'],
                'objective_score' => $result['objective_score'],
                'objective_details' => $result['objective_details'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)))
            ->unique()
            ->values();

        $dispatchClient = Mockery::mock(SchedulingSolverClient::class);
        $dispatchClient->shouldReceive('solve')
            ->once()
            ->withArgs(fn (array $dispatchedSnapshot): bool => $dispatchedSnapshot === $snapshot)
            ->andReturn($results[0]);

        (new ScheduleSolverDispatchJob((int) $run->id))->handle(
            app(ScheduleSolverSnapshotService::class),
            $dispatchClient,
            app(ScheduleCloudResultIngestor::class),
        );

        $run->refresh();
        $candidates = $run->candidateRows()
            ->orderBy('scheduling_demand_id')
            ->orderBy('meeting_sequence')
            ->get();

        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $run->status);
        $this->assertSame(self::SolverVersion, $run->solver_version);
        $this->assertSame(self::ContractVersion, $run->model_version);
        $this->assertSame(self::ContractVersion, data_get($run->input_snapshot, 'contract_version'));
        $this->assertSame(
            $expectedCoverage,
            $candidates->map(fn (CandidateScheduleRow $row): string => $row->scheduling_demand_id.':'.$row->meeting_sequence)->all(),
        );
        $this->assertTrue($candidates->every(
            fn (CandidateScheduleRow $row): bool => in_array($row->status, [CandidateScheduleRow::StatusOk, CandidateScheduleRow::StatusWarning], true)
                && blank($row->violations),
        ));
        $this->assertSame(0, data_get($run->diagnostics, 'solver_result.summary.unassigned_count'));
        $this->assertSame(0, data_get($run->diagnostics, 'solver_result.summary.hard_violation_count'));
        $this->assertEquals(
            $results[0]['solver_statistics'],
            data_get($run->diagnostics, 'solver_result.solver_statistics'),
        );
        $this->assertGreaterThan(0, $run->runtime_ms);

        $dispatchEvent = OperationalEvent::query()
            ->where('event_type', OperationalEvent::TypeSolverDispatchAttempt)
            ->where('related_record_type', ScheduleGenerationRun::class)
            ->where('related_record_id', $run->id)
            ->sole();
        $this->assertSame(OperationalEvent::StatusProcessed, $dispatchEvent->status);

        $summary = $run->publicationSummary();
        $this->assertSame(0, $summary['conflicts']);
        $published = app(SchedulePublishService::class)->publish(
            $run,
            $registrar,
            $summary['warnings'] > 0 ? 'TAL-96B2 verified real-loopback acceptance.' : null,
        );
        $meetings = $published->sectionMeetings()->orderBy('id')->get();

        $this->assertSame(ScheduleGenerationRun::StatusPublished, $published->status);
        $this->assertCount(count($expectedCoverage), $meetings);
        $this->assertTrue($meetings->every(
            fn (SectionMeeting $meeting): bool => $meeting->state === SectionMeeting::StateActive,
        ));

        $faculty = User::query()->findOrFail($meetings->firstOrFail()->faculty_user_id);
        $facultyMeetings = $meetings->where('faculty_user_id', $faculty->id);

        $facultySchedule = Livewire::actingAs($faculty)
            ->test(FacultySchedule::class);

        $facultySchedule->assertOk();
        $facultySchedule->assertCanSeeTableRecords($facultyMeetings);

        if ((string) getenv('TALA_96B2_REPORT_EVIDENCE') === '1') {
            fwrite(STDOUT, PHP_EOL.'TAL96B2_EVIDENCE='.json_encode([
                'execution_mode' => $mode,
                'snapshot_sha256' => $snapshotHash,
                'solution_sha256_values' => $solutionHashes->all(),
                'unique_solution_count' => $solutionHashes->count(),
                'solver_version' => $results[0]['solver_version'],
                'model_version' => $results[0]['model_version'],
                'runs' => collect($results)
                    ->map(fn (array $result, int $index): array => [
                        'iteration' => $index + 1,
                        'solver_status' => $result['solver_status'],
                        'assigned_count' => $result['assigned_count'],
                        'unassigned_count' => $result['unassigned_count'],
                        'hard_violation_count' => $result['hard_violation_count'],
                        'objective_score' => $result['objective_score'],
                        'runtime_seconds' => $result['runtime_seconds'],
                        'solver_statistics' => $result['solver_statistics'],
                    ])
                    ->all(),
                'publication' => [
                    'candidate_count' => $candidates->count(),
                    'published_meeting_count' => $meetings->count(),
                    'faculty_projection_count' => $facultyMeetings->count(),
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }
    }

    private function requiredSetting(string $key): string
    {
        $value = trim((string) getenv($key));
        $this->assertNotSame('', $value, "{$key} is required for TAL-96B2 cloud acceptance.");

        return $value;
    }
}
