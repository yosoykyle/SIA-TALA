<?php

namespace Tests\Feature\Auth;

use App\Http\Responses\ApplicantRegistrationResponse;
use App\Http\Responses\RoleAwareLoginResponse;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmailVerificationBoundaryTest extends TestCase
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
     * @return array<string, array{role: string, status: string, panel: string, promptRoute: string}>
     */
    public static function unverifiedUsers(): array
    {
        return [
            'registrar staff workspace' => [
                'role' => User::StaffRoleRegistrar,
                'status' => User::StatusActive,
                'panel' => 'admin',
                'promptRoute' => 'filament.admin.auth.email-verification.prompt',
            ],
            'applicant workspace' => [
                'role' => 'applicant',
                'status' => User::StatusApplicantPending,
                'panel' => 'applicant',
                'promptRoute' => 'filament.applicant.auth.email-verification.prompt',
            ],
            'student hub' => [
                'role' => 'student',
                'status' => User::StatusActive,
                'panel' => 'student',
                'promptRoute' => 'filament.student.auth.email-verification.prompt',
            ],
        ];
    }

    #[DataProvider('unverifiedUsers')]
    public function test_unverified_valid_users_are_allowed_to_reach_only_their_panel_verification_prompt(
        string $role,
        string $status,
        string $panel,
        string $promptRoute,
    ): void {
        $user = $this->userWithRole($role, $status, verified: false);

        $this->assertTrue($user->canAccessPanel(Filament::getPanel($panel)));

        $this->actingAs($user)
            ->get("/{$panel}")
            ->assertRedirect(route($promptRoute));
    }

    #[DataProvider('unverifiedUsers')]
    public function test_login_response_sends_unverified_users_to_their_panel_verification_prompt(
        string $role,
        string $status,
        string $panel,
        string $promptRoute,
    ): void {
        $user = $this->userWithRole($role, $status, verified: false);
        $request = Request::create('/login', 'POST');
        $request->setUserResolver(fn (): User => $user);

        $response = app(RoleAwareLoginResponse::class)->toResponse($request);

        $this->assertSame(route($promptRoute), $response->getTargetUrl());
    }

    public function test_applicant_registration_response_sends_unverified_applicant_to_applicant_verification_prompt(): void
    {
        $request = Request::create('/applicant/register', 'POST');

        $response = app(ApplicantRegistrationResponse::class)->toResponse($request);

        $this->assertSame(route('filament.applicant.auth.email-verification.prompt'), $response->getTargetUrl());
    }

    /**
     * @return array<string, array{role: string, status: string, panel: string}>
     */
    public static function verifiedUsers(): array
    {
        return [
            'verified registrar staff workspace' => [
                'role' => User::StaffRoleRegistrar,
                'status' => User::StatusActive,
                'panel' => 'admin',
            ],
            'verified applicant workspace' => [
                'role' => 'applicant',
                'status' => User::StatusApplicantPending,
                'panel' => 'applicant',
            ],
            'verified student hub' => [
                'role' => 'student',
                'status' => User::StatusActive,
                'panel' => 'student',
            ],
        ];
    }

    #[DataProvider('verifiedUsers')]
    public function test_verified_valid_users_can_reach_their_panel_dashboard(
        string $role,
        string $status,
        string $panel,
    ): void {
        $user = $this->userWithRole($role, $status, verified: true);

        $this->actingAs($user)
            ->get("/{$panel}")
            ->assertOk();
    }

    private function userWithRole(string $role, string $status, bool $verified): User
    {
        $user = User::factory()->create([
            'status' => $status,
            'email_verified_at' => $verified ? now() : null,
        ]);

        $user->assignRole($role);

        return $user;
    }
}
