<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PanelAccessBoundaryTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['applicant', 'student', ...User::staffRoleNames()] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    /**
     * @return array<string, array{role: string, status: string, verified: bool, panel: string}>
     */
    public static function allowedPanelAccess(): array
    {
        return [
            'registrar can access staff workspace' => [
                'role' => User::StaffRoleRegistrar,
                'status' => User::StatusActive,
                'verified' => true,
                'panel' => 'admin',
            ],
            'accounting can access staff workspace' => [
                'role' => User::StaffRoleAccounting,
                'status' => User::StatusActive,
                'verified' => true,
                'panel' => 'admin',
            ],
            'faculty can access staff workspace' => [
                'role' => User::StaffRoleFaculty,
                'status' => User::StatusActive,
                'verified' => true,
                'panel' => 'admin',
            ],
            'academic head can access staff workspace' => [
                'role' => User::StaffRoleAcademicHead,
                'status' => User::StatusActive,
                'verified' => true,
                'panel' => 'admin',
            ],
            'system super admin can access staff workspace' => [
                'role' => User::StaffRoleSystemSuperAdmin,
                'status' => User::StatusActive,
                'verified' => true,
                'panel' => 'admin',
            ],
            'student can access student hub' => [
                'role' => 'student',
                'status' => User::StatusActive,
                'verified' => true,
                'panel' => 'student',
            ],
            'pending applicant can access applicant workspace' => [
                'role' => 'applicant',
                'status' => User::StatusApplicantPending,
                'verified' => true,
                'panel' => 'applicant',
            ],
            'action-required applicant can access applicant workspace' => [
                'role' => 'applicant',
                'status' => User::StatusApplicantActionRequired,
                'verified' => true,
                'panel' => 'applicant',
            ],
            'for-evaluation applicant can access applicant workspace' => [
                'role' => 'applicant',
                'status' => User::StatusApplicantForEvaluation,
                'verified' => true,
                'panel' => 'applicant',
            ],
            'approved applicant can access applicant workspace' => [
                'role' => 'applicant',
                'status' => User::StatusApplicantApproved,
                'verified' => true,
                'panel' => 'applicant',
            ],
        ];
    }

    #[DataProvider('allowedPanelAccess')]
    public function test_canonical_roles_can_access_only_their_authorized_panel(
        string $role,
        string $status,
        bool $verified,
        string $panel,
    ): void {
        $user = $this->userWithRole($role, $status, $verified);

        $this->assertTrue($user->canAccessPanel(Filament::getPanel($panel)));
    }

    /**
     * @return array<string, array{role: ?string, status: string, verified: bool, panel: string}>
     */
    public static function deniedPanelAccess(): array
    {
        return [
            'guest-equivalent no-role user cannot access staff workspace' => [
                'role' => null,
                'status' => User::StatusActive,
                'verified' => true,
                'panel' => 'admin',
            ],
            'student cannot access staff workspace' => [
                'role' => 'student',
                'status' => User::StatusActive,
                'verified' => true,
                'panel' => 'admin',
            ],
            'applicant cannot access staff workspace' => [
                'role' => 'applicant',
                'status' => User::StatusApplicantPending,
                'verified' => true,
                'panel' => 'admin',
            ],
            'registrar cannot access student hub' => [
                'role' => User::StaffRoleRegistrar,
                'status' => User::StatusActive,
                'verified' => true,
                'panel' => 'student',
            ],
            'registrar cannot access applicant workspace' => [
                'role' => User::StaffRoleRegistrar,
                'status' => User::StatusActive,
                'verified' => true,
                'panel' => 'applicant',
            ],
            'student cannot access applicant workspace' => [
                'role' => 'student',
                'status' => User::StatusActive,
                'verified' => true,
                'panel' => 'applicant',
            ],
            'applicant cannot access student hub' => [
                'role' => 'applicant',
                'status' => User::StatusApplicantPending,
                'verified' => true,
                'panel' => 'student',
            ],
            'inactive registrar cannot access staff workspace' => [
                'role' => User::StaffRoleRegistrar,
                'status' => User::StatusInactive,
                'verified' => true,
                'panel' => 'admin',
            ],
            'archived registrar cannot access staff workspace' => [
                'role' => User::StaffRoleRegistrar,
                'status' => User::StatusArchived,
                'verified' => true,
                'panel' => 'admin',
            ],
            'inactive student cannot access student hub' => [
                'role' => 'student',
                'status' => User::StatusInactive,
                'verified' => true,
                'panel' => 'student',
            ],
            'archived student cannot access student hub' => [
                'role' => 'student',
                'status' => User::StatusArchived,
                'verified' => true,
                'panel' => 'student',
            ],
            'inactive applicant cannot access applicant workspace' => [
                'role' => 'applicant',
                'status' => User::StatusInactive,
                'verified' => true,
                'panel' => 'applicant',
            ],
            'archived applicant cannot access applicant workspace' => [
                'role' => 'applicant',
                'status' => User::StatusArchived,
                'verified' => true,
                'panel' => 'applicant',
            ],
        ];
    }

    #[DataProvider('deniedPanelAccess')]
    public function test_wrong_role_inactive_archived_or_unverified_users_cannot_access_panels(
        ?string $role,
        string $status,
        bool $verified,
        string $panel,
    ): void {
        $user = $this->userWithRole($role, $status, $verified);

        $this->assertFalse($user->canAccessPanel(Filament::getPanel($panel)));
    }

    /**
     * @return array<string, array{path: string, loginRoute: string}>
     */
    public static function guestPanelRedirects(): array
    {
        return [
            'guest staff workspace' => [
                'path' => '/admin',
                'loginRoute' => 'filament.admin.auth.login',
            ],
            'guest applicant workspace' => [
                'path' => '/applicant',
                'loginRoute' => 'filament.applicant.auth.login',
            ],
            'guest student hub' => [
                'path' => '/student',
                'loginRoute' => 'filament.student.auth.login',
            ],
        ];
    }

    #[DataProvider('guestPanelRedirects')]
    public function test_guests_are_redirected_to_the_matching_panel_login(string $path, string $loginRoute): void
    {
        $this->get($path)
            ->assertRedirect(route($loginRoute));
    }

    /**
     * @return array<string, array{role: string, status: string, path: string}>
     */
    public static function forbiddenPanelRequests(): array
    {
        return [
            'student request to staff workspace' => [
                'role' => 'student',
                'status' => User::StatusActive,
                'path' => '/admin',
            ],
            'applicant request to staff workspace' => [
                'role' => 'applicant',
                'status' => User::StatusApplicantPending,
                'path' => '/admin',
            ],
            'registrar request to applicant workspace' => [
                'role' => User::StaffRoleRegistrar,
                'status' => User::StatusActive,
                'path' => '/applicant',
            ],
            'registrar request to student hub' => [
                'role' => User::StaffRoleRegistrar,
                'status' => User::StatusActive,
                'path' => '/student',
            ],
            'inactive applicant request to applicant workspace' => [
                'role' => 'applicant',
                'status' => User::StatusInactive,
                'path' => '/applicant',
            ],
            'archived student request to student hub' => [
                'role' => 'student',
                'status' => User::StatusArchived,
                'path' => '/student',
            ],
        ];
    }

    #[DataProvider('forbiddenPanelRequests')]
    public function test_authenticated_users_without_panel_access_are_forbidden(
        string $role,
        string $status,
        string $path,
    ): void {
        $user = $this->userWithRole($role, $status, true);

        $this->actingAs($user)
            ->get($path)
            ->assertForbidden();
    }

    private function userWithRole(?string $role, string $status, bool $verified): User
    {
        $user = User::factory()->create([
            'status' => $status,
            'email_verified_at' => $verified ? now() : null,
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }
}
