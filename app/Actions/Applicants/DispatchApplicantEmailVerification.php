<?php

namespace App\Actions\Applicants;

use App\Models\User;
use Filament\Auth\Notifications\VerifyEmail;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Throwable;

class DispatchApplicantEmailVerification
{
    public function execute(User $user): bool
    {
        if ($user->hasVerifiedEmail()) {
            return true;
        }

        $user->forceFill([
            'email_verification_nonce' => Str::random(64),
        ])->save();

        try {
            $notification = app(VerifyEmail::class);
            $notification->url = Filament::getVerifyEmailUrl($user);
            $user->notify($notification);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        return true;
    }
}
