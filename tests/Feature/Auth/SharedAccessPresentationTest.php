<?php

namespace Tests\Feature\Auth;

use App\Models\StaffInvitation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class SharedAccessPresentationTest extends TestCase
{
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
        $this->view('errors.500')->assertSee('Source: Servitech Institute Asia HTTP response 500 (Powered by TALA)')
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
}
