<?php

namespace App\Filament\Pages\Auth;

use Caresome\FilamentAuthDesigner\Pages\Auth\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

class RequestPasswordReset extends BaseRequestPasswordReset
{
    public function request(): void
    {
        $hadAuthenticationFlag = session()->has(ContextualLogin::AuthenticatingSessionKey);
        $authenticationFlag = session(ContextualLogin::AuthenticatingSessionKey);
        session()->put(ContextualLogin::AuthenticatingSessionKey, true);

        try {
            parent::request();
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Account recovery is temporarily unavailable')
                ->body('Your details have been kept. Try again later or contact official support if recovery remains unavailable.')
                ->danger()
                ->persistent()
                ->send();
        } finally {
            if ($hadAuthenticationFlag) {
                session()->put(ContextualLogin::AuthenticatingSessionKey, $authenticationFlag);
            } else {
                session()->forget(ContextualLogin::AuthenticatingSessionKey);
            }
        }
    }

    protected function getFailureNotification(string $status): ?Notification
    {
        $this->form->fill();

        return $this->getSentNotification($status);
    }

    protected function getSentNotification(string $status): ?Notification
    {
        return Notification::make()
            ->title('Check your email for account recovery')
            ->body('If an account can use this workspace, a recovery link will be requested. If no link arrives, try again later or contact official support.')
            ->success();
    }
}
