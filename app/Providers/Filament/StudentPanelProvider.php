<?php

namespace App\Providers\Filament;

use App\Actions\Authentication\TalaAppAuthentication;
use App\Filament\Pages\Auth\AccountSecurity;
use App\Filament\Pages\Auth\ContextualLogin;
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
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
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
            ->emailVerification()
            ->emailChangeVerification()
            ->profile(AccountSecurity::class, isSimple: false)
            ->userMenuItems([
                'profile' => fn (Action $action): Action => $action
                    ->label('My profile')
                    ->icon('heroicon-o-user')
                    ->url(fn (): string => Profile::getUrl(panel: 'student')),
                Action::make('accountSecurity')
                    ->label('Password and security')
                    ->icon('heroicon-o-shield-check')
                    ->url(fn (): string => AccountSecurity::getUrl(panel: 'student')),
            ])
            ->multiFactorAuthentication(
                TalaAppAuthentication::make()->recoverable(),
                isRequired: true,
            )
            ->multiFactorAuthenticationRequiredMiddlewareName(EnsureStaffMfaIsEnabled::class)
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
                    ->emailVerification()
                    ->themeToggle()
            )
            ->discoverResources(in: app_path('Filament/Student/Resources'), for: 'App\Filament\Student\Resources')
            ->discoverPages(in: app_path('Filament/Student/Pages'), for: 'App\Filament\Student\Pages')
            ->pages([
                Dashboard::class,
                Profile::class,
            ])
            ->navigation(fn (NavigationBuilder $builder): NavigationBuilder => $builder->items([
                $this->navigationItem(Dashboard::class, 'Overview'),
                $this->enrollmentNavigationItem(),
                $this->academicsNavigationItem(),
                $this->financeNavigationItem(),
            ]))
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

    private function enrollmentNavigationItem(): NavigationItem
    {
        $panel = Filament::getPanel('student');
        $enrollmentRoute = Enrollment::getRouteName($panel);
        $corRoute = CorView::getRouteName($panel);
        $scheduleRoute = ScheduleView::getRouteName($panel);
        $inEnrollment = original_request()->routeIs($enrollmentRoute, $corRoute, $scheduleRoute);
        $item = NavigationItem::make('Enrollment')
            ->key(Enrollment::class)
            ->icon(Enrollment::getNavigationIcon())
            ->url(fn (): string => Enrollment::getUrl(panel: 'student'))
            ->isActiveWhen(fn (): bool => $inEnrollment);

        if (! $inEnrollment) {
            return $item;
        }

        return $item->childItems([
            NavigationItem::make('Current registration')
                ->icon('heroicon-o-clipboard-document-check')
                ->url(fn (): string => Enrollment::getUrl(panel: 'student'))
                ->isActiveWhen(fn (): bool => original_request()->routeIs($enrollmentRoute)),
            NavigationItem::make('Certificate (COR)')
                ->icon('heroicon-o-document-text')
                ->url(fn (): string => CorView::getUrl(panel: 'student'))
                ->isActiveWhen(fn (): bool => original_request()->routeIs($corRoute)),
            NavigationItem::make('Class schedule')
                ->icon('heroicon-o-calendar-days')
                ->url(fn (): string => ScheduleView::getUrl(panel: 'student'))
                ->isActiveWhen(fn (): bool => original_request()->routeIs($scheduleRoute)),
        ]);
    }

    private function financeNavigationItem(): NavigationItem
    {
        $item = $this->navigationItem(Finance::class, 'Fees and payments');
        $inFinance = original_request()->routeIs(Finance::getRouteName(Filament::getPanel('student')));

        if (! $inFinance) {
            return $item;
        }

        $showingHistory = original_request()->query('view') === 'history';

        return $item->childItems([
            NavigationItem::make('This term')
                ->icon('heroicon-o-banknotes')
                ->url(fn (): string => Finance::getUrl(panel: 'student'))
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
        $item = $this->navigationItem(Academics::class, 'Academics');

        if (! $inAcademics) {
            return $item;
        }

        $activeView = (string) original_request()->query('view', 'current');
        if ($activeView === 'graduation') {
            $activeView = 'record';
        }

        return $item->childItems([
            NavigationItem::make('Current subjects')
                ->icon('heroicon-o-book-open')
                ->url(fn (): string => Academics::getUrl(['view' => 'current'], panel: 'student'))
                ->isActiveWhen(fn (): bool => $inAcademics && ! in_array($activeView, ['plan', 'record'], true)),
            NavigationItem::make('Study plan')
                ->icon('heroicon-o-map')
                ->url(fn (): string => Academics::getUrl(['view' => 'plan'], panel: 'student'))
                ->isActiveWhen(fn (): bool => $inAcademics && $activeView === 'plan'),
            NavigationItem::make('Academic record')
                ->icon('heroicon-o-document-text')
                ->url(fn (): string => Academics::getUrl(['view' => 'record'], panel: 'student'))
                ->isActiveWhen(fn (): bool => $inAcademics && $activeView === 'record'),
        ]);
    }
}
