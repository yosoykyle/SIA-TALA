<?php

namespace App\Support;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Filament\Applicant\Pages\Application as ApplicantApplication;
use App\Filament\Applicant\Pages\Auth\ApplicantEmailVerification;
use App\Filament\Components\AccessibleSidebar;
use App\Filament\Pages\AssistedAdmissionApplication;
use App\Models\User;
use Caresome\FilamentAuthDesigner\Data\AuthPageConfig;
use Caresome\FilamentAuthDesigner\Enums\MediaPosition;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Auth\MultiFactor\Pages\SetUpRequiredMultiFactorAuthentication;
use Filament\Enums\GlobalSearchPosition;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;

class TalaPanelTheme
{
    public static function authLayout(AuthPageConfig $config): AuthPageConfig
    {
        return $config
            ->media(is_file(public_path('images/auth/admin.webp')) ? asset('images/auth/admin.webp') : null, alt: '')
            ->mediaPosition(MediaPosition::Top)
            ->mediaSize('clamp(10rem, 31svh, 21rem)');
    }

    /** Connect the native dialog label when the vendor heading omits its referenced id. */
    public static function configureActionModal(Action $action): void
    {
        if (! $action->hasIcon()) {
            $icon = match (true) {
                $action instanceof CreateAction => Heroicon::Plus,
                $action instanceof EditAction => Heroicon::PencilSquare,
                $action instanceof ViewAction => Heroicon::Eye,
                $action instanceof DeleteAction => Heroicon::Trash,
                str_starts_with((string) $action->getName(), 'record') => Heroicon::Plus,
                in_array($action->getName(), ['create', 'createAnother'], true) => Heroicon::Plus,
                in_array($action->getName(), ['save', 'apply'], true) => Heroicon::Check,
                $action->getName() === 'cancel' => Heroicon::XMark,
                default => null,
            };

            if ($icon !== null) {
                $action->icon($icon);
            }
        }

        $action->labeledFrom(fn (): ?string => $action->getView() === Action::BUTTON_VIEW
            && filled($action->getIcon())
            && in_array($action->getName(), ['openFilters', 'openColumnManager', 'previous', 'next', 'back'], true)
                ? 'sm' : null);

        $action->extraAttributes(fn (): array => filled($action->getIcon())
            && ($action->getTable() === null
                || $action->getRecord() !== null
                || in_array($action->getName(), ['openFilters', 'openColumnManager'], true))
            ? ['aria-label' => trim(strip_tags((string) $action->getLabel()))] : [], merge: true);

        $action->modalFooterActionsAlignment(Alignment::End);
        $action->stickyModalHeader();
        $action->stickyModalFooter();

        $action->closeModalByClickingAway(static function (Action $action): bool {
            if ($action->isSchemaDisabled() || $action->getModalSubmitAction() === false) {
                return true;
            }

            $hasDefinedSchema = Closure::bind(
                static fn (Action $target): bool => $target->schema !== null && $target->schema !== [],
                null,
                Action::class,
            )($action);

            return ! $hasDefinedSchema;
        });

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
            ->spa()
            ->spaUrlExceptions(fn (): array => [
                url('/'),
                url('/?*'),
                url('/').'/?*',
                url('/#*'),
                url('/').'/#*',
                url('/sign-in*'),
                url('/apply*'),
                url('/workspace-chooser*'),
                url('/staff-activation*'),
                url('/outputs/*'),
                url('/finance/*'),
                url('/admissions/evidence/*'),
                ...collect(['admin', 'student', 'applicant'])
                    ->reject(fn (string $panelId): bool => $panelId === $panel->getId())
                    ->map(fn (string $panelId): string => url('/'.$panelId.'*'))
                    ->all(),
                url('/'.$panel->getPath().'/login*'),
                url('/'.$panel->getPath().'/logout*'),
                url('/'.$panel->getPath().'/password-reset*'),
                url('/'.$panel->getPath().'/email-verification*'),
                url('/'.$panel->getPath().'/account-profile*'),
                ...($panel->getId() === 'admin' ? [url('/'.$panel->getPath().'/enrollments*')] : []),
            ])
            ->viteTheme('resources/css/filament/tala/theme.css')
            ->brandLogo(fn (): View => self::brand($panel))
            ->brandLogoHeight('auto')
            ->favicon(asset('images/brand/servitech-crest-favicon.png'))
            ->colors(['primary' => Color::generatePalette('#2F7D3B'), 'gray' => Color::Zinc, 'info' => Color::Zinc])
            ->maxContentWidth(Width::Full)
            ->unsavedChangesAlerts()
            ->renderHook(PanelsRenderHook::SCRIPTS_AFTER, fn (): HtmlString => new HtmlString(
                '<script src="'.e(asset('js/tala-unsaved-changes.js')).'" data-navigate-once></script>',
            ))
            ->userMenuItems([
                'logout' => fn (Action $action): Action => $action
                    ->url(null)
                    ->postToUrl(false)
                    ->alpineClickHandler(<<<'JS'
                        close();
                        const mobile = window.innerWidth < 1024;
                        if (mobile) {
                            $store.sidebar.close();
                        }
                        $nextTick(() => {
                            const trigger = mobile
                                ? document.querySelector('.fi-layout-sidebar-toggle-btn, .fi-topbar-open-sidebar-btn')
                                : ($el.closest('.fi-user-menu')?.querySelector('.fi-user-menu-trigger') ?? document.querySelector('.fi-user-menu-trigger'));
                            trigger?.focus({ preventScroll: true });
                            $dispatch('open-modal', { id: 'tala-sign-out' });
                        });
                        JS),
                'profile' => fn (Action $action): Action => $action
                    ->label('Profile')
                    ->icon('heroicon-o-user')
                    ->visible(fn (): bool => ! self::isMandatoryMfaSetupRoute()),
            ])
            ->topbar(false)
            ->globalSearch(WorkspaceNavigationSearchProvider::class, position: GlobalSearchPosition::Sidebar)
            ->globalSearchKeyBindings(['mod+k'])
            ->globalSearchDebounce('200ms')
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('17rem')
            ->sidebarLivewireComponent(AccessibleSidebar::class)
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): HtmlString => new HtmlString('<script src="'.e(asset('js/tala-wizard.js')).'"></script>'), scopes: [ApplicantApplication::class, AssistedAdmissionApplication::class])
            ->renderHook(PanelsRenderHook::BODY_START, fn (): View => view('filament.components.skip-link'))
            ->renderHook(PanelsRenderHook::LAYOUT_END, fn (): View => view('filament.components.idle-countdown', ['panelId' => $panel->getId()]))
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): View => view('filament.components.content-anchor'))
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): View => view('filament.components.form-accessibility'))
            ->renderHook(PanelsRenderHook::CONTENT_BEFORE, fn (): HtmlString => new HtmlString(match (true) {
                self::isApplicantPanel($panel) => view('filament.components.applicant-topbar', [
                    'brand' => new HtmlString(self::brand($panel)->render()),
                    'person' => self::applicantName($panel),
                ])->render(),
                self::isRegistrarWorkspace($panel) => view('filament.components.registrar-topbar', [
                    'brand' => new HtmlString(self::brand($panel)->render()),
                    'person' => self::staffName($panel),
                ])->render(),
                self::otherStaffWorkspace($panel) !== null => view('filament.components.staff-workspace-topbar', [
                    'workspace' => self::otherStaffWorkspace($panel),
                    'brand' => new HtmlString(self::brand($panel)->render()),
                    'person' => self::staffName($panel),
                ])->render(),
                self::isStudentPanel($panel) => view('filament.components.staff-workspace-topbar', [
                    'workspace' => 'student',
                    'brand' => new HtmlString(self::brand($panel)->render()),
                    'person' => self::staffName($panel),
                    'studentNumber' => self::studentNumber($panel),
                ])->render(),
                default => '',
            }))
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): HtmlString => new HtmlString(
                self::hasWorkspaceTopBar($panel)
                    ? ''
                    : '<div class="tala-mobile-workspace-identity">'.self::brand($panel)->render().'</div>',
            ))
            ->renderHook(PanelsRenderHook::SIMPLE_LAYOUT_START, fn (array $scopes): View => view('filament.components.auth-main-start', [
                'usesAuthDesigner' => self::usesAuthDesigner($scopes),
                'isMandatoryMfaSetup' => self::isMandatoryMfaSetup($scopes),
            ]))
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_START, fn (): HtmlString => new HtmlString(
                self::isApplicantPanel($panel) ? '' : '<p class="tala-workspace-context">'.e(self::workspaceContext($panel)).'</p>',
            ))
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_START, fn (): View => view('filament.components.workspace-search'))
            ->renderHook(PanelsRenderHook::SIMPLE_LAYOUT_END, fn (array $scopes): View => view('filament.components.auth-main-end', [
                'usesAuthDesigner' => self::usesAuthDesigner($scopes),
            ]))
            ->renderHook(PanelsRenderHook::SIMPLE_PAGE_END, fn (array $scopes): View => view('filament.components.auth-recovery-links', [
                'usesAuthDesigner' => self::usesAuthDesigner($scopes),
                'isMandatoryMfaSetup' => self::isMandatoryMfaSetup($scopes),
                'showRecoveryLinks' => ! in_array(ApplicantEmailVerification::class, $scopes, true),
            ]))
            ->renderHook(PanelsRenderHook::SIDEBAR_LOGO_AFTER, fn (): HtmlString => new HtmlString(
                view('filament.components.drawer-close', ['inline' => true])->render(),
            ));
    }

    /** School-first identity; the Applicant top bar carries the signed-in Applicant's name instead (#59 F65, #61). */
    private static function brand(Panel $panel): View
    {
        return view('components.tala-panel-brand', [
            'workspace' => $panel->getBrandName(),
        ]);
    }

    private static function isApplicantPanel(Panel $panel): bool
    {
        return $panel->getId() === 'applicant';
    }

    private static function isStudentPanel(Panel $panel): bool
    {
        return $panel->getId() === 'student';
    }

    /** The user's current selected staff workspace within the shared admin panel, not merely a held role. */
    private static function selectedStaffContext(Panel $panel): ?string
    {
        $user = $panel->auth()->user();
        if ($panel->getId() !== 'admin' || ! $user instanceof User) {
            return null;
        }

        $resolver = app(WorkspaceContextResolver::class);
        $contexts = $resolver->availableContexts($user);

        return $resolver->selected($user) ?? (count($contexts) === 1 ? array_key_first($contexts) : null);
    }

    /** The Registrar top bar (#80) only shows while Registrar is the user's current selected workspace. */
    private static function isRegistrarWorkspace(Panel $panel): bool
    {
        return self::selectedStaffContext($panel) === User::StaffRoleRegistrar;
    }

    /** The remaining staff workspaces (#80) share one generic top bar, keyed by a short workspace slug. */
    private static function otherStaffWorkspace(Panel $panel): ?string
    {
        return match (self::selectedStaffContext($panel)) {
            User::StaffRoleAccounting => 'accounting',
            User::StaffRoleFaculty => 'faculty',
            User::StaffRoleAcademicHead => 'academic-head',
            User::StaffRoleSystemSuperAdmin => 'system-admin',
            default => null,
        };
    }

    /** Whether this panel/workspace renders a sticky top bar instead of the plain mobile-only brand identity. */
    private static function hasWorkspaceTopBar(Panel $panel): bool
    {
        return self::isApplicantPanel($panel) || self::isRegistrarWorkspace($panel)
            || self::otherStaffWorkspace($panel) !== null || self::isStudentPanel($panel);
    }

    private static function staffName(Panel $panel): ?string
    {
        $user = $panel->auth()->user();

        return $user instanceof User && filled($user->name) ? (string) $user->name : null;
    }

    private static function studentNumber(Panel $panel): ?string
    {
        $user = $panel->auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        $number = $user->studentProfile?->student_number;

        return filled($number) ? (string) $number : null;
    }

    private static function applicantName(Panel $panel): ?string
    {
        $user = $panel->auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        if (filled($user->name)) {
            return (string) $user->name;
        }

        $application = $user->currentAdmissionApplication;
        $name = trim(implode(' ', array_filter([$application?->first_name, $application?->last_name], filled(...))));

        return $name !== '' ? $name : null;
    }

    private static function workspaceContext(Panel $panel): string
    {
        if ($panel->getId() !== 'admin' || ! $panel->auth()->user() instanceof User) {
            return (string) str($panel->getBrandName())->after(' — ');
        }

        $label = User::staffRoleOptions()[self::selectedStaffContext($panel) ?? ''] ?? null;

        return $label !== null ? $label.' workspace' : 'Choose a workspace';
    }

    public static function isMandatoryMfaSetupRoute(): bool
    {
        return \Filament\Support\original_request()->routeIs('filament.*.auth.multi-factor-authentication.set-up-required');
    }

    /** @param array<class-string> $scopes */
    private static function isMandatoryMfaSetup(array $scopes): bool
    {
        return self::isMandatoryMfaSetupRoute()
            || collect($scopes)->contains(
                fn (string $scope): bool => $scope === SetUpRequiredMultiFactorAuthentication::class
                    || is_subclass_of($scope, SetUpRequiredMultiFactorAuthentication::class),
            );
    }

    /** @param array<class-string> $scopes */
    private static function usesAuthDesigner(array $scopes): bool
    {
        return collect($scopes)->contains(fn (string $scope): bool => method_exists($scope, 'getAuthDesignerConfig'));
    }
}
