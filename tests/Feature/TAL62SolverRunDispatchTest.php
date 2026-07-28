<?php

namespace Tests\Feature;

use App\Actions\Integrations\SchedulingSolver\LocalStubSchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverClient;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\ScheduleCloudResultIngestor;
use App\Actions\Scheduling\ScheduleGenerationService;
use App\Actions\Scheduling\ScheduleSolverSnapshotService;
use App\Filament\Resources\ScheduleGenerationRuns\Pages\ListScheduleGenerationRuns;
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
use App\Models\TermOffering;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
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
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());

        config()->set('tala_integrations.scheduling_solver.driver', 'local_stub');
        $this->app->forgetInstance(SchedulingSolverClient::class);
        Queue::fake();

        $this->demandGenerator = app(GenerateSchedulingDemand::class);
        $this->runService = app(ScheduleGenerationService::class);

        foreach ([User::StaffRoleRegistrar, User::StaffRoleAcademicHead, User::StaffRoleSystemSuperAdmin, User::StaffRoleFaculty] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
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

            /**
             * @param  array<string, mixed>  $snapshot
             * @return array<string, mixed>
             */
            public function solve(array $snapshot): array
            {
                $this->snapshots[] = $snapshot;

                return (new LocalStubSchedulingSolverClient)->solve($snapshot);
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
        );

        $snapshot = $client->snapshots[0];
        $demandIds = SchedulingDemand::query()
            ->whereHas('termOffering', fn ($query) => $query->where('term_id', $source['term']->id))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame('tal94-demand-v2', $snapshot['contract_version']);
        $this->assertArrayHasKey('scheduling_demands', $snapshot);
        $this->assertArrayNotHasKey('curriculum_subject_demand', $snapshot);
        $this->assertSame($demandIds, collect($snapshot['scheduling_demands'])->pluck('scheduling_demand_id')->sort()->values()->all());
        $this->assertTrue(collect($snapshot['scheduling_demands'])->every(
            fn (array $demand): bool => $demand['source_snapshot']['active_scheduling_window_count'] === 1
        ));

        $run->refresh();

        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $run->status);
        $this->assertSame('local-stub-tal94-demand-v2', $run->solver_version);
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

        $source['term']->update([
            'scheduling_days' => [1, 2, 4, 6],
            'scheduling_day_starts_at' => '08:00:00',
            'scheduling_day_ends_at' => '18:00:00',
        ]);
        $source['lecture']->update([
            'required_room_feature_keys' => ['PROJECTOR'],
        ]);

        $room = Room::query()->where('room_type', Room::TypeLectureRoom)->firstOrFail();
        RoomFeature::factory()->for($room)->create(['feature_key' => 'PROJECTOR']);

        $this->demandGenerator->forTerm($registrar, $source['term']);
        $run = $this->runService->generate($source['term'], $registrar);
        $snapshot = $this->arrayAttribute($run, 'input_snapshot');

        $this->assertSame('tal94-demand-v2', $snapshot['contract_version']);
        $this->assertSame('balanced_v1', $snapshot['constraint_profile']['key']);
        $this->assertSame(1, $snapshot['constraint_profile']['version']);
        $this->assertEquals([
            'prefer_earlier_time_blocks' => 1,
            'reduce_faculty_idle_gaps' => 1,
            'balance_faculty_load' => 1,
            'use_rooms_efficiently' => 1,
        ], $snapshot['constraint_profile']['soft_weights']);
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
            /**
             * @param  array<string, mixed>  $snapshot
             * @return array<string, mixed>
             */
            public function solve(array $snapshot): array
            {
                $demand = $snapshot['scheduling_demands'][0];

                return [
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
                ];
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
            'objective_score' => 0,
            'objective_details' => [
                'profile_key' => 'balanced_v1',
                'profile_version' => 1,
                'terms' => [
                    'prefer_earlier_time_blocks' => ['raw' => 0, 'weight' => 1, 'weighted' => 0],
                    'reduce_faculty_idle_gaps' => ['raw' => 0, 'weight' => 1, 'weighted' => 0],
                    'balance_faculty_load' => ['raw' => 0, 'weight' => 1, 'weighted' => 0],
                    'use_rooms_efficiently' => ['raw' => 0, 'weight' => 1, 'weighted' => 0],
                ],
                'total' => 0,
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
                'result_source' => 'optimization',
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
            'solver_version' => 'cloud-run-tal63',
            'model_version' => 'tal94-demand-v2',
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

        $this->assertSame('cloud-run-tal63', $run->solver_version);
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
        $this->assertTrue(Route::has('filament.admin.resources.schedule-generation-runs.index'));

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

        $component->callAction('dispatchSolverRun', data: ['term_id' => $source['term']->id]);

        $run = ScheduleGenerationRun::query()->whereBelongsTo($source['term'])->sole();

        $component->assertNotified(
            Notification::make()
                ->title('Solver run queued')
                ->body("Run #{$run->id} captured READY_FOR_REVIEW demand rows for dispatch. Status refreshes automatically every five seconds.")
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

        CalendarEvent::factory()->for($term)->create([
            'event_type' => CalendarEvent::TypeWindow,
            'process_key' => 'scheduling',
            'start_at' => now()->addWeek(),
            'end_at' => now()->addWeeks(2),
            'state' => CalendarEvent::StateActive,
        ]);

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
