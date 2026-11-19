<?php

namespace Tests\Feature;

use App\Filament\Pages\CatalogCurriculaWorkbench;
use App\Filament\Pages\TermClasses;
use App\Filament\Pages\TermTimetable;
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
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
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
    public function legacy_class_planning_and_academic_readiness_redirect_pages_are_retired(): void
    {
        $this->assertFalse(Route::has('filament.admin.pages.class-planning'));
        $this->assertFalse(Route::has('filament.admin.pages.academic-readiness'));
        $this->assertFileDoesNotExist(app_path('Actions/Scheduling/ClassPlanningWorkflow.php'));
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
    public function academic_head_can_review_term_planning_workbench_but_system_admin_cannot_open_it(): void
    {
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $systemAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $term = Term::factory()->create(['state' => Term::StateActive]);

        $this->actingAs($academicHead)
            ->get(TermClasses::getUrl(['termId' => $term->id]))
            ->assertOk();

        $this->actingAs($academicHead)
            ->get(CatalogCurriculaWorkbench::getUrl())
            ->assertOk();

        $this->actingAs($systemAdmin)
            ->get(TermClasses::getUrl(['termId' => $term->id]))
            ->assertForbidden();
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
