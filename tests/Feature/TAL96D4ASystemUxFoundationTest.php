<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Panel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL96D4ASystemUxFoundationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.debug', false);

        Route::get('/_tal96d4a/errors/{status}', static function (string $status): never {
            abort((int) $status, 'TAL96D4A internal diagnostic must not appear in HTML.');
        });
    }

    /**
     * @return array<string, array{status: int, heading: string}>
     */
    public static function brandedBrowserErrors(): array
    {
        return [
            'forbidden' => ['status' => 403, 'heading' => 'Access not allowed'],
            'not found' => ['status' => 404, 'heading' => 'Page not found'],
            'expired session' => ['status' => 419, 'heading' => 'Your session has expired'],
            'too many requests' => ['status' => 429, 'heading' => 'Too many requests'],
            'server error' => ['status' => 500, 'heading' => 'Something went wrong'],
            'service unavailable' => ['status' => 503, 'heading' => 'Service temporarily unavailable'],
            'other client error fallback' => ['status' => 418, 'heading' => 'Request could not be completed'],
            'other server error fallback' => ['status' => 502, 'heading' => 'Service error'],
        ];
    }

    #[DataProvider('brandedBrowserErrors')]
    public function test_browser_errors_are_branded_safe_and_actionable(int $status, string $heading): void
    {
        $response = $this->get("/_tal96d4a/errors/{$status}")
            ->assertStatus($status)
            ->assertSee('Servitech Institute Asia')
            ->assertSee((string) $status)
            ->assertSee($heading)
            ->assertSee('Back to home')
            ->assertDontSee('Return to TALA home')
            ->assertSee('Error '.$status)
            ->assertSee(url('/'), false)
            ->assertSee(asset('css/tala-error.css'), false)
            ->assertDontSee('TAL96D4A internal diagnostic');

        $response->assertDontSee('Powered by TALA');
    }

    public function test_an_unmatched_browser_route_uses_the_branded_not_found_page(): void
    {
        $this->get('/_tal96d4a/route-that-does-not-exist')
            ->assertNotFound()
            ->assertSee('Page not found')
            ->assertSee('Back to home')
            ->assertDontSee('Return to TALA home');

        $this->assertFileExists(public_path('css/tala-error.css'));
    }

    public function test_json_errors_keep_the_framework_json_response_boundary(): void
    {
        $this->getJson('/_tal96d4a/errors/403')
            ->assertForbidden()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('message', 'TAL96D4A internal diagnostic must not appear in HTML.')
            ->assertDontSee('Back to home');
    }

    public function test_authenticated_forbidden_page_offers_the_authorized_workspace_and_explicit_account_switch(): void
    {
        Role::findOrCreate('applicant', 'web');
        $applicant = User::factory()->create([
            'name' => 'Applicant Example',
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $applicant->assignRole('applicant');

        $this->actingAs($applicant)
            ->get('/_tal96d4a/errors/403')
            ->assertForbidden()
            ->assertSee('Return to your application')
            ->assertSee('Stay in your application')
            ->assertDontSee('Applicant Workspace')
            ->assertDontSee('Powered by TALA')
            ->assertSee('/applicant', false)
            ->assertSee('Use another account')
            ->assertSee('Contact support')
            ->assertSee('class="error-support-link"', false)
            ->assertDontSee('HTTP 403')
            ->assertSee($applicant->fresh()->name)
            ->assertSee($applicant->email)
            ->assertSee('action="'.route('logout').'"', false)
            ->assertSee('method="POST"', false)
            ->assertSee(asset('js/tala-error.js'), false);

        $this->assertAuthenticatedAs($applicant);

        $this->post(route('logout'))
            ->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_account_switch_recovery_is_not_shown_for_unrelated_http_errors(): void
    {
        Role::findOrCreate('applicant', 'web');
        $applicant = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $applicant->assignRole('applicant');

        foreach ([404, 419, 429, 500, 503] as $status) {
            $this->actingAs($applicant)
                ->get("/_tal96d4a/errors/{$status}")
                ->assertStatus($status)
                ->assertSee('Back to home')
                ->assertDontSee('Return to TALA home')
                ->assertDontSee('Use another account')
                ->assertDontSee('data-account-switch-dialog', false)
                ->assertDontSee('Powered by TALA');
        }

        $this->assertAuthenticatedAs($applicant);
    }

    public function test_admin_panel_uses_the_canonical_staff_workspace_name(): void
    {
        $panel = (new AdminPanelProvider($this->app))->panel(new Panel);

        $this->assertSame('Servitech Institute Asia — Staff Workspace', $panel->getBrandName());
    }
}
