<?php

namespace App\Providers\Filament;

use App\Filament\Applicant\Pages\Application;
use App\Filament\Applicant\Pages\Auth\ApplicantEmailVerification;
use App\Filament\Applicant\Pages\Auth\RegisterApplicant;
use App\Filament\Applicant\Pages\Dashboard;
use App\Filament\Applicant\Pages\Requirements;
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
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class ApplicantPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('applicant')
            ->path('applicant')
            ->login()
            ->registration(RegisterApplicant::class)
            ->passwordReset()
            ->emailVerification(ApplicantEmailVerification::class)
            ->profile()
            ->brandName('TALA Applicant Workspace')
            ->brandLogo(asset('talalogo.png'))
            ->colors([
                'primary' => Color::Blue,
            ])
            ->plugin(
                AuthDesignerPlugin::make()
                    ->defaults(fn (AuthPageConfig $config) => $config
                        ->media(asset('storage/images/applicant-bg.png'))
                        ->mediaPosition(MediaPosition::Cover)
                        ->blur(6)
                    )
                    ->login()
                    ->registration(fn (AuthPageConfig $config) => $config
                        ->usingPage(RegisterApplicant::class)
                    )
                    ->passwordReset()
                    ->emailVerification(fn (AuthPageConfig $config) => $config
                        ->usingPage(ApplicantEmailVerification::class)
                    )
                    ->themeToggle()
            )
            ->discoverResources(in: app_path('Filament/Applicant/Resources'), for: 'App\Filament\Applicant\Resources')
            ->discoverPages(in: app_path('Filament/Applicant/Pages'), for: 'App\Filament\Applicant\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->navigation(fn (NavigationBuilder $builder): NavigationBuilder => $builder->items([
                $this->navigationItem(Dashboard::class, 'Home', 'filament.applicant.pages.dashboard'),
                $this->navigationItem(Application::class, 'Application', 'filament.applicant.pages.application'),
                $this->navigationItem(Requirements::class, 'Requirements', 'filament.applicant.pages.requirements'),
            ]))
            ->discoverWidgets(in: app_path('Filament/Applicant/Widgets'), for: 'App\Filament\Applicant\Widgets')
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
            ]);
    }

    /**
     * @param  class-string  $component
     */
    private function navigationItem(string $component, string $label, string $routeName): NavigationItem
    {
        return NavigationItem::make($label)
            ->icon($component::getNavigationIcon())
            ->url(fn (): string => route($routeName))
            ->isActiveWhen(fn (): bool => request()->routeIs($routeName));
    }
}
