<?php

namespace App\Support;

use App\Filament\Components\AccessibleSidebar;
use Filament\Actions\Action;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;

class TalaPanelTheme
{
    /** Connect the native dialog label when the vendor heading omits its referenced id. */
    public static function configureActionModal(Action $action): void
    {
        $action->extraModalWindowAttributes([
            'x-effect' => <<<'JS'
                if (isOpen) {
                    $nextTick(() => {
                        const dialog = $el.closest('[role=dialog]');
                        const heading = $el.querySelector('.fi-modal-heading');
                        const headingId = dialog?.getAttribute('aria-labelledby');
                        if (heading && headingId && ! document.getElementById(headingId)) {
                            heading.id = headingId;
                        }
                    });
                }
                JS,
        ], merge: true);
    }

    public static function configure(Panel $panel): Panel
    {
        return $panel
            ->viteTheme('resources/css/filament/tala/theme.css')
            ->brandLogo(fn (): View => view('components.tala-panel-brand', ['workspace' => $panel->getBrandName()]))
            ->brandLogoHeight('auto')
            ->favicon(asset('talalogo.png'))
            ->colors(['primary' => array_replace(Color::Blue, [600 => '#1D4ED8', 700 => '#1E3A8A'])])
            ->globalSearch(false)
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('17rem')
            ->sidebarLivewireComponent(AccessibleSidebar::class)
            ->renderHook(PanelsRenderHook::BODY_START, fn (): View => view('filament.components.skip-link'))
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): View => view('filament.components.content-anchor'))
            ->renderHook(PanelsRenderHook::SIMPLE_LAYOUT_START, fn (array $scopes): View => view('filament.components.auth-main-start', ['usesAuthDesigner' => self::usesAuthDesigner($scopes)]))
            ->renderHook(PanelsRenderHook::SIMPLE_LAYOUT_END, fn (array $scopes): View => view('filament.components.auth-main-end', ['usesAuthDesigner' => self::usesAuthDesigner($scopes)]))
            ->renderHook(PanelsRenderHook::SIMPLE_PAGE_END, fn (array $scopes): View => view('filament.components.auth-recovery-links', ['usesAuthDesigner' => self::usesAuthDesigner($scopes)]))
            ->renderHook(PanelsRenderHook::SIDEBAR_START, fn (): View => view('filament.components.drawer-close'));
    }

    /** @param array<class-string> $scopes */
    private static function usesAuthDesigner(array $scopes): bool
    {
        return collect($scopes)->contains(fn (string $scope): bool => method_exists($scope, 'getAuthDesignerConfig'));
    }
}
