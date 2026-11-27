<?php

namespace App\Providers\Filament;

use App\Actions\Authentication\TalaAppAuthentication;
use App\Filament\Components\LiveDatabaseNotifications;
use App\Filament\Pages\Auth\AccountSecurity;
use App\Filament\Pages\Auth\ContextualLogin;
use App\Filament\Pages\Auth\EmailVerificationPrompt;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Pages\Auth\ResetPassword;
use App\Filament\Student\Pages\Academics;
use App\Filament\Student\Pages\CorView;
use App\Filament\Student\Pages\Dashboard;
use App\Filament\Student\Pages\Enrollment;
use App\Filament\Student\Pages\Finance;
use App\Filament\Student\Pages\Profile;
use App\Filament\Student\Pages\ScheduleView;
use App\Http\Middleware\EnforceCanonicalSessionPolicy;
use App\Http\Middleware\EnsureStaffMfaIsEnabled;
use App\Models\User;
use App\Support\NavigationBadgeCounts;
use App\Support\TalaPanelTheme;
use Caresome\FilamentAuthDesigner\AuthDesignerPlugin;
use Caresome\FilamentAuthDesigner\Data\AuthPageConfig;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Vite;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

use function Filament\Support\original_request;

class StudentPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return TalaPanelTheme::configure($panel)
            ->id('student')
            ->path('student')
            ->login(ContextualLogin::class)
            ->passwordReset()
            ->emailVerification(EmailVerificationPrompt::class)
            ->emailChangeVerification()
            ->profile(AccountSecurity::class, isSimple: false)
            ->userMenuItems([
                'profile' => fn (Action $action): Action => $action
                    ->label('Profile')
                    ->icon('heroicon-o-user')
                    ->url(fn (): string => AccountSecurity::getUrl(panel: 'student'))
                    ->visible(fn (): bool => ! TalaPanelTheme::isMandatoryMfaSetupRoute()),
                'quick_tour' => Action::make('quick_tour')
                    ->label('Quick tour')
                    ->icon('heroicon-o-map')
                    ->sort(-1)
                    ->url(null)
                    ->postToUrl(false)
                    ->visible(fn (): bool => ! TalaPanelTheme::isMandatoryMfaSetupRoute())
                    ->alpineClickHandler(<<<'JS'
                        close();
                        const mobile = window.innerWidth < 1024;
                        if (mobile && $store?.sidebar?.isOpen) {
                            $store.sidebar.close();
                        }
                        $nextTick(() => {
                            const trigger = mobile
                                ? document.querySelector('.fi-layout-sidebar-toggle-btn, .fi-topbar-open-sidebar-btn')
                                : ($el.closest('.fi-user-menu')?.querySelector('.fi-user-menu-trigger') ?? document.querySelector('.fi-user-menu-trigger'));
                            window.dispatchEvent(new CustomEvent('tala:start-student-tour', { detail: { returnFocusEl: trigger } }));
                        });
                        JS),
            ])
            ->multiFactorAuthentication(
                TalaAppAuthentication::make()->recoverable(),
                isRequired: true,
            )
            ->multiFactorAuthenticationRequiredMiddlewareName(EnsureStaffMfaIsEnabled::class)
            ->databaseNotifications(
                condition: fn (): bool => ! TalaPanelTheme::isMandatoryMfaSetupRoute(),
                livewireComponent: LiveDatabaseNotifications::class,
            )
            ->databaseNotificationsPolling('8s')
            ->brandName('Servitech Institute Asia — Student Hub')
            ->plugin(
                AuthDesignerPlugin::make()
                    ->defaults(TalaPanelTheme::authLayout(...))
                    ->login(fn (AuthPageConfig $config) => $config
                        ->usingPage(ContextualLogin::class)
                    )
                    ->passwordReset(fn (AuthPageConfig $config) => $config
                        ->usingPage(RequestPasswordReset::class)
                        ->usingResetPage(ResetPassword::class)
                    )
                    ->emailVerification(fn (AuthPageConfig $config) => $config
                        ->usingPage(EmailVerificationPrompt::class)
                    )
                    ->themeToggle()
            )
            ->renderHook(
                PanelsRenderHook::SIMPLE_PAGE_START,
                fn (array $scopes): View => view('filament.components.auth-back-link', [
                    'showBackToSignIn' => in_array(RequestPasswordReset::class, $scopes, true)
                        || in_array(ResetPassword::class, $scopes, true)
                        || request()->routeIs('filament.*.auth.password-reset.*', 'filament.applicant.auth.register'),
                ]),
            )
            ->renderHook(
                PanelsRenderHook::CONTENT_START,
                fn (): HtmlString => auth()->check() && ! TalaPanelTheme::isMandatoryMfaSetupRoute()
                    ? new HtmlString(view('filament.components.student-quick-tour')->render())
                    : new HtmlString(''),
            )
            ->renderHook(
                PanelsRenderHook::SCRIPTS_AFTER,
                fn (): HtmlString => auth()->check() && ! TalaPanelTheme::isMandatoryMfaSetupRoute()
                    ? app(Vite::class)(['resources/js/student-tour.js'])
                    : new HtmlString(''),
            )
            ->discoverResources(in: app_path('Filament/Student/Resources'), for: 'App\Filament\Student\Resources')
            ->discoverPages(in: app_path('Filament/Student/Pages'), for: 'App\Filament\Student\Pages')
            ->pages([
                Dashboard::class,
                Profile::class,
            ])
            ->navigation(function (NavigationBuilder $builder): NavigationBuilder {
                $badgeCounts = new NavigationBadgeCounts;
                $user = auth()->user();
                $user = $user instanceof User ? $user : null;

                return $builder->items([
                    $this->navigationItem(Dashboard::class, 'Overview'),
                    $this->navigationItem(ScheduleView::class, 'Class schedule'),
                    $this->enrollmentNavigationItem($badgeCounts, $user),
                    $this->academicsNavigationItem(),
                    $this->financeNavigationItem($badgeCounts, $user),
                ]);
            })
            ->discoverWidgets(in: app_path('Filament/Student/Widgets'), for: 'App\Filament\Student\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnforceCanonicalSessionPolicy::class,
            ]);
    }

    /**
     * @param  class-string  $component
     */
    private function navigationItem(string $component, string $label): NavigationItem
    {
        return NavigationItem::make($label)
            ->key($component)
            ->icon($component::getNavigationIcon())
            ->url(fn (): string => $component::getUrl(panel: 'student'))
            ->isActiveWhen(fn (): bool => original_request()->routeIs($component::getRouteName(Filament::getPanel('student'))));
    }

    private function enrollmentNavigationItem(NavigationBadgeCounts $badgeCounts, ?User $user): NavigationItem
    {
        $panel = Filament::getPanel('student');
        $enrollmentRoute = Enrollment::getRouteName($panel);
        $corRoute = CorView::getRouteName($panel);
        $inEnrollment = original_request()->routeIs($enrollmentRoute, $corRoute);
        $enrollmentBadge = fn (): ?string => ($count = $badgeCounts->studentEnrollmentActionCount($user)) > 0 ? (string) $count : null;
        $item = NavigationItem::make('Enrollment')
            ->key(Enrollment::class)
            ->icon(Enrollment::getNavigationIcon())
            ->url(fn (): string => Enrollment::getUrl(panel: 'student'))
            ->badge($enrollmentBadge, 'warning')
            ->isActiveWhen(fn (): bool => $inEnrollment);

        if (! $inEnrollment) {
            return $item;
        }

        return $item->childItems([
            NavigationItem::make('Current registration')
                ->icon('heroicon-o-clipboard-document-check')
                ->url(fn (): string => Enrollment::getUrl(panel: 'student'))
                ->badge($enrollmentBadge, 'warning')
                ->isActiveWhen(fn (): bool => original_request()->routeIs($enrollmentRoute)),
            NavigationItem::make('Certificate (COR)')
                ->icon('heroicon-o-document-text')
                ->url(fn (): string => CorView::getUrl(panel: 'student'))
                ->isActiveWhen(fn (): bool => original_request()->routeIs($corRoute)),
        ]);
    }

    private function financeNavigationItem(NavigationBadgeCounts $badgeCounts, ?User $user): NavigationItem
    {
        $financeBadge = fn (): ?string => ($count = $badgeCounts->studentFinanceActionCount($user)) > 0 ? (string) $count : null;
        $item = $this->navigationItem(Finance::class, 'Fees and payments')
            ->badge($financeBadge, 'warning');
        $inFinance = original_request()->routeIs(Finance::getRouteName(Filament::getPanel('student')));

        if (! $inFinance) {
            return $item;
        }

        $showingHistory = original_request()->query('view') === 'history';

        return $item->childItems([
            NavigationItem::make('This term')
                ->icon('heroicon-o-banknotes')
                ->url(fn (): string => Finance::getUrl(panel: 'student'))
                ->badge($financeBadge, 'warning')
                ->isActiveWhen(fn (): bool => ! $showingHistory),
            NavigationItem::make('Past statements')
                ->icon('heroicon-o-archive-box')
                ->url(fn (): string => Finance::getUrl(['view' => 'history'], panel: 'student'))
                ->isActiveWhen(fn (): bool => $showingHistory),
        ]);
    }

    private function academicsNavigationItem(): NavigationItem
    {
        $panel = Filament::getPanel('student');
        $academicsRoute = Academics::getRouteName($panel);
        $inAcademics = original_request()->routeIs($academicsRoute);
        $item = $this->navigationItem(Academics::class, 'Grades');

        if (! $inAcademics) {
            return $item;
        }

        $activeView = (string) original_request()->query('view', 'record');
        if (in_array($activeView, ['current', 'graduation'], true)) {
            $activeView = 'record';
        }

        return $item->childItems([
            NavigationItem::make('Study plan')
                ->icon('heroicon-o-map')
                ->url(fn (): string => Academics::getUrl(['view' => 'plan'], panel: 'student'))
                ->isActiveWhen(fn (): bool => $inAcademics && $activeView === 'plan'),
        ]);
    }
}
