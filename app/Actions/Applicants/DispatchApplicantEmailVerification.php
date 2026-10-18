<?php

namespace App\Actions\Applicants;

use App\Models\User;
use Filament\Auth\Notifications\VerifyEmail;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

class DispatchApplicantEmailVerification
{
    public const SentAtSessionKey = 'tala.verification_sent_at';

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

        session()->put(self::SentAtSessionKey, now()->getTimestamp());

        return true;
    }

    /**
     * The newest link expires after the verification window, measured from when this browser session last sent one.
     */
    public static function expiresAt(): ?Carbon
    {
        $sentAt = session(self::SentAtSessionKey);

        if (! is_int($sentAt)) {
            return null;
        }

        return Carbon::createFromTimestamp($sentAt)->addMinutes((int) config('auth.verification.expire', 60));
    }
}
