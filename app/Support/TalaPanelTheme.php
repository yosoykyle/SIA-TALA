<?php

namespace App\Support;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Filament\Components\AccessibleSidebar;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Enums\GlobalSearchPosition;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;

class TalaPanelTheme
{
    /** Connect the native dialog label when the vendor heading omits its referenced id. */
    public static function configureActionModal(Action $action): void
    {
        $action->labeledFrom(fn (): ?string => $action->getView() === Action::BUTTON_VIEW
            && filled($action->getIcon())
            && in_array($action->getName(), ['openFilters', 'openColumnManager', 'previous', 'next', 'back'], true)
                ? 'sm' : null);

        $action->extraAttributes(fn (): array => filled($action->getIcon())
            ? ['aria-label' => trim(strip_tags((string) $action->getLabel()))] : [], merge: true);

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
            ->colors(['primary' => Color::generatePalette('#2F7D3B'), 'gray' => Color::Zinc, 'info' => Color::generatePalette('#0C53C1')])
            ->maxContentWidth(Width::Full)
            ->unsavedChangesAlerts()
            ->userMenuItems([
                'logout' => fn (Action $action): Action => $action
                    ->url(null)
                    ->postToUrl(false)
                    ->alpineClickHandler("close(); \$el.closest('.fi-user-menu').querySelector('.fi-user-menu-trigger').focus(); \$dispatch('open-modal', { id: 'tala-sign-out' })"),
                'profile' => fn (Action $action): Action => $action
                    ->label('Account security')
                    ->icon('heroicon-o-shield-check'),
            ])
            ->topbar(false)
            ->globalSearch(WorkspaceNavigationSearchProvider::class, position: GlobalSearchPosition::Sidebar)
            ->globalSearchKeyBindings(['mod+k'])
            ->globalSearchDebounce('200ms')
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('17rem')
            ->sidebarLivewireComponent(AccessibleSidebar::class)
            ->renderHook(PanelsRenderHook::BODY_START, fn (): View => view('filament.components.skip-link'))
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): View => view('filament.components.content-anchor'))
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): HtmlString => new HtmlString(
                '<div class="tala-mobile-workspace-identity">'.view('components.tala-panel-brand', ['workspace' => $panel->getBrandName()])->render().'</div>',
            ))
            ->renderHook(PanelsRenderHook::SIMPLE_LAYOUT_START, fn (array $scopes): View => view('filament.components.auth-main-start', ['usesAuthDesigner' => self::usesAuthDesigner($scopes)]))
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_START, fn (): HtmlString => new HtmlString(
                '<p class="tala-workspace-context">'.e(self::workspaceContext($panel)).'</p>',
            ))
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_START, fn (): View => view('filament.components.workspace-search'))
            ->renderHook(PanelsRenderHook::SIDEBAR_FOOTER, fn (): HtmlString => new HtmlString(
                '<div class="tala-sidebar-attribution">'.view('components.tala-panel-brand', ['placement' => 'attribution'])->render().'</div>',
            ))
            ->renderHook(PanelsRenderHook::CONTENT_END, fn (): HtmlString => new HtmlString(
                '<footer class="tala-mobile-attribution">'.view('components.tala-panel-brand', ['placement' => 'attribution'])->render().'</footer>',
            ))
            ->renderHook(PanelsRenderHook::SIMPLE_PAGE_END, fn (): HtmlString => new HtmlString(
                '<footer class="tala-auth-attribution">'.view('components.tala-panel-brand', ['placement' => 'attribution'])->render().'</footer>',
            ))
            ->renderHook(PanelsRenderHook::SIMPLE_LAYOUT_END, fn (array $scopes): View => view('filament.components.auth-main-end', ['usesAuthDesigner' => self::usesAuthDesigner($scopes)]))
            ->renderHook(PanelsRenderHook::SIMPLE_PAGE_END, fn (array $scopes): View => view('filament.components.auth-recovery-links', ['usesAuthDesigner' => self::usesAuthDesigner($scopes)]))
            ->renderHook(PanelsRenderHook::SIDEBAR_LOGO_AFTER, fn (): HtmlString => new HtmlString(
                view('filament.components.drawer-close', ['inline' => true])->render(),
            ));
    }

    private static function workspaceContext(Panel $panel): string
    {
        $user = $panel->auth()->user();
        if ($panel->getId() !== 'admin' || ! $user instanceof User) {
            return (string) str($panel->getBrandName())->after(' — ');
        }

        $resolver = app(WorkspaceContextResolver::class);
        $contexts = $resolver->availableContexts($user);
        $selected = $resolver->selected($user);
        if ($selected === null && count($contexts) === 1) {
            $selected = array_key_first($contexts);
        }

        $label = User::staffRoleOptions()[$selected ?? ''] ?? null;

        return $label !== null ? $label.' workspace' : 'Choose a workspace';
    }

    /** @param array<class-string> $scopes */
    private static function usesAuthDesigner(array $scopes): bool
    {
        return collect($scopes)->contains(fn (string $scope): bool => method_exists($scope, 'getAuthDesignerConfig'));
    }
}
