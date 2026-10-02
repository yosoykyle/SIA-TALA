<?php

namespace TalaPreview;

use Filament\Panel;
use Filament\PanelProvider;
use Filament\FontProviders\LocalFontProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;

class PreviewPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        // Laravel merges default connection entries into an empty config array.
        config()->set('database.connections', []);
        // The local showcase has no accounts or authentication endpoint.
        \Illuminate\Support\Facades\Auth::viaRequest('preview-guest', fn () => null);
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('preview')
            ->path('filament')
            ->brandName('TALA UI')
            ->brandLogo(fn () => view('brand'))
            ->brandLogoHeight('2.25rem')
            ->font('Inter', url: '/assets/fonts.css', provider: LocalFontProvider::class)
            ->colors([
                'primary' => Color::generatePalette('#2f7d3b'),
                'gray' => Color::Zinc,
                'danger' => Color::generatePalette('#b4113f'),
                'info' => Color::generatePalette('#0c53c1'),
                'success' => Color::Green,
                'warning' => Color::Amber,
            ])
            ->sidebarCollapsibleOnDesktop()
            ->topbar(false)
            ->sidebarWidth('16rem')
            ->maxContentWidth(Width::Full)
            ->userMenu(false)
            ->globalSearch(false)
            ->pages([Components::class, Guidance::class])
            ->navigationItems([
                \Filament\Navigation\NavigationItem::make('Bootstrap public page')->url('/public')->icon('heroicon-o-globe-alt')->sort(3),
            ])
            ->middleware([
                \Illuminate\Cookie\Middleware\EncryptCookies::class,
                \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
                \Illuminate\Session\Middleware\StartSession::class,
                \Illuminate\View\Middleware\ShareErrorsFromSession::class,
                \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
                \Filament\Http\Middleware\DisableBladeIconComponents::class,
                \Filament\Http\Middleware\DispatchServingFilamentEvent::class,
            ])
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => Blade::render('<link rel="stylesheet" href="/assets/tala-theme.css">'))
            ->renderHook(PanelsRenderHook::SIDEBAR_FOOTER, fn () => view('theme-switch'))
            ->renderHook(PanelsRenderHook::FOOTER, fn () => view('attribution'));
    }
}
