<?php

namespace Tests\Feature;

use App\Models\AdmissionCycle;
use App\Models\FaqEntry;
use App\Models\Term;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\ApplicantPanelProvider;
use App\Providers\Filament\StudentPanelProvider;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Illuminate\Contracts\View\View;
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
            ->assertSee('One connected learner journey')
            ->assertSee('Create and verify your Applicant account.')
            ->assertSee('View enrollment, schedules, finance, and academic records.')
            ->assertSee('Manage verified school operations according to your role.')
            ->assertSee('Sign in to your workspace')
            ->assertSee('Frequently asked questions')
            ->assertDontSee('OUR MISSION')
            ->assertDontSee('<iframe', false)
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
        $landing = file_get_contents(resource_path('views/welcome.blade.php'));
        $script = file_get_contents(public_path('landing/js/main.js'));
        $styles = file_get_contents(public_path('landing/css/styles.css'));

        $this->assertIsString($landing);
        $this->assertIsString($script);
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
        $this->assertStringContainsString('padding-top: 1rem !important;', $styles);
        $this->assertStringContainsString('padding-bottom: 1rem !important;', $styles);
        $this->assertGreaterThanOrEqual(5, substr_count($landing, 'data-navbar-contrast-target'));
        $this->assertStringContainsString('data-navbar-contrast-surface="dark"', $landing);
        $this->assertStringContainsString('class="admission-status" data-navbar-contrast-surface="theme"', $landing);
        $this->assertStringContainsString('data-navbar-contrast-surface="theme"', $landing);
        $this->assertStringContainsString('document.elementsFromPoint', $script);
        $this->assertStringContainsString('window.requestAnimationFrame', $script);
        $this->assertStringContainsString('data-navbar-foreground', $script);
        $this->assertStringContainsString('[data-navbar-foreground="black"]', $styles);
        $this->assertStringContainsString('[data-navbar-foreground="white"]', $styles);
        $this->assertStringContainsString('color: #000000 !important;', $styles);
        $this->assertStringContainsString('color: #ffffff !important;', $styles);
        $this->assertStringNotContainsString('navbar-light-theme', $script);
        $this->assertStringNotContainsString('navbar-light-theme', $styles);
    }

    public function test_tala_logo_surfaces_share_the_approved_brand_radius_and_journey_does_not_fake_progress(): void
    {
        $landingStyles = file_get_contents(public_path('landing/css/styles.css'));
        $errorStyles = file_get_contents(public_path('css/tala-error.css'));
        $filamentStyles = file_get_contents(public_path('css/tala-filament.css'));
        $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));

        $this->assertIsString($landingStyles);
        $this->assertIsString($errorStyles);
        $this->assertIsString($filamentStyles);
        $this->assertIsString($provider);
        $this->assertGreaterThanOrEqual(2, substr_count($landingStyles, 'border-radius: 22.37%;'));
        $landing = file_get_contents(resource_path('views/welcome.blade.php'));
        $this->assertStringContainsString('class="learner-journey row', $landing);
        $this->assertStringNotContainsString('aria-current="step"', $landing);
        $this->assertStringNotContainsString('role="progressbar"', $landing);
        $this->assertStringContainsString('border-radius: 22.37%;', $errorStyles);
        $this->assertStringContainsString('.fi-logo', $filamentStyles);
        $this->assertStringNotContainsString("Css::make('tala-panel-brand'", $provider);
        $this->assertStringContainsString('tala-filament.css', file_get_contents(resource_path('css/filament/tala/theme.css')));
    }

    public function test_landing_keeps_external_location_guidance_without_an_embedded_map_or_global_button_margins(): void
    {
        $landing = file_get_contents(resource_path('views/welcome.blade.php'));
        $styles = file_get_contents(public_path('landing/css/styles.css'));

        $this->assertIsString($landing);
        $this->assertIsString($styles);
        $this->assertStringContainsString('Open in Google Maps', $landing);
        $this->assertStringNotContainsString('<iframe', $landing);
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
        foreach ([$admin, $applicant, $student] as $panel) {
            $logo = $panel->getBrandLogo();
            $this->assertInstanceOf(View::class, $logo);
            $markup = $logo->render();

            $this->assertStringContainsString(asset('talalogo.png'), $markup);
            $this->assertStringContainsString(asset('images/brand/servitech-crest.webp'), $markup);
            $this->assertStringContainsString($panel->getBrandName(), $markup);
            $this->assertStringContainsString('alt="Servitech Institute Asia"', $markup);
        }

        $this->assertSame(array_replace(Color::Blue, [600 => '#1D4ED8', 700 => '#1E3A8A']), $admin->getColors()['primary']);
        $this->assertSame($admin->getColors()['primary'], $applicant->getColors()['primary']);
        $this->assertSame($admin->getColors()['primary'], $student->getColors()['primary']);
    }

    private function openAdmissions(): AdmissionCycle
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);

        return AdmissionCycle::factory()->for($term)->published()->create([
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addDay(),
        ]);
    }
}
