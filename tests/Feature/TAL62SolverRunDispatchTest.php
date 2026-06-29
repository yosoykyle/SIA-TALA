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
use App\Models\Program;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
        $demandIds = SchedulingDemand::query()->orderBy('id')->pluck('id')->all();

        $this->assertSame('tal61-demand-v1', $snapshot['contract_version']);
        $this->assertArrayHasKey('scheduling_demands', $snapshot);
        $this->assertArrayNotHasKey('curriculum_subject_demand', $snapshot);
        $this->assertSame($demandIds, collect($snapshot['scheduling_demands'])->pluck('scheduling_demand_id')->sort()->values()->all());
        $this->assertTrue(collect($snapshot['scheduling_demands'])->every(
            fn (array $demand): bool => $demand['source_snapshot']['active_scheduling_window_count'] === 1
        ));

        $run->refresh();

        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $run->status);
        $this->assertSame('local-stub-tal61-demand-v1', $run->solver_version);
        $this->assertSame(2, CandidateScheduleRow::query()->where('schedule_run_id', $run->id)->count());
        $this->assertSame(
            $demandIds,
            CandidateScheduleRow::query()->where('schedule_run_id', $run->id)->orderBy('scheduling_demand_id')->pluck('scheduling_demand_id')->all(),
        );
        $this->assertSame(
            [CandidateScheduleRow::StatusOk, CandidateScheduleRow::StatusOk],
            CandidateScheduleRow::query()->where('schedule_run_id', $run->id)->orderBy('id')->pluck('status')->all(),
        );
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
            $this->assertSame(0, ScheduleGenerationRun::query()->count());
            $this->assertSame([SchedulingDemand::ValidationActionRequired], SchedulingDemand::query()->pluck('validation_state')->unique()->values()->all());
        }
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
            'missing_scheduling_demand_identifier',
            $diagnostics['solver_result']['summary']['rejected_rows'][0]['reason'],
        );
    }

    public function test_authorization_boundaries_and_admin_panel_registration_are_enforced(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $systemSuperAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $faculty = $this->staff(User::StaffRoleFaculty);

        $this->assertTrue(Gate::forUser($registrar)->allows('viewAny', ScheduleGenerationRun::class));
        $this->assertTrue(Gate::forUser($academicHead)->allows('viewAny', ScheduleGenerationRun::class));
        $this->assertTrue(Gate::forUser($systemSuperAdmin)->allows('create', ScheduleGenerationRun::class));
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
}
