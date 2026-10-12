<?php

namespace Tests\Feature\Auth;

use App\Models\StaffInvitation;
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
        $this->assertStringContainsString('servitech-crest.webp', (string) FilamentView::renderHook(PanelsRenderHook::CONTENT_START));
        foreach ([PanelsRenderHook::SIDEBAR_FOOTER, PanelsRenderHook::CONTENT_END, PanelsRenderHook::SIMPLE_PAGE_END] as $hook) {
            $this->assertStringNotContainsString('Powered by TALA', (string) FilamentView::renderHook($hook));
        }
    }

    public function test_signed_in_applicant_panel_shows_the_escaped_name_once_in_each_identity_context(): void
    {
        Role::findOrCreate('applicant', 'web');
        $applicant = User::factory()->create(User::staffNamePayload('Ada', null, '<Applicant>'));
        $applicant->assignRole('applicant');
        $this->actingAs($applicant);
        $panel = $this->bootPanel('applicant');

        foreach (['sidebar brand' => $panel->getBrandLogo()->render(), PanelsRenderHook::CONTENT_START => (string) FilamentView::renderHook(PanelsRenderHook::CONTENT_START)] as $context => $markup) {
            $this->assertSame(1, substr_count($markup, 'Ada &lt;Applicant&gt;'), $context.' for '.$applicant->getFilamentName());
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
    public function test_non_applicant_panels_preserve_identity_and_attribution_for_multi_role_accounts(string $panelId, string $context): void
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
        $this->assertStringContainsString('servitech-crest.webp', $panel->getBrandLogo()->render());
        $this->assertStringNotContainsString('Shared Account', (string) FilamentView::renderHook(PanelsRenderHook::CONTENT_START));
        foreach ([PanelsRenderHook::SIDEBAR_FOOTER, PanelsRenderHook::CONTENT_END, PanelsRenderHook::SIMPLE_PAGE_END] as $hook) {
            $this->assertStringContainsString('Powered by TALA', (string) FilamentView::renderHook($hook));
        }
    }

    public function test_workspace_choice_has_a_shared_appearance_and_an_empty_state_recovery(): void
    {
        $this->view('auth.workspace-chooser', ['contexts' => [], 'errors' => new ViewErrorBag])
            ->assertSee('data-tala-system-appearance', false)
            ->assertSee('css/tala-foundation.css', false)
            ->assertSee('Skip to main content')
            ->assertSee('No workspace is currently authorized')
            ->assertSee('System Administrator')
            ->assertSee('Contact school support');
    }

    public function test_staff_activation_retains_native_password_fields_with_associated_guidance(): void
    {
        $this->view('auth.staff-activation', [
            'invitation' => (new StaffInvitation)->forceFill(['id' => 1]), 'token' => str_repeat('a', 64),
            'isUsable' => true, 'errors' => new ViewErrorBag,
        ])->assertSee('data-tala-system-appearance', false)
            ->assertSee('autocomplete="new-password"', false)
            ->assertSee('aria-describedby="password-guidance', false)
            ->assertSee('Continue to MFA setup')
            ->assertSee('Contact school support');
    }

    public function test_server_failure_rendering_does_not_require_authentication_data_or_compiled_assets(): void
    {
        Auth::shouldReceive('user')->never();
        $this->view('errors.500')->assertSee('Servitech Institute Asia')
            ->assertSee('Error 500')
            ->assertSee('Powered by TALA')
            ->assertSee('The request outcome is unconfirmed.')
            ->assertSee('Contact school support')
            ->assertSee('System Administration')
            ->assertSee('Return to Servitech Institute Asia home')
            ->assertDontSee('Return to TALA home')
            ->assertDontSee('/build/assets', false)
            ->assertDontSee('livewire.js', false);
    }

    public function test_shared_access_layout_renders_school_first_identity_without_duplicate_announcement(): void
    {
        $view = $this->blade('<x-tala-access-layout title="Test Access" heading-id="test-heading"><h1 id="test-heading">Test Heading</h1><div>Body Content</div></x-tala-access-layout>');

        $view->assertSee('Test Access — Servitech Institute Asia Inc. · Powered by TALA')
            ->assertSee('Servitech Institute Asia home')
            ->assertSee('alt="" aria-hidden="true" class="tala-brand__crest"', false)
            ->assertSee('Servitech Institute Asia')
            ->assertDontSee('alt="Servitech Institute Asia"', false);
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
