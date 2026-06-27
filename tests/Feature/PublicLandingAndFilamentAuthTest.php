<?php

namespace Tests\Feature;

use App\Filament\Applicant\Pages\Auth\RegisterApplicant;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\ApplicantPanelProvider;
use App\Providers\Filament\StudentPanelProvider;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use ReflectionMethod;
use Tests\TestCase;

class PublicLandingAndFilamentAuthTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_public_landing_page_renders_with_filament_auth_ctas(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('TALA')
            ->assertSee('Apply Online')
            ->assertSee('Sign In')
            ->assertSee(route('filament.applicant.auth.register'), false)
            ->assertSee(route('filament.applicant.auth.login'), false)
            ->assertSee(route('filament.student.auth.login'), false)
            ->assertSee(route('filament.admin.auth.login'), false)
            ->assertSee('Senior High School')
            ->assertSee('College Programs')
            ->assertDontSee('Student Registration');
    }

    public function test_public_fortify_auth_view_routes_are_not_exposed(): void
    {
        $this->get('/login')->assertMethodNotAllowed();
        $this->get('/register')->assertMethodNotAllowed();
        $this->get('/forgot-password')->assertMethodNotAllowed();
        $this->get('/email/verify')->assertNotFound();
    }

    public function test_filament_panel_auth_routes_render(): void
    {
        $this->get(route('filament.admin.auth.login'))->assertOk();
        $this->get(route('filament.applicant.auth.login'))->assertOk();
        $this->get(route('filament.student.auth.login'))->assertOk();
        $this->get(route('filament.applicant.auth.register'))
            ->assertOk()
            ->assertSee('Create Applicant Account')
            ->assertSee('Apply Online');
    }

    public function test_role_filament_panels_share_the_admin_primary_color(): void
    {
        $this->assertSame(Color::Blue, $this->configuredPrimaryColor(AdminPanelProvider::class));
        $this->assertSame(Color::Blue, $this->configuredPrimaryColor(ApplicantPanelProvider::class));
        $this->assertSame(Color::Blue, $this->configuredPrimaryColor(StudentPanelProvider::class));
    }

    public function test_applicant_filament_registration_assigns_applicant_role(): void
    {
        $page = app(RegisterApplicant::class);
        $method = new ReflectionMethod(RegisterApplicant::class, 'handleRegistration');
        $method->setAccessible(true);

        $user = $method->invoke($page, [
            'name' => 'Test Applicant',
            'email' => 'test-applicant@example.test',
            'password' => 'secret-password',
        ]);

        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue($user->hasRole('applicant'));
        $this->assertTrue(Hash::check('secret-password', $user->password));
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
}
