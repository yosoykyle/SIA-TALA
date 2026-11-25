<?php

namespace Tests\Feature;

use App\Actions\Applicants\ApplicantEntryReadinessService;
use App\Filament\Resources\FaqEntries\FaqEntryResource;
use App\Filament\Resources\PublicNotices\PublicNoticeResource;
use App\Support\WorkspaceNavigationSearchProvider;
use Caresome\FilamentAuthDesigner\AuthDesignerConfigRepository;
use Caresome\FilamentAuthDesigner\AuthDesignerPlugin;
use Caresome\FilamentAuthDesigner\Data\AuthPageConfig;
use Caresome\FilamentAuthDesigner\Enums\MediaPosition;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HumanCenteredSharedThemeTest extends TestCase
{
    public function test_authentication_keeps_landmarks_recovery_and_context_without_staff_remember_device(): void
    {
        $response = $this->get('/admin/login')->assertOk()
            ->assertSee('Sign in')
            ->assertSee('<main', false)
            ->assertSee('Back to school site')
            ->assertSee('Contact support')
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
            if ($panelId === 'applicant') {
                $this->assertNull($panel->getGlobalSearchProvider());
            } else {
                $this->assertInstanceOf(WorkspaceNavigationSearchProvider::class, $panel->getGlobalSearchProvider());
            }
            $this->assertFalse($panel->isProfilePageSimple());
        }
    }

    public function test_recurring_native_actions_share_purpose_icons_and_allow_a_task_specific_override(): void
    {
        foreach ([
            [CreateAction::make(), Heroicon::Plus],
            [EditAction::make(), Heroicon::PencilSquare],
            [ViewAction::make(), Heroicon::Eye],
            [DeleteAction::make(), Heroicon::Trash],
            [Action::make('recordAcademicDecision'), Heroicon::Plus],
            [Action::make('save'), Heroicon::Check],
            [Action::make('apply'), Heroicon::Check],
            [Action::make('cancel'), Heroicon::XMark],
        ] as [$action, $icon]) {
            $this->assertSame($icon, $action->getIcon());
        }

        $this->assertSame(Heroicon::AcademicCap, CreateAction::make()->icon(Heroicon::AcademicCap)->getIcon());
        $this->assertNull(CreateAction::make()->icon(null)->getIcon());
    }

    #[DataProvider('panels')]
    public function test_panel_links_share_native_loading_navigation_without_intercepting_outputs_or_account_switches(string $panelId): void
    {
        $panel = Filament::getPanel($panelId);
        Filament::setCurrentPanel($panel);
        Filament::bootCurrentPanel();

        $this->assertTrue($panel->hasSpaMode());
        $this->assertFalse($panel->hasSpaPrefetching());
        $this->assertSame('var(--tala-brand-foreground)', config('livewire.navigate.progress_bar_color'));
        $this->assertTrue(config('livewire.navigate.show_progress_bar'));

        $internalUrl = url('/'.$panelId.'/records');
        $link = Blade::render('<x-workspace-link :href="$href" icon="heroicon-o-document-text" label="Open record" />', ['href' => $internalUrl]);
        $this->assertStringContainsString('wire:navigate', $link);

        foreach ([
            url('/outputs/cor/1'),
            url('/finance/exports/1'),
            url('/admissions/evidence/1'),
            url('/workspace-chooser'),
            url('/'.$panelId.'/login'),
            url('/'.$panelId.'/password-reset/request'),
            url('/'.($panelId === 'admin' ? 'student' : 'admin').'/login'),
            'https://example.org/help',
        ] as $normalUrl) {
            $this->assertStringNotContainsString('wire:navigate', (string) \Filament\Support\generate_href_html($normalUrl));
        }

        $this->assertStringNotContainsString('wire:navigate', (string) \Filament\Support\generate_href_html($internalUrl, true));
    }

    #[DataProvider('panels')]
    public function test_panels_render_the_shared_unsaved_navigation_confirmation(string $panelId): void
    {
        Filament::setCurrentPanel(Filament::getPanel($panelId));
        Filament::bootCurrentPanel();

        $scripts = (string) FilamentView::renderHook(PanelsRenderHook::SCRIPTS_AFTER);
        $this->assertStringContainsString('js/tala-unsaved-changes.js', $scripts);
        $this->assertStringContainsString('data-navigate-once', $scripts);
        $unsavedChangesJs = file_get_contents(public_path('js/tala-unsaved-changes.js'));
        $this->assertStringContainsString('.fi-auth-theme-switcher-wrapper:not([x-data])', $unsavedChangesJs);
        $this->assertStringContainsString("wrapper.setAttribute('x-data', '{ close() {} }')", $unsavedChangesJs);
        $this->assertStringContainsString("backdrop.setAttribute('data-auth-error-pulse', 'peak')", $unsavedChangesJs);
        $this->assertStringContainsString("backdrop.setAttribute('data-auth-error-pulse', 'settle')", $unsavedChangesJs);
        $this->assertStringContainsString("backdrop.setAttribute('data-auth-state', 'loading')", $unsavedChangesJs);
        $this->assertStringContainsString("backdrop.setAttribute('data-auth-state', 'success')", $unsavedChangesJs);
        $this->assertStringNotContainsString("backdrop.setAttribute('data-auth-error'", $unsavedChangesJs);

        $modal = Blade::render("@include('filament.components.unsaved-changes-confirmation')");
        $this->assertStringContainsString('Leave without saving?', $modal);
        $this->assertStringContainsString('Keep editing', $modal);
        $this->assertStringContainsString('Leave page', $modal);
        $this->assertStringContainsString('tala-unsaved-changes.heading', $modal);
    }

    #[DataProvider('panels')]
    public function test_contextual_login_keeps_native_auth_designer_layouts_and_tracked_media(string $panelId): void
    {
        $this->get('/'.$panelId.'/login')
            ->assertOk()
            ->assertSee('images/brand/servitech-crest-outlined-192.webp', false)
            ->assertDontSee('talalogo.png', false)
            ->assertDontSee('Powered by TALA')
            ->assertSee('images/auth/admin.webp', false)
            ->assertSee('media-top', false)
            ->assertDontSee('fi-auth-layout no-media', false)
            ->assertDontSee('storage/images/', false)
            ->assertSee('Enable light theme')
            ->assertSee('Enable dark theme')
            ->assertSee('Enable system theme')
            ->assertSee('autocomplete="username"', false)
            ->assertSee('class="tala-ambient tala-ambient--auth"', false)
            ->assertSee('class="tala-ambient-wash"', false)
            ->assertSee('data-band="top"', false)
            ->assertSee('data-band="bottom"', false);
        $this->get('/'.$panelId.'/login')->assertDontSee('href="'.asset('css/tala-filament.css').'"', false);
    }

    #[DataProvider('panels')]
    public function test_recovery_and_other_auth_states_inherit_the_same_panel_layout(string $panelId): void
    {
        $this->get('/'.$panelId.'/password-reset/request')
            ->assertOk()
            ->assertSee('images/auth/admin.webp', false)
            ->assertSee('media-top', false)
            ->assertSee('Forgot password?')
            ->assertSee('Enter your email address and we will send a password reset link if your account is eligible.')
            ->assertSee('Send reset link')
            ->assertSee('Back to sign in')
            ->assertSee('autocomplete="email"', false)
            ->assertSee('novalidate', false)
            ->assertDontSee('back to login')
            ->assertDontSee('Send email');

        foreach (['login', 'registration', 'password-reset', 'email-verification'] as $page) {
            $config = app(AuthDesignerConfigRepository::class)->getConfig($page, $panelId);
            $this->assertSame(MediaPosition::Top, $config->position);
            $this->assertTrue($config->showThemeSwitcher);
            $this->assertSame('', $config->mediaAlt, 'Decorative media must not duplicate the form orientation.');
        }
    }

    public function test_public_gateway_initializes_and_follows_the_system_color_scheme(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee("window.matchMedia('(prefers-color-scheme: dark)')", false)
            ->assertSee("root.setAttribute('data-bs-theme', theme)", false);

        $script = file_get_contents(public_path('landing/js/main.js'));

        $this->assertStringContainsString("colorScheme.addEventListener('change'", $script);
        $this->assertStringContainsString('applyTheme(event.matches', $script);
        $this->assertStringContainsString("const theme = isDark ? 'dark' : 'light'", $script);
    }

    public function test_public_arrival_keeps_school_discovery_and_guarded_access_with_native_navigation(): void
    {
        $view = file_get_contents(resource_path('views/welcome.blade.php'));
        $styles = file_get_contents(public_path('landing/css/styles.css'));

        $this->assertStringContainsString('id="admission-status-title"', $view);
        $this->assertStringContainsString('class="program-list', $view);
        $this->assertStringNotContainsString('class="workspace-card', $view);
        $this->assertStringNotContainsString('class="portal-overview', $view);
        $this->assertStringContainsString('public-sign-in', $view);
        $this->assertStringNotContainsString('id="application-status"', $view);
        $this->assertStringNotContainsString('dropdown public-sign-in', $view);
        $this->assertStringNotContainsString('6vw, 5.6rem', $styles);
        $this->assertStringContainsString('env(safe-area-inset-bottom)', $styles);
        $script = file_get_contents(public_path('landing/js/main.js'));
        $this->assertStringContainsString('window.bootstrap.Collapse.getOrCreateInstance', $script);
        $this->assertStringContainsString("navigation.addEventListener('hidden.bs.collapse'", $script);
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

        $this->assertStringContainsString(".fi-body :is(input[type='checkbox'], input[type='radio'])", $theme);
        $this->assertStringContainsString('border: 1px solid var(--tala-control-border);', $theme);
        $this->assertStringContainsString('.fi-body .fi-input-wrp:not(.fi-disabled, .fi-invalid):not(:focus-within)', $theme);
        $this->assertStringContainsString('--tw-ring-color: var(--tala-control-border);', $theme);
        $this->assertStringContainsString('--tala-control-border: #71717b;', $foundation);
        $this->assertStringContainsString('--tala-control-border: #a1a1aa;', $foundation);
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
        $this->assertStringContainsString("document.querySelector('.fi-layout-sidebar-toggle-btn, .fi-topbar-open-sidebar-btn')?.focus", $sidebar);
        $this->assertStringContainsString("@include('filament-panels::livewire.sidebar')", $sidebar);
    }

    public function test_action_modals_restore_focus_without_adding_a_duplicate_panel_skip_link(): void
    {
        $skipLink = file_get_contents(resource_path('views/filament/components/skip-link.blade.php'));
        $this->assertStringNotContainsString('<a ', $skipLink);
        $this->assertStringContainsString('hidden aria-hidden="true"', $skipLink);
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
        $this->assertSame('Announcements', PublicNoticeResource::getNavigationLabel());
        $this->assertSame('FAQs', FaqEntryResource::getNavigationLabel());
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

    public function test_simple_layout_user_menu_and_ambient_backdrop_avoid_rectangular_button_shadow_and_reduce_motion(): void
    {
        $foundationCss = file_get_contents(public_path('css/tala-foundation.css'));
        $themeCss = file_get_contents(resource_path('css/filament/tala/theme.css'));

        $this->assertStringContainsString('.fi-sidebar .fi-user-menu-trigger', $foundationCss);
        $this->assertStringNotContainsString("\n.fi-user-menu-trigger,", $foundationCss);
        $this->assertStringContainsString('.fi-simple-layout-header .fi-user-menu-trigger', $themeCss);
        $this->assertStringContainsString('border-radius: 9999px;', $themeCss);
        $this->assertStringContainsString('.fi-simple-main::before', $themeCss);
        $this->assertStringContainsString('.tala-ambient, .tala-ambient * { animation: none !important; }', $themeCss);
        $this->assertStringContainsString('.tala-auth-main:has(.media-top.has-media) > .tala-ambient--auth', $themeCss);
        $this->assertStringContainsString('.tala-ambient-wash', $themeCss);
        $this->assertStringContainsString('.tala-ambient-trinket--edge[data-band="top"]', $themeCss);
        $this->assertStringContainsString('.tala-ambient-trinket--edge[data-band="bottom"]', $themeCss);
        $this->assertStringContainsString('@keyframes tala-ambient-wash-drift', $themeCss);
        $this->assertStringContainsString('@keyframes tala-ambient-orbit-a', $themeCss);
        $this->assertStringNotContainsString('.tala-auth-main:has(.fi-invalid, .fi-fo-field-wrp-error-message, [aria-invalid="true"]) > .tala-ambient--auth', $themeCss);
        $this->assertStringNotContainsString('.tala-ambient--auth[data-auth-error="true"]', $themeCss);
        $this->assertStringContainsString('.tala-ambient--auth[data-auth-error-pulse="peak"]', $themeCss);
        $this->assertStringContainsString('.tala-ambient--auth[data-auth-error-pulse="settle"]', $themeCss);
        $this->assertStringContainsString('.tala-ambient--auth[data-auth-state="loading"] .tala-ambient-wash', $themeCss);
        $this->assertStringContainsString('.tala-ambient--auth[data-auth-state="success"]', $themeCss);
        $this->assertStringContainsString('@keyframes tala-auth-error-glow-pulse', $themeCss);
        $this->assertStringContainsString('@keyframes tala-auth-error-trinket-pulse', $themeCss);
        $this->assertStringContainsString('@keyframes tala-auth-loading-wash-sweep', $themeCss);
        $this->assertStringContainsString('@keyframes tala-auth-success-trinket-celebrate', $themeCss);
        $this->assertStringContainsString('.fi-body:has(.tala-auth-main .media-top.has-media) .fi-auth-theme-switcher-wrapper', $themeCss);
        $this->assertStringContainsString('calc(var(--tala-auth-topbar-inset) + ((var(--tala-auth-topbar-height) - var(--tala-auth-switcher-height)) / 2))', $themeCss);
        $this->assertStringContainsString('.tala-verification-card', $themeCss);
        $this->assertStringContainsString('.tala-auth-verify-card', $themeCss);
        $this->assertStringContainsString('.tala-auth-back-link', $themeCss);
        $this->assertStringContainsString('.fi-body :is(.tala-auth-main, .fi-auth-layout) .fi-simple-header-heading', $themeCss);
        $this->assertStringContainsString('.fi-body .fi-input-wrp:is(.fi-invalid, :has(:is(input, textarea, select):is(:user-invalid, [aria-invalid="true"])))', $themeCss);
        $this->assertStringContainsString('.tala-auth-slideshow__slide.is-active', $themeCss);
        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\)\s*\{\s*\.tala-auth-slideshow__slide,\s*\.tala-auth-slideshow__slide\.is-active\s*\{[^}]*transition:\s*none !important;/s', $themeCss);
    }

    public function test_mfa_modals_pin_sunken_header_and_footer_within_dynamic_viewport_height(): void
    {
        $themeCss = file_get_contents(resource_path('css/filament/tala/theme.css'));

        $this->assertMatchesRegularExpression('/\.fi-body\s+\.fi-modal-window\.tala-mfa-modal\s*\{[^}]*max-block-size:\s*calc\(100dvh/s', $themeCss);
        $this->assertMatchesRegularExpression('/\.fi-body\s+\.fi-modal-window\.tala-mfa-modal\s*>\s*\.fi-modal-header\s*\{[^}]*background-color:\s*var\(--tala-surface-sunken\);/s', $themeCss);
        $this->assertMatchesRegularExpression('/\.fi-body\s+\.fi-modal-window\.tala-mfa-modal\s*>\s*\.fi-modal-footer,\s*\.fi-body\s+\.fi-modal-window\.tala-mfa-modal\s+\.fi-sc-wizard-footer\s*\{[^}]*background-color:\s*var\(--tala-surface-sunken\);/s', $themeCss);
        $this->assertMatchesRegularExpression('/\.fi-body\s+\.fi-modal-window\.tala-mfa-modal\s*>\s*\.fi-modal-content\s*\{[^}]*overflow-y:\s*auto;/s', $themeCss);
        $this->assertMatchesRegularExpression('/\.fi-body\s+\.fi-modal-window\.tala-mfa-modal\s+\.fi-sc-wizard-step\.fi-active\s*\{[^}]*overflow-y:\s*auto;/s', $themeCss);
    }

    /** @return array<string, array{string}> */
    public static function panels(): array
    {
        return ['Applicant' => ['applicant'], 'Student' => ['student'], 'Staff' => ['admin']];
    }
}
