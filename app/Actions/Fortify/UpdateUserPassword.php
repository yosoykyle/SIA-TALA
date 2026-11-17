<?php

namespace App\Actions\Fortify;

use App\Actions\Authentication\UserSessionService;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    public function __construct(private readonly UserSessionService $sessions) {}

    /**
     * Validate and update the user's password.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => $this->passwordRules(),
        ], [
            'current_password.current_password' => __('The provided password does not match your current password.'),
        ])->validateWithBag('updatePassword');

        $user->forceFill([
            'password' => Hash::make($input['password']),
        ])->save();

        $this->completePasswordChange(
            $user,
            request()->hasSession() ? request()->session()->getId() : null,
        );
    }

    /**
     * Rotate remember credentials, revoke other active sessions, and audit a self-service password change.
     */
    public function completePasswordChange(User $user, ?string $exceptSessionId = null): void
    {
        $user->forceFill([
            $user->getRememberTokenName() => Str::random(60),
        ])->save();

        $this->sessions->revokeAll($user, $exceptSessionId);

        activity()
            ->performedOn($user)
            ->causedBy($user)
            ->event('password_changed')
            ->log('Password changed and other sessions ended');
    }
}
