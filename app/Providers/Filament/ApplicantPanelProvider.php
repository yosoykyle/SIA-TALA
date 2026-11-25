<?php

namespace App\Providers\Filament;

use App\Actions\Authentication\TalaAppAuthentication;
use App\Filament\Applicant\Pages\Application;
use App\Filament\Applicant\Pages\ApplicationHistory;
use App\Filament\Applicant\Pages\Auth\ApplicantEmailVerification;
use App\Filament\Applicant\Pages\Auth\RegisterApplicant;
use App\Filament\Applicant\Pages\Dashboard;
use App\Filament\Components\LiveDatabaseNotifications;
use App\Filament\Pages\Auth\AccountSecurity;
use App\Filament\Pages\Auth\ContextualLogin;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Pages\Auth\ResetPassword;
use App\Http\Middleware\EnforceCanonicalSessionPolicy;
use App\Http\Middleware\EnsureStaffMfaIsEnabled;
use App\Models\User;
use App\Support\NavigationBadgeCounts;
use App\Support\TalaPanelTheme;
use Caresome\FilamentAuthDesigner\AuthDesignerPlugin;
use Caresome\FilamentAuthDesigner\Data\AuthPageConfig;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class ApplicantPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        VerifyEmail::toMailUsing(function (object $notifiable, string $url): MailMessage {
            $institution = trim((string) config('institution.name', 'Servitech Institute Asia Inc.'));

            if ($institution === '') {
                $institution = 'Servitech Institute Asia Inc.';
            }

            $expiresMinutes = (int) config('auth.verification.expire', 60);

            return (new MailMessage)
                ->subject("{$institution} — Verify your email address")
                ->greeting('Verify your email address')
                ->line('Click the button below to verify your email address and continue your Servitech account setup.')
                ->action('Verify email address', $url)
                ->line("This verification link expires in {$expiresMinutes} minutes, and only the most recent link can be used.")
                ->line('If you did not create an account, no further action is required.')
                ->salutation("Regards,\n{$institution}");
        });
    }

    public function panel(Panel $panel): Panel
    {
        return TalaPanelTheme::configure($panel)
            ->id('applicant')
            ->globalSearch(false)
            ->path('applicant')
            ->login(ContextualLogin::class)
            ->registration(RegisterApplicant::class)
            ->passwordReset()
            ->emailVerification(ApplicantEmailVerification::class)
            ->emailChangeVerification()
            ->profile(AccountSecurity::class, isSimple: false)
            ->multiFactorAuthentication(
                TalaAppAuthentication::make()->recoverable(),
                isRequired: true,
            )
            ->multiFactorAuthenticationRequiredMiddlewareName(EnsureStaffMfaIsEnabled::class)
            ->databaseNotifications(livewireComponent: LiveDatabaseNotifications::class)
            ->databaseNotificationsPolling('8s')
            ->brandName('Servitech Institute Asia')
            ->plugin(
                AuthDesignerPlugin::make()
                    ->defaults(TalaPanelTheme::authLayout(...))
                    ->login(fn (AuthPageConfig $config) => $config
                        ->usingPage(ContextualLogin::class)
                    )
                    ->registration(fn (AuthPageConfig $config) => $config
                        ->usingPage(RegisterApplicant::class)
                    )
                    ->passwordReset(fn (AuthPageConfig $config) => $config
                        ->usingPage(RequestPasswordReset::class)
                        ->usingResetPage(ResetPassword::class)
                    )
                    ->emailVerification(fn (AuthPageConfig $config) => $config
                        ->usingPage(ApplicantEmailVerification::class)
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
            ->discoverResources(in: app_path('Filament/Applicant/Resources'), for: 'App\Filament\Applicant\Resources')
            ->discoverPages(in: app_path('Filament/Applicant/Pages'), for: 'App\Filament\Applicant\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->navigation(function (NavigationBuilder $builder): NavigationBuilder {
                $badgeCounts = new NavigationBadgeCounts;
                $user = auth()->user();
                $user = $user instanceof User ? $user : null;

                return $builder->items([
                    $this->navigationItem(Dashboard::class, 'Home', 'filament.applicant.pages.dashboard'),
                    $this->navigationItem(Application::class, 'Application', 'filament.applicant.pages.application')
                        ->badge(fn (): ?string => ($count = $badgeCounts->applicantActionCount($user)) > 0 ? (string) $count : null, 'warning'),
                ]);
            })
            ->userMenuItems([
                'history' => Action::make('history')
                    ->label("Applicant's History")
                    ->icon(Heroicon::OutlinedClock)
                    ->url(fn (): string => ApplicationHistory::getUrl(panel: 'applicant'))
                    ->visible(fn (): bool => ! TalaPanelTheme::isMandatoryMfaSetupRoute())
                    ->sort(0),
            ])
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
                EnforceCanonicalSessionPolicy::class,
            ]);
    }

    /**
     * @param  class-string  $component
     */
    private function navigationItem(string $component, string $label, string $routeName): NavigationItem
    {
        return NavigationItem::make($label)
            ->icon($component::getNavigationIcon())
            ->url(fn (): string => $component === Application::class
                ? Application::getNavigationUrl()
                : $component::getUrl(panel: 'applicant'))
            ->isActiveWhen(fn (): bool => $component === Application::class
                ? request()->routeIs($routeName, 'filament.applicant.pages.requirements', 'filament.applicant.pages.submission-receipt')
                : request()->routeIs($routeName));
    }
}
