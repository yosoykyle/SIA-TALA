<?php

namespace App\Filament\Pages\Auth;

use App\Actions\Authentication\DispatchPasswordRecovery;
use Caresome\FilamentAuthDesigner\Pages\Auth\RequestPasswordReset as BaseRequestPasswordReset;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

class RequestPasswordReset extends BaseRequestPasswordReset
{
    public function request(): void
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $data = $this->form->getState();
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
