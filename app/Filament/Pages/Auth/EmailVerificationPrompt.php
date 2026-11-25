<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Pages\Auth\Concerns\HasAuthRateLimitCountdown;
use Caresome\FilamentAuthDesigner\Pages\Auth\EmailVerification as BaseEmailVerification;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;

/**
 * @property-read Action $logoutAction
 */
class EmailVerificationPrompt extends BaseEmailVerification
{
    use HasAuthRateLimitCountdown;

    public function mount(): void
    {
        parent::mount();

        if (! Filament::auth()->check()) {
            return;
        }

        $userRateLimitKey = $this->userResendRateLimitKey();

        if (RateLimiter::tooManyAttempts($userRateLimitKey, maxAttempts: 2)) {
            $this->startAuthRateLimitCountdown(
                secondsUntilAvailable: RateLimiter::availableIn($userRateLimitKey),
                context: 'email_verification',
                totalSeconds: 60,
            );

            return;
        }

        $this->checkInitialRateLimitOnMount(
            rateLimitingKey: $this->getRateLimitKey('resendNotification'),
            maxAttempts: 2,
            context: 'email_verification',
            totalSeconds: 60,
        );
    }

    public function getTitle(): string|Htmlable
    {
        return 'Verify your email address';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Verify your email address';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Confirm your email address to continue into your Servitech workspace.';
    }

    public function resendNotificationAction(): Action
    {
        return Action::make('resendNotification')
            ->button()
            ->color('primary')
            ->icon(Heroicon::OutlinedEnvelope)
            ->label('Resend verification email')
            ->size('md')
            ->extraAttributes(['class' => 'w-full justify-center'])
            ->disabled(fn (): bool => $this->isAuthRateLimited())
            ->action(function (): void {
                $userRateLimitKey = $this->userResendRateLimitKey();

                if (RateLimiter::tooManyAttempts($userRateLimitKey, maxAttempts: 2)) {
                    $exception = new TooManyRequestsException(
                        static::class,
                        'resendNotification',
                        request()->ip(),
                        RateLimiter::availableIn($userRateLimitKey),
                    );
                    $this->triggerVerificationRateLimitCountdown($exception);

                    return;
                }

                try {
                    $this->rateLimit(2, method: 'resendNotification');
                } catch (TooManyRequestsException $exception) {
                    $this->triggerVerificationRateLimitCountdown($exception);

                    return;
                }

                $this->clearAuthRateLimit();
                RateLimiter::hit($userRateLimitKey);

                $this->sendEmailVerificationNotification($this->getVerifiable());

                Notification::make()
                    ->title(__('filament-panels::auth/pages/email-verification/email-verification-prompt.notifications.notification_resent.title'))
                    ->success()
                    ->send();
            });
    }

    public function logoutAction(): Action
    {
        return Action::make('logout')
            ->button()
            ->color('gray')
            ->outlined()
            ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
            ->label('Sign out')
            ->size('md')
            ->url(Filament::getLogoutUrl())
            ->postToUrl()
            ->extraAttributes(['class' => 'w-full justify-center']);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getRateLimitCountdownContentComponent(),
            View::make('filament.components.email-verification-card')
                ->key('email-verification-card')
                ->viewData(fn (): array => [
                    'email' => trim((string) (Filament::auth()->user()?->getEmailForVerification() ?? '')),
                    'resendActionHtml' => new HtmlString($this->resendNotificationAction->toHtml()),
                    'logoutActionHtml' => new HtmlString($this->logoutAction->toHtml()),
                ])
                ->visible(fn (): bool => ! $this->isAuthRateLimited()),
        ]);
    }

    protected function getRateLimitCountdownFooterHtml(): ?Htmlable
    {
        return new HtmlString($this->logoutAction->toHtml());
    }

    protected function getRateLimitCountdownTitle(): ?string
    {
        return 'Too many verification emails sent';
    }

    protected function getRateLimitCountdownSummary(): ?string
    {
        return 'Please wait until the timer finishes before requesting another verification email. You can still open the newest link already sent to your inbox.';
    }

    private function triggerVerificationRateLimitCountdown(TooManyRequestsException $exception): void
    {
        $this->startAuthRateLimitCountdown($exception->secondsUntilAvailable, 'email_verification', 60);
        $this->getRateLimitedNotification($exception)?->send();
    }

    private function userResendRateLimitKey(): string
    {
        return 'filament-resend-email-verification:'.Filament::auth()->id();
    }
}
