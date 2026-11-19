<?php

namespace App\Filament\Pages\Auth;

use App\Actions\Authentication\DispatchPasswordRecovery;
use Caresome\FilamentAuthDesigner\Pages\Auth\RequestPasswordReset as BaseRequestPasswordReset;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

class RequestPasswordReset extends BaseRequestPasswordReset
{
    public function request(): void
    {
        if (is_string($this->data['email'] ?? null)) {
            $this->data['email'] = Str::lower(trim($this->data['email']));
        }

        $data = $this->form->getState();

        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

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

    public function getFormContentComponent(): Component
    {
        return parent::getFormContentComponent()
            ->extraAttributes(['novalidate' => true]);
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
