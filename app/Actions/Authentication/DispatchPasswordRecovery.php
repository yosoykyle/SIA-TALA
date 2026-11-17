<?php

namespace App\Actions\Authentication;

use App\Filament\Pages\Auth\ContextualLogin;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Notifications\Notification;
use Filament\Panel;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;
use SensitiveParameter;
use Throwable;

final class DispatchPasswordRecovery
{
    /**
     * Dispatch a password recovery email through Laravel's password broker while preserving session login state.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function sendViaBroker(
        #[SensitiveParameter] array $credentials,
        ?Panel $panel = null,
        ?User $expectedUser = null,
    ): string {
        $panel ??= Filament::getCurrentOrDefaultPanel();

        if (! $panel instanceof Panel) {
            throw new LogicException('An active Filament panel is required to dispatch password recovery.');
        }

        $hadAuthenticationFlag = session()->has(ContextualLogin::AuthenticatingSessionKey);
        $authenticationFlag = session(ContextualLogin::AuthenticatingSessionKey);
        session()->put(ContextualLogin::AuthenticatingSessionKey, true);

        try {
            return Password::broker($panel->getAuthPasswordBroker())->sendResetLink(
                $credentials,
                function (CanResetPassword $user, #[SensitiveParameter] string $token) use ($panel, $expectedUser): void {
                    if ($expectedUser instanceof User && (! $user instanceof User || ! $user->is($expectedUser))) {
                        return;
                    }

                    if ($user instanceof FilamentUser && ! $user->canAccessPanel($panel)) {
                        return;
                    }

                    if (! method_exists($user, 'notify')) {
                        $userClass = $user::class;

                        throw new LogicException("Model [{$userClass}] does not have a [notify()] method.");
                    }

                    $notification = app(ResetPasswordNotification::class, ['token' => $token]);
                    $notification->url = $panel->getResetPasswordUrl($token, $user);

                    $user->notify($notification);

                    if (class_exists(PasswordResetLinkSent::class)) {
                        event(new PasswordResetLinkSent($user));
                    }
                },
            );
        } finally {
            if ($hadAuthenticationFlag) {
                session()->put(ContextualLogin::AuthenticatingSessionKey, $authenticationFlag);
            } else {
                session()->forget(ContextualLogin::AuthenticatingSessionKey);
            }
        }
    }

    /**
     * Send a password reset link exclusively to an already-signed-in user's verified email address.
     */
    public function sendForAuthenticatedUser(User $user, Panel $panel, ?string $rateLimitKey = null): bool
    {
        $email = trim((string) $user->email);

        if ($email === '' || ! $user->canAuthenticate() || ! $user->canAccessPanel($panel)) {
            $this->unavailableNotification()->send();

            return false;
        }

        $rateLimitKey ??= 'tala-authenticated-password-recovery:'.sha1($panel->getId().'|'.(string) $user->getKey());

        if (RateLimiter::tooManyAttempts($rateLimitKey, maxAttempts: 2)) {
            $this->rateLimitedNotification(RateLimiter::availableIn($rateLimitKey))->send();

            return false;
        }

        RateLimiter::hit($rateLimitKey, decaySeconds: 60);

        try {
            $status = $this->sendViaBroker(['email' => $email], $panel, $user);
        } catch (Throwable $exception) {
            report($exception);

            $this->unavailableNotification()->send();

            return false;
        }

        if ($status === Password::RESET_THROTTLED) {
            $this->rateLimitedNotification(60)->send();

            return false;
        }

        if ($status !== Password::RESET_LINK_SENT) {
            $this->unavailableNotification()->send();

            return false;
        }

        $this->authenticatedSentNotification($email)->send();

        return true;
    }

    /**
     * Build a confirmation action that emails a password reset link for an authenticated account.
     */
    public static function authenticatedAction(bool $inMfaModal = false): Action
    {
        return Action::make('forgotPassword')
            ->label('Forgot your password?')
            ->link()
            ->authorize(function (): bool {
                $user = Filament::auth()->user();
                $panel = Filament::getCurrentOrDefaultPanel();

                return $user instanceof User
                    && $panel instanceof Panel
                    && $user->canAuthenticate()
                    && $user->canAccessPanel($panel);
            })
            ->requiresConfirmation()
            ->modalHeading('Email a password reset link?')
            ->modalDescription(function () use ($inMfaModal): string {
                $user = Filament::auth()->user();
                $email = $user instanceof User && filled($user->email)
                    ? $user->email
                    : 'your verified email address';

                if ($inMfaModal) {
                    return "We will send a password reset link to {$email}. This does not turn off two-factor authentication or reveal recovery codes. You will stay signed in here until you open the link and set a new password.";
                }

                return "We will send a password reset link to {$email} so you can reset your password without entering your current one. You will stay signed in here until you open the link and set a new password.";
            })
            ->modalSubmitActionLabel('Send reset link')
            ->action(function (Action $action) use ($inMfaModal): void {
                $user = Filament::auth()->user();
                $panel = Filament::getCurrentOrDefaultPanel();

                if (! $user instanceof User || ! $panel instanceof Panel) {
                    app(self::class)->unavailableNotification()->send();

                    return;
                }

                $sent = app(self::class)->sendForAuthenticatedUser($user, $panel);

                if ($sent && $inMfaModal) {
                    $action->cancelParentActions();
                }
            });
    }

    /**
     * Neutral guest notification preventing account enumeration.
     */
    public function guestSentNotification(): Notification
    {
        return Notification::make()
            ->title('Check your email for account recovery')
            ->body('If an account can use this workspace, a recovery link will be requested. If no link arrives, try again later or contact official support.')
            ->success();
    }

    /**
     * Confirmed recovery email notification for an authenticated user.
     */
    public function authenticatedSentNotification(string $email): Notification
    {
        return Notification::make()
            ->title('Check your email for account recovery')
            ->body("We sent a password reset link to {$email}. You will stay signed in here until you open that link and set a new password.")
            ->success();
    }

    /**
     * Failure notification shown when recovery email dispatch fails or throws.
     */
    public function unavailableNotification(): Notification
    {
        return Notification::make()
            ->title('Account recovery is temporarily unavailable')
            ->body('Your details have been kept. Try again later or contact official support if recovery remains unavailable.')
            ->danger()
            ->persistent();
    }

    /**
     * Rate-limit notification shown when too many recovery links are requested.
     */
    public function rateLimitedNotification(int $secondsUntilAvailable): Notification
    {
        $seconds = max(1, $secondsUntilAvailable);

        return Notification::make()
            ->title('Please wait before requesting another link')
            ->body("Too many password recovery requests. Wait {$seconds} seconds before requesting another reset link.")
            ->danger();
    }
}
