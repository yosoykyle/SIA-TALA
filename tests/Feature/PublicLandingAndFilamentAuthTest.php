<?php

namespace Tests\Feature;

use App\Filament\Applicant\Pages\Auth\RegisterApplicant;
use App\Models\CalendarEvent;
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
            ->assertSee('Create Applicant Account')
            ->assertSee('Applicant Sign In')
            ->assertSee('Student Sign In')
            ->assertSee('Staff Sign In')
            ->assertSee('Applicant Login')
            ->assertSee('Student Login')
            ->assertSee('Staff Login')
            ->assertSee(route('filament.applicant.auth.register'), false)
            ->assertSee(route('filament.applicant.auth.login'), false)
            ->assertSee(route('filament.student.auth.login'), false)
            ->assertSee(route('filament.admin.auth.login'), false)
            ->assertSee(asset('landing/vendor/bootstrap/css/bootstrap.min.css'), false)
            ->assertSee(asset('landing/css/styles.css'), false)
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
        $this->assertSame(Color::Blue, $this->configuredPrimaryColor(AdminPanelProvider::class));
        $this->assertSame(Color::Blue, $this->configuredPrimaryColor(ApplicantPanelProvider::class));
        $this->assertSame(Color::Blue, $this->configuredPrimaryColor(StudentPanelProvider::class));
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

    private function openAdmissions(): CalendarEvent
    {
        Http::fake([
            'api.pwnedpasswords.com/*' => Http::response('', 200),
        ]);

        $term = Term::factory()->create(['state' => Term::StateActive]);

        return CalendarEvent::factory()->for($term)->create([
            'event_type' => CalendarEvent::TypeWindow,
            'scope_type' => CalendarEvent::ScopeInstitution,
            'process_key' => CalendarEvent::ProcessAdmissions,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'day_of_week' => null,
            'starts_at' => null,
            'ends_at' => null,
            'blocks_scheduling' => false,
            'state' => CalendarEvent::StateActive,
        ]);
    }
}
