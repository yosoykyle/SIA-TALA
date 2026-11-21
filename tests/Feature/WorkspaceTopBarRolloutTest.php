<?php

namespace Tests\Feature;

use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\FacultySchedule;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\Users\UserResource;
use App\Http\Middleware\EnsureStaffMfaIsEnabled;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\TalaPanelTheme;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** @see TalaPanelTheme::otherStaffWorkspace() The remaining staff workspaces and Student Hub share one generic top bar (#80), following the Registrar top bar's pattern. */
class WorkspaceTopBarRolloutTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array<string, array{0: string, 1: string}> */
    public static function staffWorkspaces(): array
    {
        return [
            'accounting' => [User::StaffRoleAccounting, 'accounting'],
            'faculty' => [User::StaffRoleFaculty, 'faculty'],
            'academic-head' => [User::StaffRoleAcademicHead, 'academic-head'],
            'system-admin' => [User::StaffRoleSystemSuperAdmin, 'system-admin'],
        ];
    }

    #[DataProvider('staffWorkspaces')]
    public function test_each_remaining_staff_workspace_shows_the_shared_top_bar_with_the_signed_in_name(string $role, string $slug): void
    {
        $this->withoutMiddleware(EnsureStaffMfaIsEnabled::class);
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create(['status' => User::StatusActive, 'first_name' => 'Workspace', 'last_name' => 'Rollout Staffer']);
        $user->assignRole($role);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $url = match ($role) {
            User::StaffRoleAccounting => EnrollmentResource::getUrl(panel: 'admin', isAbsolute: false),
            User::StaffRoleFaculty => FacultySchedule::getUrl(panel: 'admin', isAbsolute: false),
            User::StaffRoleAcademicHead => AcademicApprovals::getUrl(panel: 'admin', isAbsolute: false),
            User::StaffRoleSystemSuperAdmin => UserResource::getUrl(panel: 'admin', isAbsolute: false),
        };

        $response = $this->actingAs($user)->get($url);

        $response->assertOk()
            ->assertSee('tala-staff-topbar', false)
            ->assertSee('data-tala-workspace="'.$slug.'"', false)
            ->assertSee('Workspace Rollout Staffer', false)
            ->assertDontSee('tala-mobile-workspace-identity', false);
    }

    public function test_student_hub_shows_the_shared_top_bar_with_the_signed_in_name(): void
    {
        Role::findOrCreate('student', 'web');
        $user = User::factory()->create(['status' => User::StatusActive, 'first_name' => 'Workspace', 'last_name' => 'Rollout Student']);
        $user->assignRole('student');
        StudentProfile::factory()->create(['user_id' => $user->id]);
        Filament::setCurrentPanel(Filament::getPanel('student'));

        $response = $this->actingAs($user)->get('/student');

        $response->assertOk()
            ->assertSee('tala-staff-topbar', false)
            ->assertSee('data-tala-workspace="student"', false)
            ->assertSee('Workspace Rollout Student', false)
            ->assertDontSee('tala-mobile-workspace-identity', false);
    }
}
