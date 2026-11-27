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

    /** @return array<string, array{string}> */
    public static function panels(): array
    {
        return ['Applicant' => ['applicant'], 'Student' => ['student'], 'Staff' => ['admin']];
    }
}
