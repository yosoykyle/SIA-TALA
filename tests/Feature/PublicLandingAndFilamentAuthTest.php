<?php

namespace Tests\Feature;

use App\Filament\Applicant\Pages\Auth\RegisterApplicant;
use App\Models\AdmissionCycle;
use App\Models\Term;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\ApplicantPanelProvider;
use App\Providers\Filament\StudentPanelProvider;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class PublicLandingAndFilamentAuthTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_public_landing_page_renders_with_filament_auth_ctas(): void
    {
        $this->openAdmissions();

        $this->get('/')
            ->assertOk()
            ->assertSee('TALA')
            ->assertSee('class="tala-skip-link" href="#main-content"', false)
            ->assertSee('class="tala-skip-link__icon"', false)
            ->assertSee('<main id="main-content" tabindex="-1">', false)
            ->assertSee('Create Applicant account')
            ->assertSee('Applicant sign in')
            ->assertSee('Student sign in')
            ->assertSee('Staff sign in')
            ->assertSee(route('filament.applicant.auth.register'), false)
            ->assertSee(route('filament.applicant.auth.login'), false)
            ->assertSee(route('filament.student.auth.login'), false)
            ->assertSee(route('filament.admin.auth.login'), false)
            ->assertSee(asset('landing/vendor/bootstrap/css/bootstrap.min.css'), false)
            ->assertSee(asset('landing/css/styles.css'), false)
            ->assertSee('tala-public-icon', false)
            ->assertDontSee('bootstrap-icons.min.css', false)
            ->assertDontSee('class="bi ', false)
            ->assertSee('data-bs-toggle="dropdown"', false)
            ->assertSee('data-bs-target="#privacyModal"', false)
            ->assertSee('modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down', false)
            ->assertSee('Open in Google Maps')
            ->assertDontSee('<iframe', false)
            ->assertSee('Applicant Workspace')
            ->assertSee('Student Hub')
            ->assertDontSee('Student Registration')
            ->assertDontSee('applicant.login')
            ->assertDontSee('student.login')
            ->assertDontSee('applicant.register')
            ->assertDontSee('href="#"', false);

        $landingCss = file_get_contents(public_path('landing/css/styles.css'));
        $foundationCss = file_get_contents(public_path('css/tala-foundation.css'));
        $hiddenSkipLink = $this->cssRule($foundationCss, '.tala-skip-link');
        $keyboardFocusedSkipLink = $this->cssRule($foundationCss, '.tala-skip-link:focus-visible');

        $this->assertStringNotContainsString('.skip-link', $landingCss);
        $this->assertStringContainsString('background: var(--tala-surface);', $hiddenSkipLink);
        $this->assertStringContainsString('color: var(--tala-link);', $hiddenSkipLink);
        $this->assertStringContainsString('inset-inline-start: 1rem;', $hiddenSkipLink);
        $this->assertStringContainsString('opacity: 0;', $hiddenSkipLink);
        $this->assertStringContainsString('pointer-events: none;', $hiddenSkipLink);
        $this->assertStringContainsString('transform: translateY(calc(-100% - 2rem - env(safe-area-inset-top)));', $hiddenSkipLink);
        $this->assertStringContainsString('z-index: 1100;', $hiddenSkipLink);
        $this->assertStringContainsString('opacity: 1;', $keyboardFocusedSkipLink);
        $this->assertStringContainsString('pointer-events: auto;', $keyboardFocusedSkipLink);
        $this->assertStringContainsString('transform: translateY(0);', $keyboardFocusedSkipLink);
        $this->assertStringNotContainsString('.tala-skip-link:focus {', $foundationCss);

        $landingJavaScript = file_get_contents(public_path('landing/js/main.js'));
        $this->assertStringContainsString("document.querySelector('.tala-skip-link')", $landingJavaScript);
        $this->assertStringContainsString('document.getElementById(skipLink.hash.slice(1))?.focus({ preventScroll: true })', $landingJavaScript);
    }

    public function test_public_fortify_auth_view_routes_are_not_exposed(): void
    {
        $this->get('/login')->assertMethodNotAllowed();
        $this->get('/register')->assertNotFound();
        $this->get('/forgot-password')->assertMethodNotAllowed();
        $this->get('/email/verify')->assertNotFound();
    }

    public function test_filament_panel_auth_routes_render(): void
    {
        $this->openAdmissions();

        $this->get(route('filament.admin.auth.login'))->assertOk();
        $this->get(route('filament.applicant.auth.login'))->assertOk();
        $this->get(route('filament.student.auth.login'))->assertOk();
        $this->get(route('filament.applicant.auth.register'))
            ->assertOk()
            ->assertSee('Create Applicant Account')
            ->assertSee('Create account')
            ->assertSee(route('home', ['modal' => 'privacy']), false);
    }

    public function test_role_filament_panels_share_the_admin_primary_color(): void
    {
        $institutionalBlue = array_replace(Color::Blue, [600 => '#1D4ED8', 700 => '#1E3A8A']);

        $this->assertSame($institutionalBlue, $this->configuredPrimaryColor(AdminPanelProvider::class));
        $this->assertSame($institutionalBlue, $this->configuredPrimaryColor(ApplicantPanelProvider::class));
        $this->assertSame($institutionalBlue, $this->configuredPrimaryColor(StudentPanelProvider::class));
    }

    public function test_applicant_filament_registration_assigns_applicant_role(): void
    {
        $this->openAdmissions();

        $page = app(RegisterApplicant::class);
        $method = new ReflectionMethod(RegisterApplicant::class, 'handleRegistration');
        $method->setAccessible(true);

        $user = $method->invoke($page, [
            'email' => 'test-applicant@example.test',
            'password' => 'a valid passphrase',
            'password_confirmation' => 'a valid passphrase',
            'privacy_acknowledged' => true,
        ]);

        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue($user->hasRole('applicant'));
        $this->assertTrue(Hash::check('a valid passphrase', $user->password));
    }

    /**
     * @param  class-string  $panelProviderClass
     * @return array<int | string, string | int> | string
     */
    private function configuredPrimaryColor(string $panelProviderClass): array|string
    {
        return (new $panelProviderClass($this->app))
            ->panel(new Panel)
            ->getColors()['primary'];
    }

    private function openAdmissions(): AdmissionCycle
    {
        Http::fake([
            'api.pwnedpasswords.com/*' => Http::response('', 200),
        ]);

        $term = Term::factory()->create(['state' => Term::StateActive]);

        return AdmissionCycle::factory()->for($term)->published()->create([
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addDay(),
        ]);
    }

    private function cssRule(string $css, string $selector): string
    {
        $matched = preg_match('/'.preg_quote($selector, '/').'\s*\{([^}]+)\}/', $css, $matches);

        $this->assertSame(1, $matched, "Expected CSS rule {$selector} was not found.");

        return $matches[1];
    }
}
