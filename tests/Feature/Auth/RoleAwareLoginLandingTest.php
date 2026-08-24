<?php

namespace Tests\Feature\Auth;

use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleAwareLoginLandingTest extends TestCase
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
     * @return array<string, array{role: string, status: string, expectedPath: string}>
     */
    public static function verifiedCanonicalRoleRedirects(): array
    {
        return [
            'student lands in Student Hub' => [
                'role' => 'student',
                'status' => User::StatusActive,
                'expectedPath' => '/student',
            ],
            'active applicant lands in Applicant Workspace' => [
                'role' => 'applicant',
                'status' => User::StatusActive,
                'expectedPath' => '/applicant',
            ],
        ];
    }

    public function test_staff_cannot_bypass_contextual_filament_mfa_through_the_headless_fortify_login(): void
    {
        $user = $this->userWithRole(User::StaffRoleRegistrar, User::StatusActive);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->get(route('filament.admin.auth.login', ['context' => User::StaffRoleRegistrar]))
            ->assertOk()
            ->assertSee('Sign in to Registrar');
    }

    #[DataProvider('verifiedCanonicalRoleRedirects')]
    public function test_verified_canonical_roles_redirect_to_their_workspace_after_login(
        string $role,
        string $status,
        string $expectedPath,
    ): void {
        $user = $this->userWithRole($role, $status);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect($expectedPath);
    }

    /**
     * @return array<string, array{role: string, status: string, path: string, expectedText: string}>
     */
    public static function dashboardLandingContent(): array
    {
        return [
            'registrar sees staff dashboard shell' => [
                'role' => User::StaffRoleRegistrar,
                'status' => User::StatusActive,
                'path' => '/admin',
                'expectedText' => 'Dashboard',
            ],
            'applicant sees Applicant Workspace dashboard' => [
                'role' => 'applicant',
                'status' => User::StatusActive,
                'path' => '/applicant',
                'expectedText' => 'TALA Applicant Workspace',
            ],
            'student sees Student Hub dashboard' => [
                'role' => 'student',
                'status' => User::StatusActive,
                'path' => '/student',
                'expectedText' => 'TALA Student Hub',
            ],
        ];
    }

    #[DataProvider('dashboardLandingContent')]
    public function test_verified_canonical_roles_land_on_their_dashboard_shell(
        string $role,
        string $status,
        string $path,
        string $expectedText,
    ): void {
        $user = $this->userWithRole($role, $status);

        $this->actingAs($user)
            ->get($path)
            ->assertOk()
            ->assertSee($expectedText);
    }

    public function test_active_verified_user_without_a_canonical_role_cannot_authenticate(): void
    {
        $user = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    private function userWithRole(string $role, string $status): User
    {
        $user = User::factory()->create([
            'status' => $status,
            'email_verified_at' => now(),
        ]);

        $user->assignRole($role);

        if (in_array($role, User::staffRoleNames(), true)) {
            $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $user->saveAppAuthenticationRecoveryCodes(['stored-code']);
        }

        if ($role === 'student') {
            StudentProfile::factory()->create(['user_id' => $user->id]);
        }

        return $user;
    }
}
