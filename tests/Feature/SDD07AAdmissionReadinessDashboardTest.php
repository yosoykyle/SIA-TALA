<?php

namespace Tests\Feature;

use App\Actions\Enrollment\AdmissionReadinessDashboardService;
use App\Actions\Scheduling\TermSchedulingReadinessService;
use App\Filament\Pages\AdmissionReadinessDashboard;
use App\Models\AdmissionCapacityPlan;
use App\Models\AdmissionOffering;
use App\Models\AdmissionRequirementPolicy;
use App\Models\DocumentRequirementItem;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SDD07AAdmissionReadinessDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (User::staffRoleNames() as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_registrar_can_open_admission_readiness_dashboard(): void
    {
        $registrar = $this->staffUser(User::StaffRoleRegistrar, ['manage-admission-setup']);
        Term::factory()->create(['term_name' => 'SY 2026-2027 First Semester']);

        $this->actingAs($registrar)
            ->get(AdmissionReadinessDashboard::getUrl())
            ->assertOk()
            ->assertSee('Admission Readiness')
            ->assertSee('SY 2026-2027 First Semester');
    }

    public function test_ordinary_staff_cannot_open_admission_readiness_dashboard(): void
    {
        $staff = $this->staffUser(User::StaffRoleFaculty, []);

        $this->actingAs($staff)
            ->get(AdmissionReadinessDashboard::getUrl())
            ->assertForbidden();
    }

    public function test_dashboard_service_reports_blockers_for_incomplete_admission_setup(): void
    {
        $term = Term::factory()->create([
            'enrollment_starts_at' => now()->subDay(),
            'payment_deadline' => now()->addDay(),
        ]);
        AdmissionOffering::factory()->create([
            'term_id' => $term->id,
            'name' => 'Blocked Offering',
            'status' => AdmissionOffering::StatusDraft,
            'published_at' => null,
        ]);

        $data = $this->dashboardService()->evaluate($term->id, CarbonImmutable::now(config('app.timezone')));

        $this->assertSame(1, $data['summary']['total_offerings']);
        $this->assertSame(0, $data['summary']['ready_offerings']);
        $this->assertSame(1, $data['summary']['blocked_offerings']);
        $this->assertContains('Admission setup', collect($data['offerings'][0]['blockers'])->pluck('category')->all());
        $this->assertContains('Requirement policy', collect($data['offerings'][0]['blockers'])->pluck('category')->all());
        $this->assertContains('Capacity', collect($data['offerings'][0]['blockers'])->pluck('category')->all());
        $this->assertContains('Published schedule', collect($data['offerings'][0]['blockers'])->pluck('category')->all());
    }

    public function test_dashboard_service_reports_ready_offering_when_payment_prerequisites_are_configured(): void
    {
        $term = Term::factory()->create([
            'enrollment_starts_at' => now()->subDay(),
            'enrollment_ends_at' => now()->addDays(10),
            'payment_deadline' => now()->addDays(12),
        ]);
        $offering = AdmissionOffering::factory()->create([
            'term_id' => $term->id,
            'name' => 'Ready Offering',
            'education_level' => 'college',
            'year_level' => '1st Year',
        ]);
        $policy = AdmissionRequirementPolicy::factory()->create([
            'admission_offering_id' => $offering->id,
        ]);
        DocumentRequirementItem::factory()->create([
            'admission_requirement_policy_id' => $policy->id,
        ]);
        AdmissionCapacityPlan::factory()->create([
            'term_id' => $term->id,
            'scope_type' => AdmissionCapacityPlan::ScopeEducationLevel,
            'education_level' => 'college',
            'capacity_limit' => 10,
            'reserved_count' => 2,
        ]);
        $this->publishedSchedule($term);

        $data = $this->dashboardService()->evaluate($term->id, CarbonImmutable::now(config('app.timezone')));

        $this->assertSame(1, $data['summary']['ready_offerings']);
        $this->assertTrue($data['offerings'][0]['is_ready']);
        $this->assertSame([], $data['offerings'][0]['blockers']);
        $this->assertSame(8, $data['offerings'][0]['capacity_plans'][0]['remaining']);
        $this->assertSame(1, $data['offerings'][0]['active_policy_count']);
        $this->assertSame(1, $data['offerings'][0]['document_item_count']);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staffUser(string $roleName, array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $user = User::factory()->create();
        $user->assignRole($roleName);

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    private function dashboardService(): AdmissionReadinessDashboardService
    {
        $schedulingReadiness = new class extends TermSchedulingReadinessService
        {
            public function __construct() {}

            public function evaluateTerm(Term $term): array
            {
                return [
                    'is_ready' => true,
                    'missing_term_fields' => [],
                    'section_issues' => [],
                    'delivery_group_issues' => [],
                    'faculty_input_issues' => [],
                    'room_catalog_mode' => 'test',
                ];
            }
        };

        return new AdmissionReadinessDashboardService($schedulingReadiness);
    }

    private function publishedSchedule(Term $term): void
    {
        $section = Section::factory()->create([
            'term_id' => $term->id,
        ]);
        $group = SectionDeliveryGroup::factory()->create([
            'section_id' => $section->id,
        ]);
        $subject = Subject::factory()->create();
        $faculty = User::factory()->create();
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'requested_by' => $faculty->id,
            'generated_at' => now(),
            'committed_by' => $faculty->id,
            'committed_at' => now(),
            'published_by' => $faculty->id,
            'published_at' => now(),
            'constraint_summary' => [],
        ]);

        SectionMeeting::query()->create([
            'term_id' => $term->id,
            'section_id' => $section->id,
            'section_delivery_group_id' => $group->id,
            'subject_id' => $subject->id,
            'faculty_id' => $faculty->id,
            'room' => 'R-101',
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:00:00',
            'modality' => 'on_site',
            'schedule_generation_run_id' => $run->id,
            'committed_by' => $faculty->id,
            'committed_at' => now(),
        ]);
    }
}
