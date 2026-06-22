<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FortifyLoginViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_fortify_auth_views_render_for_public_and_verified_flows(): void
    {
        $user = $this->userWithRole('student', [
            'email_verified_at' => null,
        ]);
        $token = Password::broker()->createToken($user);

        $this->get(route('login'))
            ->assertOk()
            ->assertSeeText('Sign in to T.A.L.A.');

        $this->get(route('password.request'))
            ->assertOk()
            ->assertSeeText('Reset your password');

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()
            ->assertSeeText('Create a new password');

        $this->actingAs($user)
            ->get(route('verification.notice'))
            ->assertOk()
            ->assertSeeText('Verify your email');
    }

    public function test_active_student_can_login_and_is_redirected_to_student_hub(): void
    {
        $student = $this->userWithRole('student', [
            'email' => 'student@example.test',
        ]);

        $this->post(route('login.store'), [
            'email' => 'STUDENT@example.test',
            'password' => 'password',
        ])
            ->assertRedirect(route('student.dashboard', absolute: false));

        $this->assertAuthenticatedAs($student);
    }

    public function test_active_staff_can_login_and_is_redirected_to_admin_panel(): void
    {
        $registrar = $this->userWithRole(User::StaffRoleRegistrar, [
            'email' => 'registrar@example.test',
        ]);

        $this->post(route('login.store'), [
            'email' => 'registrar@example.test',
            'password' => 'password',
        ])
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($registrar);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $this->userWithRole('student', [
            'email' => 'inactive@example.test',
            'status' => User::StatusInactive,
        ]);

        $this->post(route('login.store'), [
            'email' => 'inactive@example.test',
            'password' => 'password',
        ])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_logout_clears_authenticated_session(): void
    {
        $student = $this->userWithRole('student');

        $this->actingAs($student)
            ->post(route('logout'))
            ->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_password_reset_flow_sends_link_and_updates_password(): void
    {
        Notification::fake();

        $user = $this->userWithRole('student', [
            'email' => 'reset@example.test',
        ]);

        $this->post(route('password.email'), [
            'email' => 'reset@example.test',
        ])->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);

        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => 'reset@example.test',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login', absolute: false));

        $this->assertTrue(Hash::check('NewPassword123!', $user->refresh()->password));
    }

    public function test_unverified_student_is_redirected_to_email_verification_notice(): void
    {
        $student = $this->userWithRole('student', [
            'email_verified_at' => null,
        ]);

        $this->actingAs($student)
            ->get(route('student.dashboard'))
            ->assertRedirect(route('verification.notice', absolute: false));
    }

    public function test_verification_notification_can_be_resent(): void
    {
        Notification::fake();

        $student = $this->userWithRole('student', [
            'email_verified_at' => null,
        ]);

        $this->actingAs($student)
            ->post(route('verification.send'))
            ->assertRedirect();

        Notification::assertSentTo($student, VerifyEmail::class);
    }

    public function test_wrong_roles_are_denied_from_direct_role_urls(): void
    {
        Permission::findOrCreate('manage-users');
        $student = $this->userWithRole('student');
        $registrar = $this->userWithRole(User::StaffRoleRegistrar);

        $this->actingAs($student)
            ->get('/admin')
            ->assertForbidden();

        $this->actingAs($registrar)
            ->get(route('student.dashboard'))
            ->assertForbidden();
    }

    public function test_s1_auth_configuration_excludes_removed_scope_and_enforces_one_staff_role(): void
    {
        $fortifyConfig = file_get_contents(config_path('fortify.php'));
        $userForm = file_get_contents(app_path('Filament/Resources/Users/Schemas/UserForm.php'));
        $roleResource = file_get_contents(app_path('Filament/Resources/Roles/RoleResource.php'));

        $this->assertIsString($fortifyConfig);
        $this->assertIsString($userForm);
        $this->assertIsString($roleResource);
        $this->assertSame('login', config('fortify.limiters.login'));
        $this->assertTrue(RateLimiter::limiter('login') !== null);
        $this->assertStringNotContainsString('Features::registration()', $fortifyConfig);
        $this->assertStringNotContainsString('Features::passkeys', $fortifyConfig);
        $this->assertStringNotContainsString('Features::twoFactorAuthentication', $fortifyConfig);
        $this->assertStringContainsString('->maxItems(1)', $userForm);
        $this->assertStringContainsString('One role per account', $userForm);
        $this->assertStringContainsString('public static function canCreate(): bool', $roleResource);
        $this->assertStringNotContainsString('CreateRole::route', $roleResource);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function userWithRole(string $role, array $attributes = []): User
    {
        Role::findOrCreate($role);

        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }
}
