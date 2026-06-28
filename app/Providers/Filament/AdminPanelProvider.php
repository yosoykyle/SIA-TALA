<?php

namespace App\Providers\Filament;

use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\TermOfferings\TermOfferingResource;
use App\Filament\Resources\Users\UserResource;
use Caresome\FilamentAuthDesigner\AuthDesignerPlugin;
use Caresome\FilamentAuthDesigner\Data\AuthPageConfig;
use Caresome\FilamentAuthDesigner\Enums\MediaPosition;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->passwordReset()
            ->emailVerification()
            ->profile()
            ->brandName('T.A.L.A. System')
            ->brandLogo(asset('talalogo.jpg'))
            ->colors([
                'primary' => Color::Blue,
            ])
            ->plugin(
                AuthDesignerPlugin::make()
                    ->defaults(fn (AuthPageConfig $config) => $config
                        ->media(asset('storage/images/admin-bg.png'))
                        ->mediaPosition(MediaPosition::Left)
                        ->mediaSize('50%')
                    )
                    ->login()
                    ->passwordReset()
                    ->emailVerification()
                    ->themeToggle()
            )
            ->resources([
                UserResource::class,
                RoleResource::class,
                ActivityResource::class,
                TermOfferingResource::class,
            ])
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
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
}
