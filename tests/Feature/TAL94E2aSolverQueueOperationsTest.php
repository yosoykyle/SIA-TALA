<?php

namespace Tests\Feature;

use App\Actions\Integrations\SchedulingSolver\SchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverTransportException;
use App\Actions\Scheduling\ScheduleCloudResultIngestor;
use App\Actions\Scheduling\ScheduleGenerationService;
use App\Actions\Scheduling\ScheduleSolverDispatchLifecycleService;
use App\Actions\Scheduling\ScheduleSolverSnapshotService;
use App\Filament\Resources\ScheduleGenerationRuns\Pages\ViewScheduleGenerationRun;
use App\Jobs\ScheduleSolverDispatchJob;
use App\Models\OperationalEvent;
use App\Models\ScheduleGenerationRun;
use App\Models\Term;
use App\Models\User;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Throwable;

final class TAL94E2aSolverQueueOperationsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotContains(DB::connection()->getDatabaseName(), ['tala_db', 'tala_test_codex']);

        foreach ([User::StaffRoleRegistrar, User::StaffRoleSystemSuperAdmin] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Queue::fake();
    }

    public function test_run_generation_rejects_another_active_run_for_the_locked_term(): void
    {
        $term = Term::factory()->create();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->createRun($term, $registrar, ScheduleGenerationRun::StatusQueued);

        try {
            app(ScheduleGenerationService::class)->generate($term, $registrar);
            $this->fail('A second active solver run was accepted for the same term.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Another queued or dispatching solver run already exists for this term.',
                $exception->errors()['term_id'][0] ?? null,
            );
        }

        $this->assertSame(1, ScheduleGenerationRun::query()->whereBelongsTo($term)->count());
        Queue::assertNothingPushed();
    }

    public function test_transient_attempts_are_recorded_once_and_only_exhaustion_finalizes_the_run(): void
    {
        $term = Term::factory()->create();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $run = $this->createRun($term, $registrar, ScheduleGenerationRun::StatusQueued);
        $originalSnapshot = $run->input_snapshot;
        $originalHash = $run->input_hash;
        $counter = new class
        {
            public int $calls = 0;
        };
        $client = $this->failingClient(
            SchedulingSolverTransportException::retryable(
                classification: SchedulingSolverTransportException::ClassificationServerError,
                message: 'Scheduling solver request failed.',
                statusCode: 503,
                previous: new RuntimeException('Bearer secret-token https://private-solver.example.test'),
            ),
            $counter,
        );

        $this->runAttempt($run, $client, attempt: 1, expectException: true);

        $run->refresh();
        $this->assertSame(ScheduleGenerationRun::StatusQueued, $run->status);
        $this->assertSame(1, $counter->calls);
        $this->assertSame(1, OperationalEvent::query()->where('related_record_id', $run->id)->count());

        $this->runAttempt($run, $client, attempt: 1);
        $this->assertSame(1, $counter->calls, 'A duplicate payload for the same cycle and attempt called the solver twice.');

        $this->runAttempt($run, $client, attempt: 2, expectException: true);
        $this->runAttempt($run, $client, attempt: 3, expectFailure: true);

        $run->refresh();
        $events = OperationalEvent::query()
            ->where('related_record_type', ScheduleGenerationRun::class)
            ->where('related_record_id', $run->id)
            ->orderBy('id')
            ->get();

        $this->assertSame(3, $counter->calls);
        $this->assertSame(ScheduleGenerationRun::StatusFailed, $run->status);
        $this->assertEquals($originalSnapshot, $run->input_snapshot);
        $this->assertSame($originalHash, $run->input_hash);
        $this->assertSame([1, 2, 3], $events->pluck('diagnostics.attempt')->all());
        $this->assertSame([false, false, true], $events->pluck('diagnostics.final')->all());
        $this->assertSame(['FAILED'], $events->pluck('status')->unique()->values()->all());
        $this->assertSame(3, $events->pluck('external_id')->unique()->count());
        $this->assertTrue((bool) data_get($run->diagnostics, 'solver_dispatch.failure.retryable'));
        $this->assertTrue((bool) data_get($run->diagnostics, 'solver_dispatch.failure.final'));
        $this->assertSame(
            SchedulingSolverTransportException::ClassificationServerError,
            data_get($run->diagnostics, 'solver_dispatch.failure.classification'),
        );

        $encodedEvents = (string) json_encode($events->pluck('diagnostics')->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret-token', $encodedEvents);
        $this->assertStringNotContainsString('private-solver.example.test', $encodedEvents);

        $job = new ScheduleSolverDispatchJob((int) $run->id);
        $this->assertSame(3, $job->tries);
        $this->assertSame(360, $job->timeout);
        $this->assertSame([60, 300], $job->backoff());
    }

    public function test_permanent_transport_failure_is_final_on_the_first_attempt_and_not_retryable(): void
    {
        $term = Term::factory()->create();
        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $run = $this->createRun($term, $superAdmin, ScheduleGenerationRun::StatusQueued);
        $counter = new class
        {
            public int $calls = 0;
        };
        $client = $this->failingClient(
            SchedulingSolverTransportException::permanent(
                classification: SchedulingSolverTransportException::ClassificationConfiguration,
                message: 'Scheduling solver is not configured.',
                previous: new RuntimeException('C:\\private\\scheduler-invoker.json'),
            ),
            $counter,
        );

        $this->runAttempt($run, $client, attempt: 1, expectFailure: true);

        $run->refresh();
        $event = OperationalEvent::query()->where('related_record_id', $run->id)->sole();

        $this->assertSame(1, $counter->calls);
        $this->assertSame(ScheduleGenerationRun::StatusFailed, $run->status);
        $this->assertFalse((bool) data_get($event->diagnostics, 'retryable'));
        $this->assertTrue((bool) data_get($event->diagnostics, 'final'));
        $this->assertSame(
            SchedulingSolverTransportException::ClassificationConfiguration,
            data_get($event->diagnostics, 'classification'),
        );
        $this->assertFalse(Gate::forUser($superAdmin)->allows('retry', $run));
        $this->assertStringNotContainsString('scheduler-invoker.json', (string) json_encode($event->diagnostics));
    }

    public function test_authorized_retry_requeues_the_same_immutable_run_and_preserves_prior_attempts(): void
    {
        $term = Term::factory()->create();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $systemAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $run = $this->finallyFailedTransientRun($term, $registrar);
        $originalSnapshot = $run->input_snapshot;
        $originalHash = $run->input_hash;
        $event = $this->failedAttemptEvent($run, cycle: 1, attempt: 3, final: true);

        $this->assertTrue(Gate::forUser($registrar)->allows('retry', $run));
        $this->assertFalse(Gate::forUser($systemAdmin)->allows('retry', $run));

        $component = Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $run->getRouteKey()])
            ->assertOk()
            ->assertActionExists('retrySolverRun');

        $html = $component->html();
        $this->assertStringContainsString('Operations', $html);
        $this->assertStringContainsString('Retryable', $html);

        $component
            ->callAction('retrySolverRun')
            ->assertNotified();

        $run->refresh();

        $this->assertSame(ScheduleGenerationRun::StatusQueued, $run->status);
        $this->assertSame(2, $run->dispatchCycle());
        $this->assertSame(0, (int) data_get($run->diagnostics, 'solver_dispatch.last_attempt'));
        $this->assertEquals($originalSnapshot, $run->input_snapshot);
        $this->assertSame($originalHash, $run->input_hash);
        $this->assertTrue(OperationalEvent::query()->whereKey($event->id)->exists());
        $this->assertSame(1, OperationalEvent::query()->where('related_record_id', $run->id)->count());
        Queue::assertPushed(
            ScheduleSolverDispatchJob::class,
            fn (ScheduleSolverDispatchJob $job): bool => $job->scheduleGenerationRunId === $run->id,
        );
    }

    public function test_retry_is_denied_for_active_successful_permanent_candidate_bearing_and_published_runs(): void
    {
        $term = Term::factory()->create();
        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $cases = [
            'active' => $this->createRun($term, $superAdmin, ScheduleGenerationRun::StatusQueued),
            'successful' => $this->createRun($term, $superAdmin, ScheduleGenerationRun::StatusUnderReview),
            'permanent failure' => $this->createRun($term, $superAdmin, ScheduleGenerationRun::StatusFailed, [
                'solver_dispatch' => [
                    'dispatch_cycle' => 1,
                    'last_attempt' => 1,
                    'failure' => ['retryable' => false, 'final' => true],
                ],
            ]),
            'candidate bearing' => $this->createRun(
                $term,
                $superAdmin,
                ScheduleGenerationRun::StatusFailed,
                $this->finalTransientDiagnostics(),
                candidateKey: 'candidate-present',
            ),
            'published' => $this->createRun($term, $superAdmin, ScheduleGenerationRun::StatusPublished, [
                'solver_dispatch' => [
                    'dispatch_cycle' => 1,
                    'last_attempt' => 3,
                    'failure' => ['retryable' => true, 'final' => true],
                ],
            ]),
        ];

        foreach ($cases as $label => $run) {
            $this->assertFalse(
                Gate::forUser($superAdmin)->allows('retry', $run),
                "The {$label} run was incorrectly authorized for retry.",
            );
        }
    }

    public function test_database_queue_and_canonical_worker_guidance_match_the_solver_timeout(): void
    {
        $this->assertSame(420, config('queue.connections.database.retry_after'));

        $environmentExample = file_get_contents(base_path('.env.example'));
        $composer = file_get_contents(base_path('composer.json'));
        $readme = file_get_contents(base_path('README.md'));
        $solverReadme = file_get_contents(base_path('cloud/scheduler-solver/README.md'));

        $this->assertIsString($environmentExample);
        $this->assertIsString($composer);
        $this->assertIsString($readme);
        $this->assertIsString($solverReadme);
        $this->assertStringContainsString('DB_QUEUE_RETRY_AFTER=420', $environmentExample);
        $this->assertStringContainsString('--queue=scheduling,default', $composer);
        $this->assertStringNotContainsString('--tries=1', $composer);
        $this->assertStringContainsString('--queue=scheduling,default', $readme);
        $this->assertStringContainsString('--queue=scheduling,default', $solverReadme);
        $this->assertStringNotContainsString('--tries=1', $solverReadme);
    }

    private function runAttempt(
        ScheduleGenerationRun $run,
        SchedulingSolverClient $client,
        int $attempt,
        bool $expectException = false,
        bool $expectFailure = false,
    ): void {
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempt);

        if ($expectFailure) {
            $queueJob->shouldReceive('fail')->once()->with(Mockery::type(Throwable::class));
        } else {
            $queueJob->shouldReceive('fail')->never();
        }

        $job = new ScheduleSolverDispatchJob((int) $run->id);
        $job->setJob($queueJob);

        try {
            $job->handle(
                app(ScheduleSolverSnapshotService::class),
                $client,
                app(ScheduleCloudResultIngestor::class),
                app(ScheduleSolverDispatchLifecycleService::class),
            );

            if ($expectException) {
                $this->fail('A retryable intermediate transport failure did not escape to the queue worker.');
            }
        } catch (SchedulingSolverTransportException $exception) {
            if (! $expectException) {
                throw $exception;
            }

            $this->assertTrue($exception->isRetryable());
        }
    }

    private function failingClient(SchedulingSolverTransportException $exception, object $counter): SchedulingSolverClient
    {
        return new class($exception, $counter) implements SchedulingSolverClient
        {
            public function __construct(
                private readonly SchedulingSolverTransportException $exception,
                private readonly object $counter,
            ) {}

            public function solve(array $snapshot): array
            {
                $this->counter->calls++;

                throw $this->exception;
            }

            public function probe(): array
            {
                return ['status' => 503, 'body' => 'unavailable'];
            }
        };
    }

    private function finallyFailedTransientRun(Term $term, User $actor): ScheduleGenerationRun
    {
        return $this->createRun(
            $term,
            $actor,
            ScheduleGenerationRun::StatusFailed,
            $this->finalTransientDiagnostics(),
        );
    }

    /** @return array<string, mixed> */
    private function finalTransientDiagnostics(): array
    {
        return [
            'solver_dispatch' => [
                'status' => 'failed',
                'dispatch_cycle' => 1,
                'last_attempt' => 3,
                'failure' => [
                    'classification' => SchedulingSolverTransportException::ClassificationServerError,
                    'retryable' => true,
                    'final' => true,
                    'message' => 'Scheduling solver request failed.',
                ],
            ],
        ];
    }

    private function failedAttemptEvent(
        ScheduleGenerationRun $run,
        int $cycle,
        int $attempt,
        bool $final,
    ): OperationalEvent {
        return OperationalEvent::query()->create([
            'event_domain' => OperationalEvent::DomainIntegration,
            'integration' => OperationalEvent::IntegrationSchedulingSolver,
            'channel' => 'queue',
            'direction' => 'OUTBOUND',
            'event_type' => OperationalEvent::TypeSolverDispatchAttempt,
            'event_version' => '1',
            'user_id' => $run->requested_by,
            'external_id' => "schedule-solver:run:{$run->id}:cycle:{$cycle}:attempt:{$attempt}",
            'status' => OperationalEvent::StatusFailed,
            'occurred_at' => now()->subMinute(),
            'failed_at' => now(),
            'related_record_type' => ScheduleGenerationRun::class,
            'related_record_id' => $run->id,
            'diagnostics' => [
                'cycle' => $cycle,
                'attempt' => $attempt,
                'classification' => SchedulingSolverTransportException::ClassificationServerError,
                'retryable' => true,
                'final' => $final,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $diagnostics
     */
    private function createRun(
        Term $term,
        User $actor,
        string $status,
        ?array $diagnostics = null,
        ?string $candidateKey = null,
    ): ScheduleGenerationRun {
        $snapshot = [
            'contract_version' => 'tal94-demand-v2',
            'run_metadata' => [
                'term_id' => $term->id,
            ],
            'scheduling_demands' => [],
        ];

        return ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => $status,
            'requested_by' => $actor->id,
            'input_snapshot' => $snapshot,
            'input_hash' => hash('sha256', (string) Str::uuid()),
            'solver_version' => 'test-solver',
            'candidate_key' => $candidateKey,
            'published_at' => $status === ScheduleGenerationRun::StatusPublished ? now() : null,
            'diagnostics' => $diagnostics ?? [
                'solver_dispatch' => [
                    'status' => 'queued',
                    'dispatch_cycle' => 1,
                    'last_attempt' => 0,
                ],
            ],
        ]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
