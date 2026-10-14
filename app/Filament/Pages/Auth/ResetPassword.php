<?php

namespace App\Filament\Pages\Auth;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Caresome\FilamentAuthDesigner\Pages\Auth\ResetPassword as BaseResetPassword;
use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ResetPassword extends BaseResetPassword
{
    public function resetPassword(): ?PasswordResetResponse
    {
        $hadAuthenticationFlag = session()->has(ContextualLogin::AuthenticatingSessionKey);
        $authenticationFlag = session(ContextualLogin::AuthenticatingSessionKey);
        $notifications = session('filament.notifications', []);
        session()->put(ContextualLogin::AuthenticatingSessionKey, true);

        try {
            return DB::transaction(function (): ?PasswordResetResponse {
                $response = parent::resetPassword();

                if ($response instanceof PasswordResetResponse) {
                    $user = User::query()->where('email', $this->email)->firstOrFail();

                    app(ResetUserPassword::class)->completeRecovery($user);
                }

                return $response;
            });
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
            if ($hadAuthenticationFlag) {
                session()->put(ContextualLogin::AuthenticatingSessionKey, $authenticationFlag);
            } else {
                session()->forget(ContextualLogin::AuthenticatingSessionKey);
            }
        }
    }
}
