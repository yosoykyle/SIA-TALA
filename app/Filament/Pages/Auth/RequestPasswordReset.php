<?php

namespace App\Filament\Pages\Auth;

use App\Actions\Authentication\DispatchPasswordRecovery;
use App\Filament\Pages\Auth\Concerns\HasAuthRateLimitCountdown;
use Caresome\FilamentAuthDesigner\Pages\Auth\RequestPasswordReset as BaseRequestPasswordReset;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

class RequestPasswordReset extends BaseRequestPasswordReset
{
    use HasAuthRateLimitCountdown;

    public function mount(): void
    {
        parent::mount();

        $this->checkInitialRateLimitOnMount(
            rateLimitingKey: $this->getRateLimitKey('request'),
            maxAttempts: 2,
            context: 'password_reset',
            totalSeconds: 60,
        );
    }

    public function request(): void
    {
        if (is_string($this->data['email'] ?? null)) {
            $this->data['email'] = Str::lower(trim($this->data['email']));
        }

        if (! RateLimiter::tooManyAttempts($this->getRateLimitKey('request'), 2)) {
            $data = $this->form->getState();
        } else {
            $data = is_array($this->data) ? $this->data : [];
        }

        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->data['email'] = null;
            $this->form->fill(['email' => null]);
            $this->resetErrorBag();
            $this->resetValidation();
            $this->startAuthRateLimitCountdown($exception->secondsUntilAvailable, 'password_reset', 60);
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $this->clearAuthRateLimit();

        $dispatcher = app(DispatchPasswordRecovery::class);

        try {
            $status = $dispatcher->sendViaBroker(
                $this->getCredentialsFromFormData($data),
                Filament::getCurrentOrDefaultPanel(),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            $dispatcher->unavailableNotification()->send();

            return;
        }

        if ($status !== Password::RESET_LINK_SENT) {
            $this->getFailureNotification($status)?->send();

            return;
        }

        $this->getSentNotification($status)?->send();

        $this->form->fill();
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Forgot password?';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Enter your email address and we will send a password reset link if your account is eligible.';
    }

    protected function getEmailFormComponent(): TextInput
    {
        $component = parent::getEmailFormComponent();
        if (! $component instanceof TextInput) {
            throw new LogicException('The Filament password reset email component must be a text input.');
        }

        return $component
            ->label('Email address')
            ->autocomplete('email')
            ->maxLength(255);
    }

    protected function getRequestFormAction(): Action
    {
        return parent::getRequestFormAction()
            ->label('Send reset link');
    }

    public function loginAction(): Action
    {
        return parent::loginAction()
            ->label('Back to sign in');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                RenderHook::make(PanelsRenderHook::AUTH_PASSWORD_RESET_REQUEST_FORM_BEFORE),
                $this->getRateLimitCountdownContentComponent(),
                $this->getFormContentComponent(),
                RenderHook::make(PanelsRenderHook::AUTH_PASSWORD_RESET_REQUEST_FORM_AFTER),
            ]);
    }

    public function getFormContentComponent(): Component
    {
        return parent::getFormContentComponent()
            ->extraAttributes(['novalidate' => true])
            ->visible(fn (): bool => ! $this->isAuthRateLimited());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        return [
            'email' => Str::lower(trim((string) ($data['email'] ?? ''))),
        ];
    }

    protected function getRateLimitedNotification(TooManyRequestsException $exception): ?Notification
    {
        return app(DispatchPasswordRecovery::class)->rateLimitedNotification($exception->secondsUntilAvailable);
    }

    protected function getFailureNotification(string $status): ?Notification
    {
        $this->form->fill();

        return $this->getSentNotification($status);
    }

    protected function getSentNotification(string $status): ?Notification
    {
        return app(DispatchPasswordRecovery::class)->guestSentNotification();
    }
}
