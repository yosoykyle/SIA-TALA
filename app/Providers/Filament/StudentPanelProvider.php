<?php

namespace App\Providers\Filament;

use App\Actions\Authentication\TalaAppAuthentication;
use App\Filament\Pages\Auth\AccountSecurity;
use App\Filament\Pages\Auth\ContextualLogin;
use App\Filament\Student\Pages\Academics;
use App\Filament\Student\Pages\Dashboard;
use App\Filament\Student\Pages\Enrollment;
use App\Filament\Student\Pages\Finance;
use App\Filament\Student\Pages\Profile;
use App\Http\Middleware\EnforceCanonicalSessionPolicy;
use App\Http\Middleware\EnsureStaffMfaIsEnabled;
use App\Support\TalaPanelTheme;
use Caresome\FilamentAuthDesigner\AuthDesignerPlugin;
use Caresome\FilamentAuthDesigner\Data\AuthPageConfig;
use Caresome\FilamentAuthDesigner\Enums\MediaPosition;
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
            ->multiFactorAuthentication(
                TalaAppAuthentication::make()->recoverable(),
                isRequired: true,
            )
            ->multiFactorAuthenticationRequiredMiddlewareName(EnsureStaffMfaIsEnabled::class)
            ->brandName('TALA Student Hub')
            ->plugin(
                AuthDesignerPlugin::make()
                    ->defaults(fn (AuthPageConfig $config) => $config
                        ->media(is_file(public_path('images/auth/student.webp')) ? asset('images/auth/student.webp') : null, alt: '')
                        ->mediaPosition(MediaPosition::Cover)
                        ->blur(6)
                    )
                    ->login(fn (AuthPageConfig $config) => $config
                        ->usingPage(ContextualLogin::class)
                    )
                    ->passwordReset()
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
                $this->navigationItem(Dashboard::class, 'Home'),
                $this->navigationItem(Enrollment::class, 'Enrollment'),
                $this->navigationItem(Academics::class, 'Academics'),
                $this->navigationItem(Finance::class, 'Finance'),
                NavigationItem::make('Profile')
                    ->icon('heroicon-o-user')
                    ->url(fn (): string => Profile::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs(Profile::getRouteName())),
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
        /** @var NavigationItem $item */
        $item = $component::getNavigationItems()[0];

        return $item->label($label);
    }
}
