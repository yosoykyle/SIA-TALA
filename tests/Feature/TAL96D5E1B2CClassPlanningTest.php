<?php

namespace Tests\Feature;

use App\Actions\Scheduling\ClassPlanningWorkflow;
use App\Actions\Scheduling\TermSchedulingReadinessService;
use App\Filament\Pages\AcademicReadiness;
use App\Filament\Pages\ClassPlanning;
use App\Filament\Pages\TermPlanningWorkbench;
use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Filament\Resources\ScheduleGenerationRuns\Pages\ListScheduleGenerationRuns;
use App\Filament\Resources\SchedulingDemands\Pages\ListSchedulingDemands;
use App\Filament\Resources\SectionMeetings\Pages\ListSectionMeetings;
use App\Filament\Resources\TermOfferings\TermOfferingResource;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL96D5E1B2CClassPlanningTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

        foreach ([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
            User::StaffRoleSystemSuperAdmin,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function legacy_class_planning_redirects_to_the_owning_term_workbench(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        Term::factory()->create([
            'label' => 'Planning Test Term',
            'state' => Term::StateActive,
        ]);

        $this->actingAs($registrar);

        Livewire::test(ClassPlanning::class)
            ->assertRedirect(TermPlanningWorkbench::getUrl(['viewTab' => 'classes']));

        $this->assertSame('Class Planning', ClassPlanning::getNavigationLabel());
        $this->assertFalse(ClassPlanning::shouldRegisterNavigation());
    }

    #[Test]
    public function academic_readiness_remains_an_accessible_source(): void
    {
        $this->actingAs($this->staff(User::StaffRoleRegistrar));

        Livewire::test(AcademicReadiness::class)
            ->assertSuccessful();
    }

    #[Test]
    public function workflow_presenter_reports_truthful_blockers_without_invoking_the_solver(): void
    {
        $term = Term::factory()->create([
            'label' => 'Incomplete Planning Term',
            'scheduling_days' => [],
            'state' => Term::StateDraft,
        ]);

        $summary = app(ClassPlanningWorkflow::class)->present($term);

        $this->assertFalse($summary['is_ready']);
        $this->assertCount(6, $summary['stages']);
        $this->assertSame('Prerequisites', $summary['stages'][0]['title']);
        $this->assertSame('Blocked', $summary['stages'][0]['status']);
        $this->assertSame('Complete academic and term setup', $summary['stages'][0]['action_label']);
        $this->assertSame(0, $summary['counts']['schedule_runs']);
        $this->assertSame(0, $summary['counts']['published_meetings']);
    }

    #[Test]
    public function scheduling_authority_keeps_registrar_management_academic_head_read_only_and_system_admin_denied(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $systemAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $offering = TermOffering::factory()->create();

        $this->assertTrue(Gate::forUser($registrar)->allows('viewAny', TermOffering::class));
        $this->assertTrue(Gate::forUser($registrar)->allows('create', TermOffering::class));
        $this->assertTrue(Gate::forUser($registrar)->allows('update', $offering));

        $this->assertTrue(Gate::forUser($academicHead)->allows('viewAny', TermOffering::class));
        $this->assertTrue(Gate::forUser($academicHead)->allows('view', $offering));
        $this->assertFalse(Gate::forUser($academicHead)->allows('create', TermOffering::class));
        $this->assertFalse(Gate::forUser($academicHead)->allows('update', $offering));

        $this->assertFalse(Gate::forUser($systemAdmin)->allows('viewAny', TermOffering::class));
        $this->assertFalse(Gate::forUser($systemAdmin)->allows('view', $offering));
        $this->assertFalse(Gate::forUser($systemAdmin)->allows('create', TermOffering::class));
        $this->assertFalse(Gate::forUser($systemAdmin)->allows('update', $offering));
    }

    #[Test]
    public function academic_head_can_review_class_planning_but_system_admin_cannot_open_it(): void
    {
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $systemAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);

        Livewire::actingAs($academicHead)
            ->test(ClassPlanning::class)
            ->assertRedirect(TermPlanningWorkbench::getUrl(['viewTab' => 'classes']));

        $this->actingAs($academicHead)
            ->get(ClassPlanning::getUrl())
            ->assertRedirect(TermPlanningWorkbench::getUrl(['viewTab' => 'classes']));

        $this->actingAs($systemAdmin)
            ->get(ClassPlanning::getUrl())
            ->assertForbidden();
    }

    #[Test]
    public function teaching_resources_action_opens_the_source_that_owns_the_current_blocker(): void
    {
        $term = Term::factory()->create([
            'label' => 'Faculty Blocker Term',
            'state' => Term::StateActive,
        ]);
        $readiness = Mockery::mock(TermSchedulingReadinessService::class);
        $readiness->shouldReceive('evaluateTerm')
            ->once()
            ->with($term)
            ->andReturn([
                'is_ready' => false,
                'missing_term_fields' => [],
                'section_issues' => [],
                'delivery_group_issues' => [],
                'faculty_input_issues' => [[
                    'missing_inputs' => ['active_faculty_qualification'],
                ]],
                'room_input_issues' => [],
                'room_catalog_mode' => 'test',
            ]);
        $this->app->instance(TermSchedulingReadinessService::class, $readiness);

        $summary = app(ClassPlanningWorkflow::class)->present($term);
        $teachingResources = $summary['stages'][2];

        $this->assertSame('Review faculty qualifications', $teachingResources['action_label']);
        $this->assertSame(FacultyQualificationResource::getUrl('index'), $teachingResources['action_url']);
    }

    #[Test]
    public function source_records_remain_routable_while_plain_language_scheduling_views_are_exposed(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();
        $this->actingAs($registrar);

        $this->assertArrayHasKey('index', TermOfferingResource::getPages());

        Livewire::test(ListSchedulingDemands::class, ['termId' => $term->id])
            ->assertSet('termId', $term->id)
            ->assertSee('Schedule requirements')
            ->assertTableColumnExists('course_and_section')
            ->assertTableColumnExists('requirement_summary')
            ->assertTableColumnExists('validation_state');

        Livewire::test(ListScheduleGenerationRuns::class, ['termId' => $term->id])
            ->assertSet('termId', $term->id)
            ->assertSee('Timetable generation history')
            ->assertActionHasUrl('dispatchSolverRun', TermTimetable::getUrl(['termId' => $term->id]))
            ->assertTableColumnExists('result_summary')
            ->assertTableColumnExists('assignment_summary');

        Livewire::test(ListSectionMeetings::class, ['termId' => $term->id])
            ->assertSet('termId', $term->id)
            ->assertTableColumnExists('course_and_section')
            ->assertTableColumnExists('meeting_time')
            ->assertSee('Official meetings');
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $user->assignRole($role);
        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $user->saveAppAuthenticationRecoveryCodes(['stored-code']);

        return $user;
    }
}
