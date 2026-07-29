<?php

namespace App\Providers\Filament;

use App\Filament\Student\Pages\Academics;
use App\Filament\Student\Pages\Dashboard;
use App\Filament\Student\Pages\Enrollment;
use App\Filament\Student\Pages\Finance;
use App\Filament\Student\Pages\Profile;
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

class StudentPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('student')
            ->path('student')
            ->login()
            ->passwordReset()
            ->emailVerification()
            ->profile(Profile::class)
            ->brandName('TALA Student Hub')
            ->brandLogo(asset('talalogo.png'))
            ->colors([
                'primary' => Color::Blue,
            ])
            ->plugin(
                AuthDesignerPlugin::make()
                    ->defaults(fn (AuthPageConfig $config) => $config
                        ->media(asset('storage/images/student-bg.png'))
                        ->mediaPosition(MediaPosition::Cover)
                        ->blur(6)
                    )
                    ->login()
                    ->passwordReset()
                    ->emailVerification()
                    ->themeToggle()
            )
            ->discoverResources(in: app_path('Filament/Student/Resources'), for: 'App\Filament\Student\Resources')
            ->discoverPages(in: app_path('Filament/Student/Pages'), for: 'App\Filament\Student\Pages')
            ->pages([
                Dashboard::class,
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
