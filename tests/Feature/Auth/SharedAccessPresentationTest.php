<?php

namespace Tests\Feature\Auth;

use App\Models\PendingEmailChange;
use App\Models\StaffInvitation;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Filament\Support\View\ViewManager;
use Filament\View\PanelsRenderHook;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SharedAccessPresentationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_anonymous_applicant_panel_keeps_school_identity_without_workspace_or_attribution(): void
    {
        $panel = $this->bootPanel('applicant');

        $this->assertStringContainsString('Servitech', $panel->getBrandLogo()->render());
        $this->assertStringNotContainsString('Applicant Workspace', (string) FilamentView::renderHook(PanelsRenderHook::SIDEBAR_NAV_START));
        $this->assertStringContainsString('servitech-crest-outlined-192.webp', (string) FilamentView::renderHook(PanelsRenderHook::CONTENT_BEFORE));
        foreach ([PanelsRenderHook::SIDEBAR_FOOTER, PanelsRenderHook::CONTENT_END, PanelsRenderHook::SIMPLE_PAGE_END] as $hook) {
            $this->assertStringNotContainsString('Powered by TALA', (string) FilamentView::renderHook($hook));
        }
    }

    public function test_signed_in_applicant_panel_shows_the_escaped_name_once_in_the_top_bar(): void
    {
        Role::findOrCreate('applicant', 'web');
        $applicant = User::factory()->create(User::staffNamePayload('Ada', null, '<Applicant>'));
        $applicant->assignRole('applicant');
        $this->actingAs($applicant);
        $panel = $this->bootPanel('applicant');

        $sidebarBrand = $panel->getBrandLogo()->render();
        $topBar = (string) FilamentView::renderHook(PanelsRenderHook::CONTENT_BEFORE);

        $this->assertStringNotContainsString('Ada', $sidebarBrand);
        $this->assertSame(1, substr_count($topBar, 'Ada &lt;Applicant&gt;'));
        foreach ([$sidebarBrand, $topBar] as $markup) {
            $this->assertStringNotContainsString('Ada <Applicant>', $markup);
            $this->assertStringNotContainsString('Applicant Workspace', $markup);
        }
        foreach ([PanelsRenderHook::SIDEBAR_FOOTER, PanelsRenderHook::CONTENT_END, PanelsRenderHook::SIMPLE_PAGE_END] as $hook) {
            $this->assertStringNotContainsString('Powered by TALA', (string) FilamentView::renderHook($hook));
        }
    }

    /** @return array<string, array{string, string}> */
    public static function nonApplicantPanels(): array
    {
        return [
            'Staff context of an Applicant-capable account' => ['admin', 'Registrar workspace'],
            'Student context of an Applicant-capable account' => ['student', 'Student Hub'],
        ];
    }

    #[DataProvider('nonApplicantPanels')]
    public function test_signed_in_panels_preserve_identity_without_product_attribution(string $panelId, string $context): void
    {
        foreach (['applicant', 'student', User::StaffRoleRegistrar] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $user = User::factory()->create([
            ...User::staffNamePayload('Shared', null, 'Account'),
            'status' => User::StatusActive,
        ]);
        $user->assignRole(['applicant', 'student', User::StaffRoleRegistrar]);
        $this->actingAs($user);
        session()->put('tala.workspace_context', User::StaffRoleRegistrar);
        $panel = $this->bootPanel($panelId);

        $this->assertStringContainsString($context, (string) FilamentView::renderHook(PanelsRenderHook::SIDEBAR_NAV_START));
        $this->assertStringContainsString('servitech-crest-outlined-192.webp', $panel->getBrandLogo()->render());
        $this->assertStringNotContainsString('Shared Account', (string) FilamentView::renderHook(PanelsRenderHook::CONTENT_START));
        foreach ([PanelsRenderHook::SIDEBAR_FOOTER, PanelsRenderHook::CONTENT_END, PanelsRenderHook::SIMPLE_PAGE_END] as $hook) {
            $this->assertStringNotContainsString('Powered by TALA', (string) FilamentView::renderHook($hook));
        }
    }

    #[DataProvider('nonApplicantPanels')]
    public function test_anonymous_non_applicant_panels_omit_account_entry_attribution(string $panelId, string $context): void
    {
        $this->bootPanel($panelId);

        $this->assertStringNotContainsString('Powered by TALA', (string) FilamentView::renderHook(PanelsRenderHook::SIMPLE_PAGE_END));
    }

    public function test_signed_in_access_and_error_pages_omit_product_attribution(): void
    {
        $this->actingAs(User::factory()->create());

        $this->blade('<x-tala-access-layout title="Test Access" heading-id="test-heading"><h1 id="test-heading">Test Heading</h1></x-tala-access-layout>')
            ->assertSee('Servitech Institute Asia')
            ->assertDontSee('Powered by TALA');
        $this->view('errors.403')->assertSee('Access not allowed')->assertDontSee('Powered by TALA');
        $this->view('errors.500')->assertDontSee('Powered by TALA');
    }

    public function test_workspace_choice_has_a_shared_appearance_and_an_empty_state_recovery(): void
    {
        $this->actingAs(User::factory()->create([
            ...User::staffNamePayload('Elena', null, 'Reyes'),
            'email' => 'elena.reyes@example.test',
        ]));

        $this->view('auth.workspace-chooser', ['contexts' => [], 'errors' => new ViewErrorBag])
            ->assertSee('data-tala-system-appearance', false)
            ->assertSee('css/tala-foundation.css', false)
            ->assertSee('tala-ambient', false)
            ->assertSee('tala-record-identity', false)
            ->assertSee('tala-identity-surface', false)
            ->assertSee('student-identity-art', false)
            ->assertSee('Elena Reyes')
            ->assertSee('Skip to main content')
            ->assertSee('No workspace is currently authorized')
            ->assertSee('System Administrator')
            ->assertSee('Contact support')
            ->assertDontSee('—');
    }

    public function test_staff_activation_uses_auth_designer_with_native_password_fields_and_guidance(): void
    {
        $inviter = User::factory()->create();
        $invitee = User::factory()->create([
            ...User::staffNamePayload('Ada', null, 'Lovelace'),
            'email' => 'faculty.invitee@example.test',
            'password' => null,
            'status' => User::StatusInvitationPending,
        ]);
        $plainToken = str_repeat('a', 64);
        $invitation = StaffInvitation::query()->create([
            'user_id' => $invitee->id,
            'invited_by' => $inviter->id,
            'email' => $invitee->email,
            'staff_roles' => [User::StaffRoleFaculty],
            'token_digest' => hash('sha256', $plainToken),
            'expires_at' => now()->addHour(),
            'sent_at' => now(),
        ]);

        $response = $this->get(route('staff-invitations.activate', [
            'invitation' => $invitation,
            'token' => $plainToken,
        ]))
            ->assertOk()
            ->assertSee('tala-auth-main', false)
            ->assertSee('fi-auth-layout has-media media-top', false)
            ->assertSee('fi-auth-media-section', false)
            ->assertSee('images/auth/admin.webp', false)
            ->assertSee('tala-ambient--auth', false)
            ->assertSee('tala-record-identity', false)
            ->assertSee('tala-identity-surface', false)
            ->assertSee('student-identity-art', false)
            ->assertSee('fi-auth-theme-switcher-wrapper', false)
            ->assertSee('Enable light theme')
            ->assertSee('Enable dark theme')
            ->assertSee('Enable system theme')
            ->assertSee('tala-auth-support-dialog', false)
            ->assertSee('tala-auth-privacy-dialog', false)
            ->assertSee('autocomplete="new-password"', false)
            ->assertSee('enterkeyhint="next"', false)
            ->assertSee('enterkeyhint="done"', false)
            ->assertSee('15 to 64 characters')
            ->assertSee('Confirm password')
            ->assertSee('Ada Lovelace')
            ->assertSee('faculty.invitee@example.test')
            ->assertSee('Faculty')
            ->assertSee('Activate your account')
            ->assertSee('Activate account')
            ->assertSee('Back to school site')
            ->assertSee('Contact support');

        preg_match('/<main\b[^>]*>(.*?)<\/main>/s', $response->getContent(), $mainMatch);
        $this->assertNotEmpty($mainMatch[1] ?? '');
        $this->assertStringNotContainsString('—', $mainMatch[1]);
    }

    public function test_server_failure_rendering_does_not_require_authentication_data_or_compiled_assets(): void
    {
        Auth::shouldReceive('user')->never();
        $this->view('errors.500')->assertSee('Servitech Institute Asia')
            ->assertSee('Error 500')
            ->assertDontSee('Powered by TALA')
            ->assertSee('The request outcome is unconfirmed.')
            ->assertSee('Contact support')
            ->assertSee('System Administration')
            ->assertSee('Back to home')
            ->assertDontSee('Return to TALA home')
            ->assertDontSee('/build/assets', false)
            ->assertDontSee('livewire.js', false);
    }

    public function test_shared_access_layout_renders_school_first_identity_without_duplicate_announcement(): void
    {
        $view = $this->blade('<x-tala-access-layout title="Test Access" heading-id="test-heading"><h1 id="test-heading">Test Heading</h1><div>Body Content</div></x-tala-access-layout>');

        $view->assertSee('Test Access | Servitech Institute Asia Inc.')
            ->assertDontSee('Powered by TALA')
            ->assertSee('Servitech Institute Asia home')
            ->assertSee('alt="" aria-hidden="true" class="tala-brand__crest tala-crest-outlined"', false)
            ->assertSee('Servitech Institute Asia')
            ->assertDontSee('alt="Servitech Institute Asia"', false);
    }

    public function test_authorized_workspace_name_covers_staff_workspace_subpaths_and_chooser(): void
    {
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        Role::findOrCreate('student', 'web');

        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        $this->assertSame('Staff Workspace', $registrar->authorizedWorkspaceName());

        $dualRole = User::factory()->create(['status' => User::StatusActive]);
        $dualRole->assignRole(['student', User::StaffRoleRegistrar]);
        StudentProfile::factory()->create(['user_id' => $dualRole->id]);

        $this->assertSame('workspace chooser', $dualRole->authorizedWorkspaceName());

        $this->actingAs($registrar);
        $this->view('errors.403')
            ->assertSee('Return to Staff Workspace')
            ->assertSee('Use another account');

        $this->view('errors.419')
            ->assertSee('Sign in again')
            ->assertSee('Back to home');
    }

    public function test_invalid_staff_email_change_link_redirects_to_staff_login_with_visible_status_alert(): void
    {
        $staff = User::factory()->create(['status' => User::StatusActive]);
        $change = PendingEmailChange::query()->create([
            'user_id' => $staff->id,
            'new_email' => 'updated-staff@example.test',
            'token_digest' => hash('sha256', 'valid-token'),
            'expires_at' => now()->subMinute(),
        ]);

        $response = $this->get(route('staff-email-changes.verify', ['change' => $change, 'token' => 'valid-token']));

        $response->assertRedirect(route('filament.admin.auth.login'))
            ->assertSessionHas('status');

        $this->get(route('filament.admin.auth.login'))
            ->assertOk()
            ->assertSee('tala-access-alert--info', false);
    }

    public function test_applicant_verification_countdown_uses_accessible_light_and_dark_contrast_classes(): void
    {
        $this->view('filament.applicant.components.verification-countdown', [
            'expiresAtMilliseconds' => now()->addMinutes(30)->getTimestampMs(),
            'initialClock' => '30:00',
        ])->assertDontSee(' text-gray-300', false)
            ->assertDontSee(' text-gray-400', false)
            ->assertSee('text-gray-700 dark:text-gray-200', false)
            ->assertSee('text-gray-600 dark:text-gray-300', false);
    }

    private function bootPanel(string $panelId): Panel
    {
        FilamentView::swap(new ViewManager);
        $panel = Filament::getPanel($panelId);
        Filament::setCurrentPanel($panel);
        $panel->boot();

        return $panel;
    }
}
