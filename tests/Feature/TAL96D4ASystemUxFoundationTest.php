<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Panel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL96D4ASystemUxFoundationTest extends TestCase
{
    use LazilyRefreshDatabase;

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
        $this->get("/_tal96d4a/errors/{$status}")
            ->assertStatus($status)
            ->assertSee('TALA')
            ->assertSee((string) $status)
            ->assertSee($heading)
            ->assertSee('Return to TALA home')
            ->assertSee(url('/'), false)
            ->assertSee(asset('css/tala-error.css'), false)
            ->assertDontSee('TAL96D4A internal diagnostic');
    }

    public function test_an_unmatched_browser_route_uses_the_branded_not_found_page(): void
    {
        $this->get('/_tal96d4a/route-that-does-not-exist')
            ->assertNotFound()
            ->assertSee('Page not found')
            ->assertSee('Return to TALA home');

        $this->assertFileExists(public_path('css/tala-error.css'));
    }

    public function test_json_errors_keep_the_framework_json_response_boundary(): void
    {
        $this->getJson('/_tal96d4a/errors/403')
            ->assertForbidden()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('message', 'TAL96D4A internal diagnostic must not appear in HTML.')
            ->assertDontSee('Return to TALA home');
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
            ->assertSee('Return to Applicant Workspace')
            ->assertSee('/applicant', false)
            ->assertSee('Use another account')
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
                ->assertSee('Return to TALA home')
                ->assertDontSee('Use another account')
                ->assertDontSee('data-account-switch-dialog', false);
        }

        $this->assertAuthenticatedAs($applicant);
    }

    public function test_admin_panel_uses_the_canonical_staff_workspace_name(): void
    {
        $panel = (new AdminPanelProvider($this->app))->panel(new Panel);

        $this->assertSame('TALA Staff Workspace', $panel->getBrandName());
    }

    public function test_error_page_actions_and_focus_indicators_meet_contrast_thresholds(): void
    {
        $stylesheet = file_get_contents(public_path('css/tala-error.css'));

        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('outline: 0.1875rem solid #92400e;', $stylesheet);
        $this->assertStringContainsString('outline-color: #f59e0b;', $stylesheet);
        $this->assertStringNotContainsString('background: #3b82f6;', $stylesheet);
        $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio('ffffff', '1d4ed8'));
        $this->assertGreaterThanOrEqual(3.0, $this->contrastRatio('92400e', 'ffffff'));
        $this->assertGreaterThanOrEqual(3.0, $this->contrastRatio('f59e0b', '172033'));
    }

    private function contrastRatio(string $firstHex, string $secondHex): float
    {
        $firstLuminance = $this->relativeLuminance($firstHex);
        $secondLuminance = $this->relativeLuminance($secondHex);

        return (max($firstLuminance, $secondLuminance) + 0.05)
            / (min($firstLuminance, $secondLuminance) + 0.05);
    }

    private function relativeLuminance(string $hex): float
    {
        $channels = array_map(
            static function (string $channel): float {
                $component = hexdec($channel) / 255;

                return $component <= 0.04045
                    ? $component / 12.92
                    : (($component + 0.055) / 1.055) ** 2.4;
            },
            str_split($hex, 2),
        );

        return (0.2126 * $channels[0])
            + (0.7152 * $channels[1])
            + (0.0722 * $channels[2]);
    }
}
