<?php

namespace Tests\Feature\Auth;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LearnerWorkspaceNavigationBoundaryTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['applicant', 'student', ...User::staffRoleNames()] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_applicant_workspace_navigation_contains_only_applicant_surfaces(): void
    {
        $applicant = $this->userWithRole('applicant', User::StatusActive);

        $labels = $this->navigationLabelsForPanel($applicant, 'applicant');

        $this->assertSame(['Home', 'Application'], $labels);
        $this->assertNoStaffOnlyNavigationLabels($labels);
    }

    public function test_student_hub_navigation_contains_only_student_surfaces_once(): void
    {
        $student = $this->userWithRole('student', User::StatusActive);

        $labels = $this->navigationLabelsForPanel($student, 'student');

        $this->assertSame([
            'Home',
            'Enrollment',
            'Academics',
            'Finance',
            'Profile',
        ], $labels);
        $this->assertSame($labels, array_values(array_unique($labels)));
        $this->assertNoStaffOnlyNavigationLabels($labels);
    }

    #[DataProvider('staffNavigation')]
    public function test_each_staff_context_has_only_its_canonical_top_level_destinations(string $role, array $expected): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = $this->userWithRole($role, User::StatusActive);
        $this->withSession([WorkspaceContextResolver::SessionKey => $role]);

        $this->assertSame($expected, $this->navigationLabelsForPanel($user, 'admin'));
    }

    public static function staffNavigation(): array
    {
        return [
            'Registrar' => [User::StaffRoleRegistrar, ['Admissions', 'Catalog & Curricula', 'Term Planning', 'Students & Enrollment', 'Grades & Completion']],
            'Accounting' => [User::StaffRoleAccounting, ['Fee Plans', 'Student Accounts']],
            'Faculty' => [User::StaffRoleFaculty, ['My Availability', 'My Schedule', 'Grade Rosters']],
            'Academic Head' => [User::StaffRoleAcademicHead, ['Academic Oversight']],
            'System Administrator' => [User::StaffRoleSystemSuperAdmin, ['Users & Access', 'Public Content', 'System Health', 'Governance & Audit']],
        ];
    }

    public function test_removed_primary_entries_keep_their_required_contextual_recovery_paths(): void
    {
        $this->seed(DatabaseSeeder::class);

        $applicantDashboard = file_get_contents(resource_path('views/filament/applicant/pages/dashboard.blade.php'));
        $this->assertStringContainsString('Pages\\Requirements::getUrl()', $applicantDashboard);
        $this->assertStringContainsString('Review requirements', $applicantDashboard);

        $registrar = $this->userWithRole(User::StaffRoleRegistrar, User::StatusActive);
        $this->actingAs($registrar)->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleRegistrar]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListAdmissionApplications::class)
            ->assertActionExists('admissionCycles')
            ->assertActionHasUrl('admissionCycles', AdmissionCycleResource::getUrl());

        $academicHead = $this->userWithRole(User::StaffRoleAcademicHead, User::StatusActive);
        $this->actingAs($academicHead)->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleAcademicHead]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(AcademicApprovals::class)
            ->assertSee('Catalog & Curricula')
            ->assertSee('Academic Readiness')
            ->assertSee('Term Planning')
            ->assertSee('Grade Review')
            ->assertSee('Lifecycle Exceptions');

        $provider = file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));
        $this->assertStringContainsString("'My Availability' => CalendarEventResource::class", $provider);
        $this->assertStringContainsString("'Public Content' => PublicContent::class", $provider);
        $this->assertStringNotContainsString("'Admission Cycles' =>", $provider);
        $this->assertStringNotContainsString("'My Unavailable Times' =>", $provider);
    }

    public function test_applicant_dashboard_does_not_render_staff_workspace_surfaces(): void
    {
        $applicant = $this->userWithRole('applicant', User::StatusActive);

        $this->actingAs($applicant)
            ->get('/applicant')
            ->assertOk()
            ->assertSee('TALA Applicant Workspace')
            ->assertDontSee('Staff Workspace')
            ->assertDontSee('Audit Logs')
            ->assertDontSee('Schedule Drafts')
            ->assertDontSee('Payment Queue')
            ->assertDontSee('Faculty Class Lists')
            ->assertDontSee('Grade Oversight')
            ->assertDontSee('Users')
            ->assertDontSee('Roles & Permissions');
    }

    public function test_student_hub_dashboard_does_not_render_staff_workspace_surfaces(): void
    {
        $student = $this->userWithRole('student', User::StatusActive);

        $this->actingAs($student)
            ->get('/student')
            ->assertOk()
            ->assertSee('TALA Student Hub')
            ->assertDontSee('Staff Workspace')
            ->assertDontSee('Audit Logs')
            ->assertDontSee('Schedule Drafts')
            ->assertDontSee('Payment Queue')
            ->assertDontSee('Applicant Review')
            ->assertDontSee('Document Review')
            ->assertDontSee('Faculty Class Lists')
            ->assertDontSee('Grade Oversight')
            ->assertDontSee('Users')
            ->assertDontSee('Roles & Permissions');
    }

    /**
     * @return list<string>
     */
    private function navigationLabelsForPanel(User $user, string $panel): array
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel($panel));

        $labels = [];

        foreach (Filament::getPanel($panel)->getNavigation() as $group) {
            foreach ($group->getItems() as $item) {
                $labels[] = $item->getLabel();
            }
        }

        return $labels;
    }

    /**
     * @param  list<string>  $labels
     */
    private function assertNoStaffOnlyNavigationLabels(array $labels): void
    {
        foreach ([
            'Admission Readiness',
            'Applicant Review',
            'Audit Logs',
            'COR Controls',
            'Document Review',
            'Faculty Class Lists',
            'Grade Oversight',
            'Payment Queue',
            'Roles & Permissions',
            'Schedule Drafts',
            'Users',
        ] as $staffOnlyLabel) {
            $this->assertNotContains($staffOnlyLabel, $labels);
        }
    }

    private function userWithRole(string $role, string $status): User
    {
        $user = User::factory()->create([
            'status' => $status,
            'email_verified_at' => now(),
        ]);

        $user->assignRole($role);

        if ($role === 'student') {
            StudentProfile::factory()->create([
                'user_id' => $user->id,
            ]);
        }

        return $user;
    }
}
