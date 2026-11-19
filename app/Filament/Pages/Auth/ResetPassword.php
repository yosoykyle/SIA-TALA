<?php

namespace App\Filament\Pages\Auth;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Caresome\FilamentAuthDesigner\Pages\Auth\ResetPassword as BaseResetPassword;
use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Throwable;

class ResetPassword extends BaseResetPassword
{
    public function mount(?string $email = null, #[SensitiveParameter] ?string $token = null): void
    {
        $this->token = $token ?? request()->query('token');

        $this->form->fill([
            'email' => $email ?? request()->query('email'),
        ]);
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (! Filament::auth()->check()) {
            return 'Choose a strong password to finish recovering your account.';
        }

        return 'You are currently signed in. Setting a new password will sign you out here and return you to the sign-in page.';
    }

    public function getFormContentComponent(): Component
    {
        return parent::getFormContentComponent()
            ->extraAttributes(['novalidate' => true]);
    }

    public function resetPassword(): ?PasswordResetResponse
    {
        $this->form->getState();

        $wasAuthenticated = Filament::auth()->check();
        $hadAuthenticationFlag = session()->has(ContextualLogin::AuthenticatingSessionKey);
        $authenticationFlag = session(ContextualLogin::AuthenticatingSessionKey);
        $notifications = session('filament.notifications', []);
        session()->put(ContextualLogin::AuthenticatingSessionKey, true);
        $sessionInvalidated = false;

        try {
            $response = DB::transaction(function (): ?PasswordResetResponse {
                $response = parent::resetPassword();

                if ($response instanceof PasswordResetResponse) {
                    $user = User::query()->where('email', $this->email)->firstOrFail();

                    app(ResetUserPassword::class)->completeRecovery($user);
                }

                return $response;
            });

            if ($wasAuthenticated && $response instanceof PasswordResetResponse) {
                $postResetNotifications = session('filament.notifications', []);

                Filament::auth()->logout();
                session()->invalidate();
                session()->regenerateToken();

                if (is_array($postResetNotifications) && $postResetNotifications !== []) {
                    session()->put('filament.notifications', $postResetNotifications);
                }

                $sessionInvalidated = true;
            }

            return $response;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            session()->put('filament.notifications', $notifications);

            Notification::make()
                ->title('Password recovery could not be completed')
                ->body('Your password has not changed. Your details have been kept. Try again later.')
                ->danger()
                ->persistent()
                ->send();

            return null;
        } finally {
            if (! $sessionInvalidated) {
                if ($hadAuthenticationFlag) {
                    session()->put(ContextualLogin::AuthenticatingSessionKey, $authenticationFlag);
                } else {
                    session()->forget(ContextualLogin::AuthenticatingSessionKey);
                }
            }
        }
    }
}
