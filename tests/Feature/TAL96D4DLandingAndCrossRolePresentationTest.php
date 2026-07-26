<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\FaqEntry;
use App\Models\Term;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\ApplicantPanelProvider;
use App\Providers\Filament\StudentPanelProvider;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TAL96D4DLandingAndCrossRolePresentationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_public_landing_explains_the_three_workspace_boundaries_without_script_dependent_headings(): void
    {
        $this->openAdmissions();

        $this->get('/')
            ->assertOk()
            ->assertSee('href="#main-content"', false)
            ->assertSee('id="main-content"', false)
            ->assertSee('One system. Three clear workspaces.')
            ->assertSee('Apply and track admission requirements.')
            ->assertSee('View enrollment, schedules, finance, and academic records.')
            ->assertSee('Manage verified school operations according to your role.')
            ->assertSee('LOGIN')
            ->assertSee('LOCATION')
            ->assertSee('ABOUT US')
            ->assertSee('FREQUENTLY ASKED QUESTIONS')
            ->assertSee(route('filament.applicant.auth.register'), false)
            ->assertSee(route('filament.applicant.auth.login'), false)
            ->assertSee(route('filament.student.auth.login'), false)
            ->assertSee(route('filament.admin.auth.login'), false)
            ->assertDontSee('typewriter', false)
            ->assertDontSee('image-placeholder-placeholder', false)
            ->assertDontSee('btn-blur', false)
            ->assertDontSee('card-blur', false);
    }

    public function test_public_landing_keeps_published_faq_entries_ordered_and_hides_drafts(): void
    {
        FaqEntry::query()->delete();

        FaqEntry::factory()->published()->create([
            'question' => 'Second public question?',
            'category' => FaqEntry::CategoryAccountLogin,
            'sort_order' => 2,
        ]);
        FaqEntry::factory()->published()->create([
            'question' => 'First public question?',
            'category' => FaqEntry::CategoryAdmissionEnrollment,
            'sort_order' => 1,
        ]);
        FaqEntry::factory()->create([
            'question' => 'Draft question?',
            'sort_order' => 0,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder([
                'First public question?',
                'Second public question?',
            ])
            ->assertSee(FaqEntry::categoryLabel(FaqEntry::CategoryAdmissionEnrollment))
            ->assertSee(FaqEntry::categoryLabel(FaqEntry::CategoryAccountLogin))
            ->assertSee('faq-category', false)
            ->assertDontSee('Draft question?');
    }

    public function test_official_output_component_owns_static_styles_without_blade_inside_css(): void
    {
        $layout = file_get_contents(resource_path('views/components/official-output-layout.blade.php'));
        $statement = file_get_contents(resource_path('views/finance/statement.blade.php'));
        $schedule = file_get_contents(resource_path('views/schedules/print.blade.php'));

        $this->assertIsString($layout);
        $this->assertIsString($statement);
        $this->assertIsString($schedule);
        $this->assertStringNotContainsString('{{ $styles', $layout);
        $this->assertStringNotContainsString('<x-slot:styles>', $statement);
        $this->assertStringNotContainsString('<x-slot:styles>', $schedule);
        $this->assertStringContainsString('.finance-grid', $layout);
        $this->assertStringContainsString('.schedule-table', $layout);
    }

    public function test_landing_retains_the_progressive_navigation_and_bottom_edge_blur(): void
    {
        $styles = file_get_contents(public_path('landing/css/styles.css'));

        $this->assertIsString($styles);
        $this->assertStringContainsString('.backdrop-blur > :nth-child(6)', $styles);
        $this->assertStringContainsString('.bottom-blur-strip > :nth-child(6)', $styles);
        $this->assertStringContainsString('backdrop-filter: blur(32px)', $styles);
        $this->assertMatchesRegularExpression(
            '/\.backdrop-blur\s*\{[^}]*z-index:\s*-1;/s',
            $styles,
            'The progressive navbar blur must remain behind the navigation content.',
        );
        $this->assertStringNotContainsString(
            '.backdrop-blur::after',
            $styles,
            'The navbar blur must not introduce an opaque overlay that changes the approved navigation.',
        );
        $this->assertStringContainsString('padding-top: 1.5rem !important;', $styles);
        $this->assertStringContainsString('padding-bottom: 1.5rem !important;', $styles);
        $this->assertStringContainsString('.navbar.navbar-light-theme .navbar-brand', $styles);
        $this->assertStringContainsString('.navbar.navbar-light-theme .navbar-nav .nav-link', $styles);
        $this->assertStringContainsString('.navbar.navbar-light-theme .navbar-toggler-icon', $styles);
    }

    public function test_tala_logo_surfaces_share_the_approved_brand_radius_and_access_numbers_stay_centered(): void
    {
        $landingStyles = file_get_contents(public_path('landing/css/styles.css'));
        $errorStyles = file_get_contents(public_path('css/tala-error.css'));
        $filamentStyles = file_get_contents(public_path('css/tala-filament.css'));
        $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));

        $this->assertIsString($landingStyles);
        $this->assertIsString($errorStyles);
        $this->assertIsString($filamentStyles);
        $this->assertIsString($provider);
        $this->assertGreaterThanOrEqual(3, substr_count($landingStyles, 'border-radius: 22.37%;'));
        $this->assertStringNotContainsString('.workspace-summary span {', $landingStyles);
        $this->assertStringContainsString('.workspace-summary span:not(.workspace-number)', $landingStyles);
        $this->assertMatchesRegularExpression('/\\.workspace-number\\s*\\{[^}]*width:\\s*2rem;/s', $landingStyles);
        $this->assertMatchesRegularExpression('/\\.workspace-number\\s*\\{[^}]*display:\\s*flex;/s', $landingStyles);
        $this->assertMatchesRegularExpression('/\\.workspace-number\\s*\\{[^}]*justify-content:\\s*center;/s', $landingStyles);
        $this->assertStringContainsString('border-radius: 22.37%;', $errorStyles);
        $this->assertStringContainsString('.fi-logo', $filamentStyles);
        $this->assertStringContainsString('tala-filament.css', $provider);
    }

    public function test_landing_content_actions_use_explicit_spacing_without_global_button_margins(): void
    {
        $landing = file_get_contents(resource_path('views/welcome.blade.php'));
        $styles = file_get_contents(public_path('landing/css/styles.css'));

        $this->assertIsString($landing);
        $this->assertIsString($styles);
        $this->assertMatchesRegularExpression(
            '/<div class="section-actions">\s*<a[^>]+>\s*Open in Google Maps/s',
            $landing,
        );
        $this->assertMatchesRegularExpression(
            '/\.section-actions\s*\{[^}]*margin-top:\s*1\.5rem;/s',
            $styles,
        );
        $this->assertStringNotContainsString('.btn { margin-top:', $styles);
    }

    public function test_role_workspaces_retain_consistent_tala_branding_and_distinct_names(): void
    {
        $admin = (new AdminPanelProvider($this->app))->panel(new Panel);
        $applicant = (new ApplicantPanelProvider($this->app))->panel(new Panel);
        $student = (new StudentPanelProvider($this->app))->panel(new Panel);

        $this->assertSame('TALA Staff Workspace', $admin->getBrandName());
        $this->assertSame('TALA Applicant Workspace', $applicant->getBrandName());
        $this->assertSame('TALA Student Hub', $student->getBrandName());
        $this->assertSame(asset('talalogo.png'), $admin->getBrandLogo());
        $this->assertSame($admin->getBrandLogo(), $applicant->getBrandLogo());
        $this->assertSame($admin->getBrandLogo(), $student->getBrandLogo());
        $this->assertSame(Color::Blue, $admin->getColors()['primary']);
        $this->assertSame($admin->getColors()['primary'], $applicant->getColors()['primary']);
        $this->assertSame($admin->getColors()['primary'], $student->getColors()['primary']);
    }

    private function openAdmissions(): CalendarEvent
    {
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
