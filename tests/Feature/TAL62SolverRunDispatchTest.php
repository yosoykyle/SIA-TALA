<?php

namespace Tests\Feature;

use App\Actions\Integrations\SchedulingSolver\CloudRunIdTokenProvider;
use App\Actions\Integrations\SchedulingSolver\CloudRunSchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\GoogleServiceAccountCloudRunIdTokenProvider;
use App\Actions\Integrations\SchedulingSolver\LocalHttpSchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\LocalStubSchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverRequest;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverResponse;
use App\Actions\Scheduling\ConfirmClassOffering;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\PrepareClassOffering;
use App\Actions\Scheduling\RecordTermCohort;
use App\Actions\Scheduling\RequestTimetableRepair;
use App\Actions\Scheduling\ScheduleCloudResultIngestor;
use App\Actions\Scheduling\ScheduleGenerationService;
use App\Actions\Scheduling\ScheduleSolverDispatchLifecycleService;
use App\Actions\Scheduling\ScheduleSolverRecoveryService;
use App\Actions\Scheduling\ScheduleSolverRetryService;
use App\Actions\Scheduling\ScheduleSolverSnapshotService;
use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\ScheduleGenerationRuns\Pages\ListScheduleGenerationRuns;
use App\Filament\Resources\ScheduleGenerationRuns\Pages\ViewScheduleGenerationRun;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Jobs\ScheduleSolverDispatchJob;
use App\Models\CalendarEvent;
use App\Models\CandidateScheduleRow;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\OperationalEvent;
use App\Models\Program;
use App\Models\Room;
use App\Models\RoomFeature;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermOffering;
use App\Models\TermTeachingGridRow;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL62SolverRunDispatchTest extends TestCase
{
    use DatabaseTransactions;

    private GenerateSchedulingDemand $demandGenerator;

    private ScheduleGenerationService $runService;

    private int $scopeCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame(env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());

        config()->set('tala_integrations.scheduling_solver.driver', 'local_stub');
        $this->app->forgetInstance(SchedulingSolverClient::class);
        Queue::fake();

        $this->demandGenerator = app(GenerateSchedulingDemand::class);
        $this->runService = app(ScheduleGenerationService::class);

        foreach ([User::StaffRoleRegistrar, User::StaffRoleAcademicHead, User::StaffRoleSystemSuperAdmin, User::StaffRoleFaculty] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Role::findByName(User::StaffRoleRegistrar, 'web')->givePermissionTo(Permission::findOrCreate('manage-schedules', 'web'));
    }

    /** @return array<string, array{bool}> */
    public static function recoveryKinds(): array
    {
        return ['generation' => [false], 'repair' => [true]];
    }

    /** @return array{term:Term,registrar:User,failed:ScheduleGenerationRun} */
    private function deliberateRecoveryFixture(bool $repair = false): array
    {
        $source = $this->schedulingSource(withSecondComponent: false);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->demandGenerator->forTerm($registrar, $source['term']);
        $failed = $this->runService->generate($source['term'], $registrar);
        if ($repair) {
            $this->dispatchRecoveryRun($failed, new LocalStubSchedulingSolverClient);
            $row = $failed->candidateRows()->sole();
            $failed = app(RequestTimetableRepair::class)->execute($row, $registrar,
                ['faculty_user_id' => $row->faculty_user_id, 'room_id' => $row->room_id, 'day_of_week' => $row->day_of_week,
                    'starts_at' => $row->starts_at, 'ends_at' => $row->ends_at], 'Preserve this intended repair.', 'SYNTH-RECOVERY-AUTHORITY');
        }
        $diagnostics = $failed->diagnostics;
        $diagnostics['solver_dispatch']['failure'] = ['classification' => 'client_error', 'final' => true, 'retryable' => false, 'http_status' => 403,
            'message' => 'Service access failed. Ask the System Administrator to correct access.'];
        $failed->forceFill(['status' => ScheduleGenerationRun::StatusFailed, 'diagnostics' => $diagnostics])->save();

        return ['term' => $source['term'], 'registrar' => $registrar, 'failed' => $failed->fresh()];
    }

    /** @return array<string, array{string}> */
    public static function invalidRecoveryExplanations(): array
    {
        return ['blank' => ['   '], 'overlong' => [str_repeat('x', 2001)]];
    }

    #[DataProvider('invalidRecoveryExplanations')]
    public function test_deliberate_recovery_requires_a_bounded_nonblank_correction_explanation(string $explanation): void
    {
        $fixture = $this->deliberateRecoveryFixture();
        $before = $fixture['failed']->getAttributes();

        try {
            app(ScheduleSolverRetryService::class)->recover($fixture['failed'], $fixture['registrar'], $explanation);
            $this->fail('An invalid correction explanation queued a new attempt.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('correction_explanation', $exception->errors());
        }

        $this->assertSame($before, $fixture['failed']->fresh()->getAttributes());
        $this->assertSame(1, ScheduleGenerationRun::query()->where('term_id', $fixture['term']->id)->count());
        Queue::assertPushed(ScheduleSolverDispatchJob::class, 1);
    }

    #[DataProvider('recoveryKinds')]
    public function test_deliberate_recovery_rechecks_current_planning_readiness(bool $repair): void
    {
        $fixture = $this->deliberateRecoveryFixture($repair);
        $before = $fixture['failed']->getAttributes();
        SchedulingDemand::query()->whereHas('termOffering', fn ($query) => $query->where('term_id', $fixture['term']->id))
            ->update(['validation_state' => SchedulingDemand::ValidationActionRequired]);
        $count = ScheduleGenerationRun::query()->where('term_id', $fixture['term']->id)->count();

        try {
            app(ScheduleSolverRetryService::class)->recover($fixture['failed'], $fixture['registrar'], 'Administrator corrected the service.');
            $this->fail('Unready current planning data queued a recovery.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scheduling_demands', $exception->errors());
        }

        $this->assertSame($count, ScheduleGenerationRun::query()->where('term_id', $fixture['term']->id)->count());
        $this->assertSame($before, $fixture['failed']->fresh()->getAttributes());
        Queue::assertPushed(ScheduleSolverDispatchJob::class, $repair ? 2 : 1);
    }

    public function test_deliberate_recovery_is_registrar_only_and_preserves_native_solver_outcome_gates(): void
    {
        $fixture = $this->deliberateRecoveryFixture();
        $head = $this->staff(User::StaffRoleAcademicHead);
        $this->assertFalse(Gate::forUser($head)->allows('recover', $fixture['failed']));
        try {
            app(ScheduleSolverRetryService::class)->recover($fixture['failed'], $head, 'A service correction was confirmed.');
            $this->fail('Oversight queued an operational recovery.');
        } catch (AuthorizationException) {
            $this->assertSame(1, ScheduleGenerationRun::query()->where('term_id', $fixture['term']->id)->count());
        }
        foreach (['infeasible', 'unknown', 'model_invalid'] as $outcome) {
            $fixture['failed']->forceFill(['status' => ScheduleGenerationRun::StatusBlocked,
                'diagnostics' => ['solver_result' => ['solver_status' => $outcome, 'summary' => ['outcome' => match ($outcome) {
                    'infeasible' => 'Infeasible', 'unknown' => 'Unknown', default => 'ModelInvalid',
                }]]]])->save();
            $this->assertFalse(Gate::forUser($fixture['registrar'])->allows('recover', $fixture['failed']));
            $fixture['failed']->forceFill(['status' => ScheduleGenerationRun::StatusFailed,
                'diagnostics' => ['solver_dispatch' => ['failure' => ['classification' => 'unexpected', 'final' => true, 'code' => strtoupper($outcome)]]]])->save();
            $this->assertFalse(Gate::forUser($fixture['registrar'])->allows('recover', $fixture['failed']));
        }
        Queue::assertPushed(ScheduleSolverDispatchJob::class, 1);
    }

    #[DataProvider('recoveryKinds')]
    public function test_native_recovery_action_validates_then_selects_its_fresh_successor_and_displays_attribution(bool $detail): void
    {
        Http::preventStrayRequests();
        $fixture = $this->deliberateRecoveryFixture();
        $before = $fixture['failed']->getAttributes();
        $component = Livewire::actingAs($fixture['registrar'])->test($detail ? ViewScheduleGenerationRun::class : TermTimetable::class,
            $detail ? ['record' => $fixture['failed']->getRouteKey(), 'termId' => $fixture['term']->id]
                : ['termId' => $fixture['term']->id, 'selectedRunId' => $fixture['failed']->id]);
        $component->assertActionVisible('retrySolverRun');
        if (! $detail) {
            $component->assertActionHidden('generateTimetable');
        }
        $component->callAction('retrySolverRun', data: ['correction_explanation' => ''])->assertHasActionErrors(['correction_explanation' => 'required']);
        $this->assertSame(1, ScheduleGenerationRun::query()->where('term_id', $fixture['term']->id)->count());

        $component->setActionData(['correction_explanation' => 'Administrator corrected access; <service> verified.'])->callMountedAction()->assertHasNoActionErrors()->assertNotified();

        $successor = ScheduleGenerationRun::query()->where('term_id', $fixture['term']->id)->latest('id')->firstOrFail();
        $this->assertSame($before, $fixture['failed']->fresh()->getAttributes());
        $this->assertSame($fixture['failed']->id, data_get($successor->diagnostics, 'technical_recovery.source_run_id'));
        if ($detail) {
            $component->assertRedirect(ScheduleGenerationRunResource::getUrl('view', ['record' => $successor, 'termId' => $fixture['term']->id]));
        } else {
            $component->assertSet('selectedRunId', $successor->id);
        }
        Livewire::actingAs($fixture['registrar'])->test(ViewScheduleGenerationRun::class, ['record' => $successor->id, 'termId' => $fixture['term']->id])
            ->assertSee('Service recovery')->assertSee('From attempt #'.$fixture['failed']->id)
            ->assertSee($fixture['registrar']->name)->assertSee('Administrator corrected access; <service> verified.')
            ->assertDontSeeHtml('Administrator corrected access; <service> verified.');
        Http::assertNothingSent();
        Queue::assertPushed(ScheduleSolverDispatchJob::class, 2);
    }

    /** @return array<string, array{string, bool, bool}> */
    public static function explicitRecoveryCases(): array
    {
        return ['IAM generation' => ['iam', false, false], 'IAM repair' => ['iam', true, false],
            'output generation' => ['output', false, false], 'output repair' => ['output', true, false],
            'legacy generation' => ['output', false, true], 'legacy repair' => ['output', true, true]];
    }

    #[DataProvider('explicitRecoveryCases')]
    public function test_deliberate_recovery_queues_one_attributed_fresh_request_after_a_same_endpoint_service_fix(string $failure, bool $repair, bool $legacy): void
    {
        Http::preventStrayRequests();
        $source = $this->schedulingSource(withSecondComponent: false);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->demandGenerator->forTerm($registrar, $source['term']);
        $sourceRun = null;
        $sourceBefore = null;
        if ($repair) {
            $sourceRun = $this->runService->generate($source['term'], $registrar);
            $this->dispatchRecoveryRun($sourceRun, new LocalStubSchedulingSolverClient);
            $sourceBefore = $sourceRun->fresh()->getAttributes();
            $row = $sourceRun->candidateRows()->sole();
            $assignment = ['faculty_user_id' => $row->faculty_user_id, 'room_id' => $row->room_id,
                'day_of_week' => $row->day_of_week, 'starts_at' => $row->starts_at, 'ends_at' => $row->ends_at];
        }
        $endpoint = $failure === 'iam' ? 'https://solver.example.test' : 'http://127.0.0.1:9089';
        config()->set('tala_integrations.scheduling_solver.driver', $failure === 'iam' ? 'cloud_run' : 'local_http');
        config()->set('tala_integrations.scheduling_solver.url', $endpoint);
        config()->set('tala_integrations.scheduling_solver.audience', $endpoint);
        $failed = $repair
            ? app(RequestTimetableRepair::class)->execute($row, $registrar, $assignment, 'Preserve this intended repair.', 'SYNTH-RECOVERY-AUTHORITY')
            : $this->runService->generate($source['term'], $registrar);
        $serviceFixed = false;
        Http::fake([$endpoint.'/solve' => function ($request) use ($failure, &$serviceFixed) {
            if ($serviceFixed) {
                return Http::response($this->recoveryPayload($request->data()));
            }
            if ($failure === 'iam') {
                return Http::response(['error' => 'SYNTHETIC forbidden until service access is corrected.'], 403);
            }
            $payload = $this->recoveryPayload($request->data());
            $payload['solver_status'] = 'feasible';
            $payload['assigned_count'] = 99;

            return Http::response($payload);
        }]);
        if ($failure === 'iam') {
            $tokens = $this->mock(CloudRunIdTokenProvider::class);
            $tokens->shouldReceive('tokenFor')->twice()->with($endpoint)->andReturn('SYNTHETIC-ID-TOKEN');
            $client = new CloudRunSchedulingSolverClient($tokens, $endpoint, $endpoint, 30, 5);
        } else {
            $client = new LocalHttpSchedulingSolverClient($endpoint, 30, 5, 'testing');
        }
        $this->dispatchRecoveryRun($failed, $client);
        $failed->refresh();
        if ($legacy) {
            $diagnostics = $failed->diagnostics;
            unset($diagnostics['solver_dispatch']['technical_context_fingerprint']);
            $failed->forceFill(['diagnostics' => $diagnostics])->save();
            $failed->refresh();
        }
        $before = $failed->getAttributes();
        $this->assertFalse($failed->canRetrySolver());
        $serviceFixed = true;

        $successor = app(ScheduleSolverRetryService::class)->recover($failed, $registrar, '  Administrator confirmed the service access or output correction.  ');

        $this->assertSame(ScheduleGenerationRun::StatusQueued, $successor->status);
        $this->assertNotSame($failed->id, $successor->id);
        $this->assertSame($before, $failed->fresh()->getAttributes());
        $this->assertSame($failed->id, data_get($successor->diagnostics, 'technical_recovery.source_run_id'));
        $this->assertSame($registrar->id, data_get($successor->diagnostics, 'technical_recovery.requested_by'));
        $this->assertSame('Administrator confirmed the service access or output correction.', data_get($successor->diagnostics, 'technical_recovery.correction_explanation'));
        $this->assertNotEmpty(data_get($successor->diagnostics, 'technical_recovery.requested_at'));
        if ($repair) {
            $this->assertSame(data_get($failed->input_snapshot, 'operation'), data_get($successor->input_snapshot, 'operation'));
            $this->assertSame($sourceBefore, $sourceRun->fresh()->getAttributes());
        }
        $count = ScheduleGenerationRun::query()->where('term_id', $source['term']->id)->count();
        try {
            app(ScheduleSolverRetryService::class)->recover($failed, $registrar, 'Repeat the already recovered request.');
            $this->fail('A stale or double-submitted recovery queued another run.');
        } catch (AuthorizationException|ValidationException) {
            $this->assertSame($count, ScheduleGenerationRun::query()->where('term_id', $source['term']->id)->count());
        }
        $this->dispatchRecoveryRun($successor, $client);
        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $successor->fresh()->status);
        Http::assertSentCount(2);
        Queue::assertPushed(ScheduleSolverDispatchJob::class, $repair ? 3 : 2);
    }

    #[DataProvider('recoveryKinds')]
    public function test_fresh_request_recovers_after_corrected_credentials_and_preserves_terminal_failure(bool $repair): void
    {
        Http::preventStrayRequests();
        Storage::fake('local');
        config()->set('tala_integrations.scheduling_solver', [
            'driver' => 'cloud_run', 'url' => 'https://solver.example.test',
            'audience' => 'https://solver.example.test', 'credentials_path' => null,
            'timeout_seconds' => 30, 'connect_timeout_seconds' => 5,
        ]);
        $source = $this->schedulingSource(withSecondComponent: false);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->demandGenerator->forTerm($registrar, $source['term']);
        $sourceRun = null;
        $sourceBefore = null;
        $request = fn () => $this->runService->generate($source['term'], $registrar);
        if ($repair) {
            config()->set('tala_integrations.scheduling_solver.driver', 'local_stub');
            $sourceRun = $request();
            $this->dispatchRecoveryRun($sourceRun, new LocalStubSchedulingSolverClient);
            $sourceBefore = $sourceRun->fresh()->getAttributes();
            $row = $sourceRun->candidateRows()->sole();
            $assignment = ['faculty_user_id' => $row->faculty_user_id, 'room_id' => $row->room_id,
                'day_of_week' => $row->day_of_week, 'starts_at' => $row->starts_at, 'ends_at' => $row->ends_at];
            $request = fn () => app(RequestTimetableRepair::class)->execute($row, $registrar, $assignment, 'Recover this bounded repair.', 'SYNTH-RECOVERY');
            config()->set('tala_integrations.scheduling_solver.driver', 'cloud_run');
        }
        $failed = $request();
        $this->dispatchRecoveryRun($failed, new CloudRunSchedulingSolverClient(
            new GoogleServiceAccountCloudRunIdTokenProvider(null), 'https://solver.example.test', 'https://solver.example.test', 30, 5,
        ));
        $failed->refresh();
        $this->assertSame('credential', $failed->finalSolverFailure()['classification']);
        $this->assertFalse($failed->canRetrySolver());
        $before = $failed->getAttributes();
        try {
            $request();
            $this->fail('Uncorrected credentials repeated the failed request.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($repair ? 'repair' : 'generation', $exception->errors());
        }

        Storage::disk('local')->put('corrected-solver.json', '{"private_key":"SYNTHETIC-CORRECTED-CREDENTIAL"}');
        config()->set('tala_integrations.scheduling_solver.credentials_path', Storage::disk('local')->path('corrected-solver.json'));
        $tokens = $this->mock(CloudRunIdTokenProvider::class);
        $tokens->shouldReceive('tokenFor')->once()->with('https://solver.example.test')->andReturn('SYNTHETIC-ID-TOKEN');
        Http::fake(['https://solver.example.test/solve' => fn ($request) => Http::response($this->recoveryPayload($request->data()), 200)]);

        $successor = $request();
        $this->dispatchRecoveryRun($successor, new CloudRunSchedulingSolverClient($tokens, 'https://solver.example.test', 'https://solver.example.test', 30, 5));

        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $successor->fresh()->status);
        $this->assertSame($before, $failed->fresh()->getAttributes());
        $this->assertSame(1, $failed->solverAttemptEvents()->count());
        $this->assertSame(1, $successor->candidateRows()->count());
        if ($repair) {
            $this->assertSame(data_get($failed->input_snapshot, 'operation.requested_assignment'), data_get($successor->input_snapshot, 'operation.requested_assignment'));
            $this->assertSame($sourceBefore, $sourceRun->fresh()->getAttributes());
        }
        $this->assertStringNotContainsString('SYNTHETIC-CORRECTED-CREDENTIAL', json_encode($successor->fresh()->diagnostics));
        Http::assertSentCount(1);
        Queue::assertPushed(ScheduleSolverDispatchJob::class, $repair ? 3 : 2);
    }

    public function test_fresh_generation_recovers_after_permanent_integration_configuration_is_corrected(): void
    {
        Http::preventStrayRequests();
        config()->set('tala_integrations.scheduling_solver.driver', 'local_http');
        config()->set('tala_integrations.scheduling_solver.url', 'https://invalid.example.test');
        $source = $this->schedulingSource(withSecondComponent: false);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->demandGenerator->forTerm($registrar, $source['term']);
        $failed = $this->runService->generate($source['term'], $registrar);
        $this->dispatchRecoveryRun($failed, new LocalHttpSchedulingSolverClient('https://invalid.example.test', 30, 5, 'testing'));
        $failed->refresh();
        $this->assertSame('configuration', $failed->finalSolverFailure()['classification']);
        $before = $failed->getAttributes();
        $this->assertEquivalentGenerationBlocked($source['term'], $registrar);
        config()->set('tala_integrations.scheduling_solver.url', 'http://127.0.0.1:9088');
        Http::fake(['http://127.0.0.1:9088/solve' => fn ($request) => Http::response($this->recoveryPayload($request->data()), 200)]);

        $successor = $this->runService->generate($source['term'], $registrar);
        $this->dispatchRecoveryRun($successor, new LocalHttpSchedulingSolverClient('http://127.0.0.1:9088', 30, 5, 'testing'));

        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $successor->fresh()->status);
        $this->assertSame($before, $failed->fresh()->getAttributes());
        Http::assertSentCount(1);
        Queue::assertPushed(ScheduleSolverDispatchJob::class, 2);
    }

    /** @return array<string, array{string, bool}> */
    public static function rejectedRecoveryRequests(): array
    {
        return ['optimal generation' => ['optimal', false], 'feasible generation' => ['feasible', false],
            'optimal repair' => ['optimal', true], 'feasible repair' => ['feasible', true],
            'malformed unknown generation' => ['unknown', false], 'malformed unknown repair' => ['unknown', true],
            'malformed model generation' => ['model_invalid', false], 'malformed model repair' => ['model_invalid', true]];
    }

    #[DataProvider('rejectedRecoveryRequests')]
    public function test_independently_rejected_solver_output_allows_a_fresh_request_after_technical_correction(string $status, bool $repair): void
    {
        Http::preventStrayRequests();
        $source = $this->schedulingSource(withSecondComponent: false);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->demandGenerator->forTerm($registrar, $source['term']);
        $run = $this->runService->generate($source['term'], $registrar);
        $request = fn () => $this->runService->generate($source['term'], $registrar);
        $sourceBefore = null;
        if ($repair) {
            $this->dispatchRecoveryRun($run, new LocalStubSchedulingSolverClient);
            $sourceBefore = $run->fresh()->getAttributes();
            $row = $run->candidateRows()->sole();
            $assignment = ['faculty_user_id' => $row->faculty_user_id, 'room_id' => $row->room_id,
                'day_of_week' => $row->day_of_week, 'starts_at' => '13:00:00', 'ends_at' => '16:00:00'];
            $request = fn () => app(RequestTimetableRepair::class)->execute($row, $registrar, $assignment, 'Recover this bounded repair.', 'SYNTH-RECOVERY');
        }
        config()->set('tala_integrations.scheduling_solver.driver', 'local_http');
        config()->set('tala_integrations.scheduling_solver.url', 'http://127.0.0.1:9087');
        $failed = $repair ? $request() : $run;
        Http::fake(['http://127.0.0.1:9087/solve' => function ($request) use ($status) {
            $payload = $this->recoveryPayload($request->data());
            $payload['solver_status'] = $status;
            $payload['assigned_count'] = 99;

            return Http::response($payload, 200);
        }]);
        $this->dispatchRecoveryRun($failed, new LocalHttpSchedulingSolverClient('http://127.0.0.1:9087', 30, 5, 'testing'));
        $failed->refresh();
        $this->assertSame('TechnicalFailure', data_get($failed->diagnostics, 'solver_result.summary.outcome'));
        $this->assertSame(0, $failed->candidateRows()->count());
        $this->assertFalse($failed->canRetrySolver());
        $before = $failed->getAttributes();
        try {
            $request();
            $this->fail('An unchanged technical context repeated a rejected result.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($repair ? 'repair' : 'generation', $exception->errors());
        }
        config()->set('tala_integrations.scheduling_solver.url', 'http://127.0.0.1:9088');

        $successor = $request();

        $this->assertNotSame($failed->id, $successor->id);
        $this->assertSame(ScheduleGenerationRun::StatusQueued, $successor->status);
        $this->assertSame($before, $failed->fresh()->getAttributes());
        if ($repair) {
            $this->assertSame($sourceBefore, $run->fresh()->getAttributes());
            $this->assertSame(data_get($failed->input_snapshot, 'operation.requested_assignment'), data_get($successor->input_snapshot, 'operation.requested_assignment'));
        }
        Http::assertSentCount(1);
        Queue::assertPushed(ScheduleSolverDispatchJob::class, $repair ? 3 : 2);
    }

    private function assertEquivalentGenerationBlocked(Term $term, User $registrar): void
    {
        $before = ScheduleGenerationRun::query()->where('term_id', $term->id)->count();
        try {
            $this->runService->generate($term, $registrar);
            $this->fail('Uncorrected integration was allowed to generate again.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('generation', $exception->errors());
        }
        $this->assertSame($before, ScheduleGenerationRun::query()->where('term_id', $term->id)->count());
    }

    public function test_legacy_missing_context_does_not_establish_same_driver_technical_correction(): void
    {
        Http::preventStrayRequests();
        config()->set('tala_integrations.scheduling_solver.driver', 'local_http');
        config()->set('tala_integrations.scheduling_solver.url', 'http://127.0.0.1:9087');
        $source = $this->schedulingSource(withSecondComponent: false);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->demandGenerator->forTerm($registrar, $source['term']);
        $failed = $this->runService->generate($source['term'], $registrar);
        $failed->forceFill(['status' => ScheduleGenerationRun::StatusFailed, 'diagnostics' => [
            'solver_dispatch' => ['driver' => 'local_http', 'failure' => ['classification' => 'configuration', 'final' => true, 'retryable' => false]],
        ]])->save();
        $before = $failed->fresh()->getAttributes();
        config()->set('tala_integrations.scheduling_solver.url', 'http://127.0.0.1:9088');

        $this->assertEquivalentGenerationBlocked($source['term'], $registrar);

        $this->assertSame($before, $failed->fresh()->getAttributes());
        Http::assertNothingSent();
        Queue::assertPushed(ScheduleSolverDispatchJob::class, 1);
    }

    public function test_changed_technical_context_preserves_retained_candidate_and_publication_protections(): void
    {
        config()->set('tala_integrations.scheduling_solver.driver', 'local_http');
        config()->set('tala_integrations.scheduling_solver.url', 'http://127.0.0.1:9087');
        $recovery = app(ScheduleSolverRecoveryService::class);
        $fingerprint = $recovery->contextFingerprint();
        config()->set('tala_integrations.scheduling_solver.url', 'http://127.0.0.1:9088');
        foreach ([['candidate_key' => 'retained'], ['candidate_state' => 'Accepted'], ['published_at' => now()], ['status' => ScheduleGenerationRun::StatusPublished]] as $retained) {
            $run = ScheduleGenerationRun::factory()->create([
                'status' => ScheduleGenerationRun::StatusBlocked, 'candidate_key' => null, 'candidate_state' => null,
                'published_at' => null, 'published_by' => null, 'publication_version' => null,
                'diagnostics' => ['solver_dispatch' => ['technical_context_fingerprint' => $fingerprint],
                    'solver_result' => ['solver_status' => 'optimal', 'summary' => ['outcome' => 'TechnicalFailure']]],
                ...$retained,
            ]);

            $this->assertFalse($recovery->canRecover($run));
        }
    }

    public function test_credential_file_content_changes_establish_correction_but_metadata_only_changes_do_not(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('solver.json', 'SYNTHETIC-OLD-CREDENTIAL');
        $path = Storage::disk('local')->path('solver.json');
        config()->set('tala_integrations.scheduling_solver.driver', 'cloud_run');
        config()->set('tala_integrations.scheduling_solver.credentials_path', $path);
        $recovery = app(ScheduleSolverRecoveryService::class);
        $run = ScheduleGenerationRun::factory()->create([
            'status' => ScheduleGenerationRun::StatusFailed, 'candidate_key' => null, 'candidate_state' => null,
            'published_at' => null, 'published_by' => null, 'publication_version' => null,
            'diagnostics' => ['solver_dispatch' => ['technical_context_fingerprint' => $recovery->contextFingerprint(),
                'failure' => ['classification' => 'credential', 'retryable' => false, 'final' => true]]],
        ]);
        touch($path, now()->addHour()->timestamp);

        $this->assertFalse($recovery->canRecover($run));
        Storage::disk('local')->put('solver.json', 'SYNTHETIC-CORRECTED-CREDENTIAL');
        $this->assertTrue($recovery->canRecover($run));
    }

    private function dispatchRecoveryRun(ScheduleGenerationRun $run, SchedulingSolverClient $client): void
    {
        (new ScheduleSolverDispatchJob((int) $run->id))->handle(app(ScheduleSolverSnapshotService::class), $client, app(ScheduleCloudResultIngestor::class), app(ScheduleSolverDispatchLifecycleService::class));
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function recoveryPayload(array $snapshot): array
    {
        $payload = (new LocalStubSchedulingSolverClient)->solve(new SchedulingSolverRequest($snapshot, 'SYNTH-RECOVERY'))->payload();
        $payload['solver_version'] = ScheduleGenerationRun::SolverVersion;

        return $payload;
    }

    public function test_dispatch_builds_tal61_payload_uses_solver_client_and_persists_candidate_rows(): void
    {
        $source = $this->schedulingSource();
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $this->demandGenerator->forTerm($registrar, $source['term']);

        $client = new class implements SchedulingSolverClient
        {
            /**
             * @var list<array<string, mixed>>
             */
            public array $snapshots = [];

            public function solve(SchedulingSolverRequest $request): SchedulingSolverResponse
            {
                $this->snapshots[] = $request->snapshot();

                return (new LocalStubSchedulingSolverClient)->solve($request);
            }

            /**
             * @return array{status:int, body:string}
             */
            public function probe(): array
            {
                return ['status' => 200, 'body' => 'recording'];
            }
        };

        $run = $this->runService->generate($source['term'], $registrar);

        (new ScheduleSolverDispatchJob((int) $run->id))->handle(
            app(ScheduleSolverSnapshotService::class),
            $client,
            app(ScheduleCloudResultIngestor::class),
            app(ScheduleSolverDispatchLifecycleService::class),
        );

        $snapshot = $client->snapshots[0];
        $demandIds = SchedulingDemand::query()
            ->whereHas('termOffering', fn ($query) => $query->where('term_id', $source['term']->id))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame('tala-timetable-v2', $snapshot['contract_version']);
        $this->assertArrayHasKey('scheduling_demands', $snapshot);
        $this->assertArrayNotHasKey('curriculum_subject_demand', $snapshot);
        $this->assertSame($demandIds, collect($snapshot['scheduling_demands'])->pluck('scheduling_demand_id')->sort()->values()->all());
        $this->assertTrue(collect($snapshot['scheduling_demands'])->every(
            fn (array $demand): bool => $demand['source_snapshot']['meeting_pattern'] === $demand['meeting_pattern']
                && $demand['source_snapshot']['eligible_faculty_count'] === 1
        ));

        $run->refresh();

        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $run->status);
        $this->assertSame('local-stub-tala-timetable-v2', $run->solver_version);
        $this->assertSame(2, CandidateScheduleRow::query()->where('schedule_run_id', $run->id)->count());
        $this->assertSame(
            $demandIds,
            CandidateScheduleRow::query()->where('schedule_run_id', $run->id)->orderBy('scheduling_demand_id')->pluck('scheduling_demand_id')->all(),
        );
        $this->assertSame(
            [CandidateScheduleRow::StatusOk, CandidateScheduleRow::StatusOk],
            CandidateScheduleRow::query()->where('schedule_run_id', $run->id)->orderBy('id')->pluck('status')->all(),
        );

        $attemptEvent = OperationalEvent::query()
            ->where('related_record_type', ScheduleGenerationRun::class)
            ->where('related_record_id', $run->id)
            ->sole();

        $this->assertSame(OperationalEvent::DomainIntegration, $attemptEvent->event_domain);
        $this->assertSame(OperationalEvent::IntegrationSchedulingSolver, $attemptEvent->integration);
        $this->assertSame(OperationalEvent::StatusProcessed, $attemptEvent->status);
        $this->assertSame(1, data_get($attemptEvent->diagnostics, 'cycle'));
        $this->assertSame(1, data_get($attemptEvent->diagnostics, 'attempt'));
    }

    public function test_dispatch_blocks_when_any_term_demand_is_not_ready_for_review(): void
    {
        $source = $this->schedulingSource(withFaculty: false);
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $this->demandGenerator->forTerm($registrar, $source['term']);

        $this->expectException(ValidationException::class);

        try {
            $this->runService->generate($source['term'], $registrar);
        } finally {
            $this->assertSame(0, ScheduleGenerationRun::query()->whereBelongsTo($source['term'])->count());
            $this->assertSame(
                [SchedulingDemand::ValidationActionRequired],
                SchedulingDemand::query()
                    ->whereHas('termOffering', fn ($query) => $query->where('term_id', $source['term']->id))
                    ->pluck('validation_state')
                    ->unique()
                    ->values()
                    ->all(),
            );
        }
    }

    public function test_v2_snapshot_captures_profile_operating_grid_room_features_and_credit_load(): void
    {
        $source = $this->schedulingSource(withSecondComponent: false);
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $package = TermCalendarPackage::query()->whereBelongsTo($source['term'])->sole();
        $package->teachingGridRows()->delete();
        foreach ([1, 2, 4, 6] as $day) {
            TermTeachingGridRow::factory()->for($package, 'package')->create([
                'day_of_week' => $day, 'starts_at' => '08:00:00', 'ends_at' => '18:00:00', 'breaks' => [],
            ]);
        }
        $source['lecture']->update([
            'required_room_feature_keys' => ['PROJECTOR'],
        ]);

        $room = Room::query()->where('room_type', Room::TypeLectureRoom)->firstOrFail();
        RoomFeature::factory()->for($room)->create(['feature_key' => 'PROJECTOR']);

        $this->demandGenerator->forTerm($registrar, $source['term']);
        $run = $this->runService->generate($source['term'], $registrar);
        $snapshot = $this->arrayAttribute($run, 'input_snapshot');

        $this->assertSame('tala-timetable-v2', $snapshot['contract_version']);
        $this->assertSame('lexicographic_v1', $snapshot['constraint_profile']['key']);
        $this->assertSame(1, $snapshot['constraint_profile']['version']);
        $this->assertSame([
            'cohort_mode_switches',
            'cohort_idle_time',
            'faculty_load_imbalance',
            'faculty_idle_time',
            'room_seat_waste',
            'stable_earlier_placement',
        ], $snapshot['constraint_profile']['objective_hierarchy']);
        $this->assertArrayNotHasKey('soft_weights', $snapshot['constraint_profile']);
        $this->assertSame([1, 2, 4, 6], $snapshot['term']['scheduling_days']);
        $this->assertSame('08:00:00', $snapshot['term']['scheduling_day_starts_at']);
        $this->assertSame('18:00:00', $snapshot['term']['scheduling_day_ends_at']);
        $this->assertSame([1, 2, 4, 6], collect($snapshot['time_slots'])->pluck('day_of_week')->unique()->values()->all());
        $this->assertSame(['PROJECTOR'], $snapshot['scheduling_demands'][0]['required_room_feature_keys']);
        $this->assertSame('3.00', $snapshot['scheduling_demands'][0]['load_units']);
        $this->assertContains('PROJECTOR', collect($snapshot['rooms'])->firstWhere('room_id', $room->id)['feature_keys']);
    }

    public function test_snapshot_emits_only_deterministic_recurring_calendar_blocks(): void
    {
        $source = $this->schedulingSource(withSecondComponent: false);
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $this->demandGenerator->forTerm($registrar, $source['term']);

        $demand = SchedulingDemand::query()
            ->whereHas('termOffering', fn ($query) => $query->where('term_id', $source['term']->id))
            ->sole();
        $sourceSnapshot = $this->arrayAttribute($demand, 'source_snapshot');

        $facultyLoadOptions = $sourceSnapshot['faculty_load_options'] ?? null;
        $this->assertIsArray($facultyLoadOptions);
        $this->assertIsArray($facultyLoadOptions[0] ?? null);

        $facultyId = (int) $facultyLoadOptions[0]['faculty_user_id'];
        $room = Room::query()->firstOrFail();

        CalendarEvent::factory()->for($source['term'])->create([
            'event_type' => CalendarEvent::TypeHoliday,
            'scope_type' => CalendarEvent::ScopeInstitution,
            'process_key' => CalendarEvent::ProcessMasterSchedule,
            'start_at' => now()->addMonth()->setTime(8, 0),
            'end_at' => now()->addMonth()->setTime(17, 0),
            'day_of_week' => null,
            'starts_at' => null,
            'ends_at' => null,
            'blocks_scheduling' => true,
            'state' => CalendarEvent::StateActive,
        ]);

        $facultyBlock = CalendarEvent::factory()->for($source['term'])->create([
            'event_type' => CalendarEvent::TypeUnavailable,
            'scope_type' => CalendarEvent::ScopeFaculty,
            'faculty_user_id' => $facultyId,
            'process_key' => CalendarEvent::ProcessMasterSchedule,
            'start_at' => null,
            'end_at' => null,
            'day_of_week' => 2,
            'starts_at' => '10:00:00',
            'ends_at' => '12:00:00',
            'blocks_scheduling' => true,
            'state' => CalendarEvent::StateActive,
            'authority' => 'Faculty',
        ]);
        $institutionBlock = CalendarEvent::factory()->for($source['term'])->create([
            'event_type' => CalendarEvent::TypeBreak,
            'scope_type' => CalendarEvent::ScopeInstitution,
            'process_key' => CalendarEvent::ProcessMasterSchedule,
            'start_at' => null,
            'end_at' => null,
            'day_of_week' => 1,
            'starts_at' => '12:00:00',
            'ends_at' => '13:00:00',
            'blocks_scheduling' => true,
            'state' => CalendarEvent::StateActive,
            'authority' => 'Academic Head',
        ]);
        $roomBlock = CalendarEvent::factory()->for($source['term'])->create([
            'event_type' => CalendarEvent::TypeUnavailable,
            'scope_type' => CalendarEvent::ScopeRoom,
            'room_id' => $room->id,
            'process_key' => CalendarEvent::ProcessMasterSchedule,
            'start_at' => null,
            'end_at' => null,
            'day_of_week' => 2,
            'starts_at' => '10:00:00',
            'ends_at' => '12:00:00',
            'blocks_scheduling' => true,
            'state' => CalendarEvent::StateActive,
            'authority' => 'Registrar',
        ]);

        $run = $this->runService->generate($source['term'], $registrar);
        $snapshot = $this->arrayAttribute($run, 'input_snapshot');

        $calendarBlocks = $snapshot['calendar_blocks'] ?? null;
        $this->assertIsArray($calendarBlocks);

        $this->assertSame([], $snapshot['faculty_availability']);
        $this->assertSame(
            [$institutionBlock->id, $facultyBlock->id, $roomBlock->id],
            collect($calendarBlocks)->pluck('calendar_event_id')->all(),
        );
        $this->assertEquals([
            'calendar_event_id' => $facultyBlock->id,
            'event_type' => CalendarEvent::TypeUnavailable,
            'scope_type' => CalendarEvent::ScopeFaculty,
            'room_id' => null,
            'faculty_user_id' => $facultyId,
            'authority' => 'Faculty',
            'day_of_week' => 2,
            'starts_at' => '10:00:00',
            'ends_at' => '12:00:00',
        ], $calendarBlocks[1]);
        $this->assertFalse(collect($calendarBlocks)->contains(
            fn (array $block): bool => $block['event_type'] === CalendarEvent::TypeHoliday,
        ));
    }

    public function test_old_solver_rows_without_scheduling_demand_ids_are_rejected_and_block_the_run(): void
    {
        $source = $this->schedulingSource(withSecondComponent: false);
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $this->demandGenerator->forTerm($registrar, $source['term']);

        $client = new class implements SchedulingSolverClient
        {
            public function solve(SchedulingSolverRequest $request): SchedulingSolverResponse
            {
                $snapshot = $request->snapshot();
                $demand = $snapshot['scheduling_demands'][0];

                return new SchedulingSolverResponse([
                    'solver_status' => 'optimal',
                    'assigned_count' => 1,
                    'unassigned_count' => 0,
                    'hard_violation_count' => 0,
                    'warning_count' => 0,
                    'timeout' => false,
                    'draft_rows' => [[
                        'section_id' => $demand['section_id'],
                        'section_delivery_group_id' => $demand['section_delivery_group_id'],
                        'subject_id' => $demand['course_id'],
                        'faculty_id' => $demand['eligible_faculty_user_ids'][0],
                        'day_of_week' => 1,
                        'starts_at' => '07:00:00',
                        'ends_at' => '10:00:00',
                        'status' => 'ok',
                    ]],
                ], $request->requestId(), [
                    'authentication_ms' => 0,
                    'transport_ms' => 0,
                    'decode_ms' => 0,
                ]);
            }

            /**
             * @return array{status:int, body:string}
             */
            public function probe(): array
            {
                return ['status' => 200, 'body' => 'old'];
            }
        };

        $run = $this->runService->generate($source['term'], $registrar);

        (new ScheduleSolverDispatchJob((int) $run->id))->handle(
            app(ScheduleSolverSnapshotService::class),
            $client,
            app(ScheduleCloudResultIngestor::class),
            app(ScheduleSolverDispatchLifecycleService::class),
        );

        $run->refresh();

        $this->assertSame(ScheduleGenerationRun::StatusBlocked, $run->status);
        $this->assertSame(0, CandidateScheduleRow::query()->where('schedule_run_id', $run->id)->count());
        $diagnostics = $run->getAttribute('diagnostics');

        $this->assertIsArray($diagnostics);
        $this->assertSame(
            'legacy_draft_rows_not_allowed',
            $diagnostics['solver_result']['summary']['rejected_rows'][0]['reason'],
        );
    }

    public function test_tal63_assignment_response_shape_persists_candidate_rows_without_missing_identifier_rejection(): void
    {
        $source = $this->schedulingSource(withSecondComponent: false);
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $this->demandGenerator->forTerm($registrar, $source['term']);

        $run = $this->runService->generate($source['term'], $registrar);
        $snapshot = $run->getAttribute('input_snapshot');
        $demand = $snapshot['scheduling_demands'][0];
        $room = Room::query()->findOrFail((int) $snapshot['rooms'][0]['room_id']);

        app(ScheduleCloudResultIngestor::class)->ingest($run, [
            'solver_run_id' => $run->id,
            'solver_status' => 'optimal',
            'candidate_schedule_id' => 'tal63-cloud-run-smoke',
            'assignments' => [[
                'scheduling_demand_id' => $demand['scheduling_demand_id'],
                'term_offering_id' => $demand['term_offering_id'],
                'section_id' => $demand['section_id'],
                'section_delivery_group_id' => $demand['section_delivery_group_id'],
                'subject_id' => $demand['course_id'],
                'course_component_id' => $demand['course_component_id'],
                'faculty_id' => $demand['eligible_faculty_user_ids'][0],
                'faculty_user_id' => $demand['eligible_faculty_user_ids'][0],
                'room_id' => $room->id,
                'day' => 1,
                'day_of_week' => 1,
                'start_time' => '07:00:00',
                'end_time' => '10:00:00',
                'starts_at' => '07:00:00',
                'ends_at' => '10:00:00',
                'time_slot_id' => 1,
                'time_block_reference' => 'D1-0700',
                'time_block_key' => 'D1-0700',
                'meeting_sequence' => 1,
                'meeting_pattern' => 'single_block',
                'assignment_status' => 'ok',
                'violations' => [],
                'warnings' => [],
                'scores' => [],
                'soft_constraint_scores' => [],
            ]],
            'hard_constraint_violations' => [],
            'hard_violation_count' => 0,
            'soft_constraint_scores' => [
                'objective' => 0,
            ],
            'infeasible_reasons' => [],
            'warnings' => [],
            'runtime_seconds' => 0.21,
            'objective_score' => null,
            'objective_details' => [
                'profile_key' => 'lexicographic_v1',
                'profile_version' => 1,
                'objective_hierarchy' => $snapshot['constraint_profile']['objective_hierarchy'],
                'values' => array_fill_keys($snapshot['constraint_profile']['objective_hierarchy'], null),
                'completed_levels' => [],
                'scalar_score' => null,
                'verification_only' => true,
            ],
            'solver_statistics' => [
                'ortools_version' => '9.15.6755',
                'input_demand_count' => 1,
                'input_faculty_count' => count($snapshot['faculty']),
                'input_room_count' => count($snapshot['rooms']),
                'input_time_slot_count' => count($snapshot['time_slots']),
                'candidate_count' => 1,
                'model_variable_count' => 1,
                'model_constraint_count' => 1,
                'no_overlap_constraint_count' => 0,
                'best_objective_bound' => 0.0,
                'relative_optimality_gap' => 0.0,
                'boolean_variable_count' => 1,
                'branch_count' => 0,
                'conflict_count' => 0,
                'deterministic_time_seconds' => 0.01,
                'wall_time_seconds' => 0.02,
                'worker_count' => 1,
                'random_seed' => 20260718,
                'result_source' => 'lexicographic',
                'search_stages' => [
                    'feasibility' => [
                        'status' => 'optimal',
                        'model_variable_count' => 1,
                        'model_constraint_count' => 1,
                        'no_overlap_constraint_count' => 0,
                        'boolean_variable_count' => 1,
                        'branch_count' => 0,
                        'conflict_count' => 0,
                        'deterministic_time_seconds' => 0.005,
                        'wall_time_seconds' => 0.01,
                    ],
                    'optimization' => [
                        'status' => 'optimal',
                        'model_variable_count' => 1,
                        'model_constraint_count' => 1,
                        'no_overlap_constraint_count' => 0,
                        'boolean_variable_count' => 1,
                        'branch_count' => 0,
                        'conflict_count' => 0,
                        'deterministic_time_seconds' => 0.005,
                        'wall_time_seconds' => 0.01,
                    ],
                ],
            ],
            'solver_version' => 'local-stub-tala-timetable-v2',
            'model_version' => 'tala-timetable-v2',
            'generated_at' => now()->toIso8601String(),
            'assigned_count' => 1,
            'unassigned_count' => 0,
            'warning_count' => 0,
            'timeout' => false,
        ]);

        $run->refresh();
        $diagnostics = $run->getAttribute('diagnostics');

        $this->assertSame(
            ScheduleGenerationRun::StatusUnderReview,
            $run->status,
            json_encode($diagnostics['solver_result']['findings'] ?? [], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        $candidate = CandidateScheduleRow::query()
            ->where('schedule_run_id', $run->id)
            ->firstOrFail();

        $this->assertSame('local-stub-tala-timetable-v2', $run->solver_version);
        $this->assertSame((int) $demand['scheduling_demand_id'], $candidate->scheduling_demand_id);
        $this->assertSame(CandidateScheduleRow::StatusOk, $candidate->status);
        $this->assertSame(0, $diagnostics['solver_result']['summary']['rejected_count']);
        $this->assertSame([], $diagnostics['solver_result']['summary']['rejected_rows']);
    }

    public function test_authorization_boundaries_and_admin_panel_registration_are_enforced(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $systemSuperAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $faculty = $this->staff(User::StaffRoleFaculty);

        $this->assertTrue(Gate::forUser($registrar)->allows('viewAny', ScheduleGenerationRun::class));
        $this->assertTrue(Gate::forUser($academicHead)->allows('viewAny', ScheduleGenerationRun::class));
        $this->assertFalse(Gate::forUser($systemSuperAdmin)->allows('viewAny', ScheduleGenerationRun::class));
        $this->assertFalse(Gate::forUser($systemSuperAdmin)->allows('create', ScheduleGenerationRun::class));
        $this->assertFalse(Gate::forUser($academicHead)->allows('create', ScheduleGenerationRun::class));
        $this->assertFalse(Gate::forUser($faculty)->allows('viewAny', ScheduleGenerationRun::class));
        $this->assertTrue(Route::has(ListScheduleGenerationRuns::getRouteName()));

        Livewire::actingAs($registrar)
            ->test(ListScheduleGenerationRuns::class)
            ->assertOk();

        Livewire::actingAs($academicHead)
            ->test(ListScheduleGenerationRuns::class)
            ->assertOk();

        $this->actingAs($faculty)
            ->get(ScheduleGenerationRunResource::getUrl())
            ->assertForbidden();
    }

    public function test_solver_run_list_polls_and_explains_queued_progress(): void
    {
        $source = $this->schedulingSource();
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $this->demandGenerator->forTerm($registrar, $source['term']);

        $component = Livewire::actingAs($registrar)
            ->test(ListScheduleGenerationRuns::class);

        $page = $component->instance();
        $this->assertInstanceOf(ListScheduleGenerationRuns::class, $page);
        $this->assertSame('5s', $page->getTable()->getPollingInterval());

        Livewire::actingAs($registrar)
            ->test(TermTimetable::class, ['termId' => $source['term']->id])
            ->callAction('generateTimetable')
            ->assertHasNoActionErrors();

        $run = ScheduleGenerationRun::query()->whereBelongsTo($source['term'])->sole();

        $component->assertNotified(
            Notification::make()
                ->title('Timetable generation requested')
                ->body("Request #{$run->id} captured the current ready requirements. Its status refreshes automatically.")
                ->success(),
        );
    }

    public function test_solver_run_list_does_not_sort_large_snapshot_payloads(): void
    {
        $source = $this->schedulingSource();
        $registrar = $this->staff(User::StaffRoleRegistrar);

        ScheduleGenerationRun::query()->create([
            'term_id' => $source['term']->id,
            'status' => ScheduleGenerationRun::StatusBlocked,
            'requested_by' => $registrar->id,
            'input_snapshot' => ['payload' => str_repeat('x', 1_000_000)],
            'input_hash' => hash('sha256', 'tal96d5b-large-snapshot'),
            'solver_version' => 'tal96d5b-test',
            'model_version' => 'tal96d5b-test',
            'diagnostics' => ['detail' => str_repeat('y', 1_000_000)],
        ]);

        $this->actingAs($registrar);

        $component = Livewire::test(ListScheduleGenerationRuns::class);

        $component->assertOk();
        $component->assertCanSeeTableRecords(ScheduleGenerationRun::query()->get());
    }

    /**
     * @return array{
     *     term: Term,
     *     course: Course,
     *     specification: CourseSpecification,
     *     lecture: CourseComponent,
     *     laboratory: CourseComponent|null,
     *     offering: TermOffering,
     *     section: Section,
     *     group: SectionDeliveryGroup
     * }
     */
    private function schedulingSource(
        bool $withSecondComponent = true,
        bool $withFaculty = true,
    ): array {
        $term = Term::factory()->create([
            'type' => Term::TypeFirstSemester,
            'label' => 'First Semester '.$this->scopeCounter,
            'state' => Term::StateActive,
            'default_max_units' => 21.00,
        ]);

        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);
        foreach ([1, 2, 3, 4, 5] as $day) {
            TermTeachingGridRow::factory()->for($package, 'package')->create([
                'day_of_week' => $day,
                'starts_at' => '07:00:00',
                'ends_at' => '18:00:00',
                'breaks' => [],
            ]);
        }

        $program = Program::factory()->create(['code' => 'BS'.++$this->scopeCounter]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['code' => 'IT'.str_pad((string) $this->scopeCounter, 3, '0', STR_PAD_LEFT)]);
        $specification = CourseSpecification::factory()->for($course)->create([
            'title' => 'Scheduling Systems',
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace, TermOffering::ModalityOnline],
            'same_faculty_default' => true,
        ]);
        $lecture = CourseComponent::factory()->for($specification)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 3.00,
            'room_type_default' => Room::TypeLectureRoom,
            'sequence' => 1,
        ]);
        $laboratory = $withSecondComponent
            ? CourseComponent::factory()->for($specification)->create([
                'component_type' => CourseComponent::TypeLaboratory,
                'weekly_contact_hours' => 2.00,
                'room_type_default' => Room::TypeLaboratory,
                'sequence' => 2,
            ])
            : null;
        $entry = CurriculumEntry::factory()->for($curriculum)->for($specification, 'courseSpecification')->create([
            'year_level' => 'First Year',
            'term_type' => $term->type,
            'sequence' => 1,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'expected_count' => 30,
            'state' => TermOffering::StatePendingScheduling,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'BSIT-1A-'.$this->scopeCounter,
            'capacity' => 30,
            'state' => Section::StatePlanned,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'Regular Cohort '.$this->scopeCounter,
            'expected_count' => 30,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);

        $preparer = $this->staff(User::StaffRoleRegistrar);
        $cohort = app(RecordTermCohort::class)->execute($term, $preparer, [
            'curriculum_version_id' => $curriculum->id,
            'reference' => 'DISPATCH-COHORT-'.$this->scopeCounter,
            'confirmed_count' => 30,
        ]);
        $prepared = app(PrepareClassOffering::class)->execute($term, $section, $preparer, [
            'class_reference' => 'DISPATCH-CLASS-'.$this->scopeCounter,
            'source' => Section::SourceRegular,
            'delivery_mode' => TermOffering::ModalityFaceToFace,
        ]);
        app(ConfirmClassOffering::class)->execute($prepared, $preparer, [$cohort->id => 30]);

        if ($withFaculty) {
            $faculty = $this->staff(User::StaffRoleFaculty);

            FacultyQualification::factory()
                ->for($faculty, 'faculty')
                ->for($course)
                ->create(['is_active' => true]);

            FacultyTermLoadOverride::factory()
                ->for($faculty, 'faculty')
                ->for($term)
                ->create([
                    'default_max_units_snapshot' => 21.00,
                    'approved_overload_units' => 3.00,
                    'is_active' => true,
                ]);
        }

        Room::factory()->create([
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);

        if ($withSecondComponent) {
            Room::factory()->create([
                'room_type' => Room::TypeLaboratory,
                'capacity' => 40,
                'is_active' => true,
            ]);
        }

        return [
            'term' => $term,
            'course' => $course,
            'specification' => $specification,
            'lecture' => $lecture,
            'laboratory' => $laboratory,
            'offering' => $offering,
            'section' => $section,
            'group' => $group,
        ];
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);
        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $user->saveAppAuthenticationRecoveryCodes(['stored-code']);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayAttribute(Model $model, string $key): array
    {
        $value = $model->getAttribute($key);

        $this->assertIsArray($value);

        return $value;
    }
}
