<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
        $applicant = $this->userWithRole('applicant', User::StatusApplicantPending);

        $labels = $this->navigationLabelsForPanel($applicant, 'applicant');

        $this->assertSame(['My Application', 'Dashboard'], $labels);
        $this->assertNoStaffOnlyNavigationLabels($labels);
    }

    public function test_student_hub_navigation_contains_only_student_surfaces_once(): void
    {
        $student = $this->userWithRole('student', User::StatusActive);

        $labels = $this->navigationLabelsForPanel($student, 'student');

        $this->assertSame([
            'COR',
            'Dashboard',
            'Finance',
            'Grades',
            'Holds & Blockers',
            'Class Schedule',
        ], $labels);
        $this->assertSame($labels, array_values(array_unique($labels)));
        $this->assertNoStaffOnlyNavigationLabels($labels);
    }

    public function test_applicant_dashboard_does_not_render_staff_workspace_surfaces(): void
    {
        $applicant = $this->userWithRole('applicant', User::StatusApplicantPending);

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

        foreach (Filament::getPanel($panel)->getPages() as $page) {
            if ($page::shouldRegisterNavigation() && $page::canAccess()) {
                $labels[] = $page::getNavigationLabel();
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

        return $user;
    }
}
