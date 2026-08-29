<?php

namespace Tests\Feature;

use App\Actions\Applicants\ApplicantEntryReadinessService;
use App\Filament\Resources\FaqEntries\FaqEntryResource;
use App\Filament\Resources\PublicNotices\PublicNoticeResource;
use Caresome\FilamentAuthDesigner\AuthDesignerConfigRepository;
use Caresome\FilamentAuthDesigner\AuthDesignerPlugin;
use Caresome\FilamentAuthDesigner\Data\AuthPageConfig;
use Caresome\FilamentAuthDesigner\Enums\MediaPosition;
use Filament\Actions\Action;
use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HumanCenteredSharedThemeTest extends TestCase
{
    public function test_authentication_keeps_landmarks_recovery_and_context_without_staff_remember_device(): void
    {
        $response = $this->get('/admin/login')->assertOk()
            ->assertSee('Sign in to Staff')
            ->assertSee('<main', false)
            ->assertSee('Choose another workspace')
            ->assertSee('Contact school support')
            ->assertDontSee('Remember me')
            ->assertDontSee('Remember device');

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $recoveryLink = $xpath->query('//a[contains(@href, "/password-reset/request")]')->item(0);
        $this->assertNotNull($recoveryLink);
        $this->assertNotSame('-1', $recoveryLink->getAttribute('tabindex'));
        $this->assertSame(1, $xpath->query('//main')->length);

        $this->get('/student/login')->assertOk()->assertSee('Remember device');
    }

    public function test_closed_applicant_entry_does_not_offer_an_unavailable_registration_action(): void
    {
        $this->mock(ApplicantEntryReadinessService::class)
            ->shouldReceive('registrationIsAvailable')->andReturn(false);

        $response = $this->get('/applicant/login')->assertOk()
            ->assertDontSee('sign up for an account')
            ->assertSee('Check admission availability');

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//a[normalize-space(.)="Check admission availability"]')->length);
    }

    public function test_every_panel_preserves_native_system_theme_and_collapsible_navigation(): void
    {
        foreach (['applicant', 'student', 'admin'] as $panelId) {
            $panel = Filament::getPanel($panelId);

            $this->assertSame('resources/css/filament/tala/theme.css', $panel->getViteTheme());
            $this->assertTrue($panel->isSidebarCollapsibleOnDesktop());
            $this->assertTrue($panel->hasDarkMode());
            $this->assertFalse($panel->hasDarkModeForced());
            $this->assertSame(ThemeMode::System, $panel->getDefaultThemeMode());
            $this->assertNull($panel->getGlobalSearchProvider());
            $this->assertFalse($panel->isProfilePageSimple());
        }
    }

    #[DataProvider('panels')]
    public function test_contextual_login_keeps_native_auth_designer_layouts_and_tracked_media(string $panelId): void
    {
        $this->get('/'.$panelId.'/login')
            ->assertOk()
            ->assertSee('images/brand/servitech-crest.webp', false)
            ->assertSee('talalogo.png', false)
            ->assertSee('images/auth/'.$panelId.'.webp', false)
            ->assertSee($panelId === 'admin' ? 'media-left' : 'media-cover', false)
            ->assertDontSee('fi-auth-layout no-media', false)
            ->assertDontSee('storage/images/', false)
            ->assertSee('Enable light theme')
            ->assertSee('Enable dark theme')
            ->assertSee('Enable system theme')
            ->assertSee('autocomplete="username"', false);
        $this->get('/'.$panelId.'/login')->assertDontSee('href="'.asset('css/tala-filament.css').'"', false);
    }

    #[DataProvider('panels')]
    public function test_recovery_and_other_auth_states_inherit_the_same_panel_layout(string $panelId): void
    {
        $this->get('/'.$panelId.'/password-reset/request')
            ->assertOk()
            ->assertSee('images/auth/'.$panelId.'.webp', false)
            ->assertSee($panelId === 'admin' ? 'media-left' : 'media-cover', false);

        foreach (['login', 'registration', 'password-reset', 'email-verification'] as $page) {
            $config = app(AuthDesignerConfigRepository::class)->getConfig($page, $panelId);
            $this->assertSame($panelId === 'admin' ? MediaPosition::Left : MediaPosition::Cover, $config->position);
            $this->assertTrue($config->showThemeSwitcher);
            $this->assertSame('', $config->mediaAlt, 'Decorative media must not duplicate the form orientation.');
        }
    }

    public function test_public_gateway_initializes_and_follows_the_system_color_scheme(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee("window.matchMedia('(prefers-color-scheme: dark)')", false)
            ->assertSee("document.documentElement.setAttribute('data-bs-theme', theme)", false);

        $script = file_get_contents(public_path('landing/js/main.js'));

        $this->assertStringContainsString("colorScheme.addEventListener('change'", $script);
        $this->assertStringContainsString("event.matches ? 'dark' : 'light'", $script);
    }

    public function test_public_arrival_keeps_the_accepted_admission_and_connected_journey_hierarchy(): void
    {
        $view = file_get_contents(resource_path('views/welcome.blade.php'));
        $styles = file_get_contents(public_path('landing/css/styles.css'));

        $this->assertStringContainsString('id="admission-status-title"', $view);
        $this->assertStringContainsString('id="journey-title"', $view);
        $this->assertStringContainsString('class="learner-journey', $view);
        $this->assertStringContainsString('class="program-list', $view);
        $this->assertStringNotContainsString('class="workspace-card', $view);
        $this->assertStringNotContainsString('class="portal-overview', $view);
        $this->assertStringContainsString('class="dropdown public-sign-in', $view);
        $this->assertStringContainsString('font-size: 1.875rem;', $styles);
        $this->assertStringNotContainsString('6vw, 5.6rem', $styles);
        $this->assertStringContainsString('env(safe-area-inset-bottom)', $styles);
        $script = file_get_contents(public_path('landing/js/main.js'));
        $this->assertStringContainsString('window.bootstrap.Collapse.getOrCreateInstance', $script);
        $this->assertStringContainsString("navigation.addEventListener('hidden.bs.collapse'", $script);
        $this->assertStringContainsString('destination.focus({ preventScroll: true })', $script);
        $this->assertStringContainsString("navigation.querySelector('a[href]')?.focus({ preventScroll: true })", $script);
    }

    public function test_forced_colors_and_reduced_motion_preserve_native_operability(): void
    {
        $theme = file_get_contents(resource_path('css/filament/tala/theme.css'));

        $this->assertStringContainsString('@media (forced-colors: active)', $theme);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $theme);
        $this->assertStringContainsString('.fi-body :is(.fi-input-wrp, .fi-section, .fi-ta-ctn, .fi-modal-window, .fi-dropdown-panel) { border: 1px solid CanvasText; }', $theme);
        $this->assertStringContainsString('.fi-body :is(.fi-btn, .fi-icon-btn) { border: 1px solid ButtonText; }', $theme);
        $this->assertStringContainsString(".fi-body :is(input[type='checkbox'], input[type='radio']) { appearance: auto; border: 1px solid ButtonText; }", $theme);
        $this->assertStringContainsString('.fi-body .fi-auth-layout.has-media .fi-auth-content-section { width: 100%; }', $theme);
    }

    public function test_native_control_boundaries_use_the_shared_contrast_tokens(): void
    {
        $theme = file_get_contents(resource_path('css/filament/tala/theme.css'));
        $foundation = file_get_contents(public_path('css/tala-foundation.css'));

        $this->assertStringContainsString(".fi-input-wrp, .fi-body :is(input[type='checkbox'], input[type='radio']) { border: 1px solid var(--tala-control-border); }", $theme);
        $this->assertStringContainsString('--tala-control-border: #64748b;', $foundation);
        $this->assertStringContainsString('--tala-control-border: #94a3b8;', $foundation);
    }

    public function test_native_sidebar_keeps_collapsed_names_and_hides_the_closed_mobile_drawer(): void
    {
        $theme = file_get_contents(resource_path('css/filament/tala/theme.css'));
        $sidebar = file_get_contents(resource_path('views/filament/components/accessible-sidebar.blade.php'));

        $this->assertMatchesRegularExpression('/\.fi-sidebar:not\(\.fi-sidebar-open\) \.fi-sidebar-item-label\s*\{\s*@apply sr-only;\s*display: block !important;/s', $theme);
        $this->assertStringContainsString('x-bind:inert="mobile && ! $store.sidebar.isOpen"', $sidebar);
        $this->assertStringContainsString('x-on:resize.window.debounce.50ms=', $sidebar);
        $this->assertStringContainsString('x-on:resize.window="if (window.innerWidth >= 1024) mobile = false"', $sidebar);
        $this->assertStringContainsString('x-trap.inert.noscroll.noreturn=', $sidebar);
        $this->assertStringContainsString("document.querySelector('.fi-topbar-open-sidebar-btn')?.focus", $sidebar);
        $this->assertStringContainsString('.fi-sidebar .fi-logo,', $theme);
        $this->assertStringContainsString("@include('filament-panels::livewire.sidebar')", $sidebar);
    }

    public function test_panel_skip_link_and_action_modals_restore_keyboard_focus(): void
    {
        $skipLink = file_get_contents(resource_path('views/filament/components/skip-link.blade.php'));
        $this->assertStringContainsString("document.getElementById('tala-main-content')?.focus({ preventScroll: true })", $skipLink);
        $this->assertStringContainsString('rememberActionGroup(actionGroup)', $skipLink);
        $this->assertStringContainsString('window.talaActionGroupReturnHref', $skipLink);
        $this->assertStringContainsString("window.addEventListener('modal-closed'", $skipLink);
        $this->assertStringContainsString('window.talaFocusRestorePending', $skipLink);
        $this->assertStringContainsString("window.addEventListener('transitionend'", $skipLink);
        $this->assertStringContainsString('window.setTimeout(window.talaRestoreActionGroupFocus, 0)', $skipLink);
        $this->assertStringContainsString('target.focus({ preventScroll: true })', $skipLink);
    }

    public function test_public_content_has_flat_notices_and_faq_tabs(): void
    {
        $this->assertNull(PublicNoticeResource::getNavigationGroup());
        $this->assertNull(FaqEntryResource::getNavigationGroup());
        $this->assertSame('Notices', PublicNoticeResource::getNavigationLabel());
        $this->assertSame('FAQ', FaqEntryResource::getNavigationLabel());
    }

    public function test_native_action_modals_connect_their_existing_heading_to_the_dialog(): void
    {
        $attributes = Action::make('preview')->getExtraModalWindowAttributes();

        $this->assertArrayHasKey('x-effect', $attributes);
        $this->assertStringContainsString("getAttribute('aria-labelledby')", $attributes['x-effect']);
        $this->assertStringContainsString("querySelector('.fi-modal-heading')", $attributes['x-effect']);
        $this->assertStringContainsString('! document.getElementById(headingId)', $attributes['x-effect']);
        $this->assertStringContainsString('heading.id = headingId', $attributes['x-effect']);
    }

    #[DataProvider('panels')]
    public function test_auth_media_is_small_and_missing_media_uses_the_native_fallback(string $panelId): void
    {
        $asset = public_path('images/auth/'.$panelId.'.webp');
        $this->assertFileExists($asset);
        $this->assertLessThan(300000, filesize($asset));
        $this->assertSame('image/webp', getimagesize($asset)['mime']);

        $originalPublicPath = public_path();
        $this->app->usePublicPath(base_path('tests/fixtures/missing-public-assets'));

        try {
            $plugin = Filament::getPanel($panelId)->getPlugin('auth-designer');
            $this->assertInstanceOf(AuthDesignerPlugin::class, $plugin);
            $configure = $plugin->getDefaultsConfigurator();
            $this->assertNotNull($configure);
            $config = $configure(new AuthPageConfig);
            $this->assertNull($config->getMedia());
            $this->assertNull($config->getEffectivePosition());
        } finally {
            $this->app->usePublicPath($originalPublicPath);
        }
    }

    /** @return array<string, array{string}> */
    public static function panels(): array
    {
        return ['Applicant' => ['applicant'], 'Student' => ['student'], 'Staff' => ['admin']];
    }
}
