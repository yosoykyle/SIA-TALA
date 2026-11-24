<?php

namespace App\Http\Controllers;

use App\Actions\Applicants\DispatchApplicantEmailVerification;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ApplicantEmailVerificationController extends Controller
{
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        if (! ctype_digit($id)) {
            abort(403, 'This verification link is expired, malformed, or no longer the newest link.');
        }

        $applicant = User::query()->find((int) $id);

        if (! $applicant instanceof User || ! $applicant->hasRole('applicant')) {
            abort(403, 'This verification link is expired, malformed, or no longer the newest link.');
        }

        $authenticatedUser = $request->user();

        if ($authenticatedUser instanceof User && (int) $authenticatedUser->getKey() !== (int) $applicant->getKey()) {
            abort(403, 'You are signed in to a different account.');
        }

        if (! hash_equals(sha1($applicant->getEmailForVerification()), $hash)) {
            abort(403, 'This verification link is expired, malformed, or no longer the newest link.');
        }

        if (! $applicant->hasVerifiedEmail() && $applicant->markEmailAsVerified()) {
            event(new Verified($applicant));
        }

        $request->session()->forget(DispatchApplicantEmailVerification::SentAtSessionKey);

        if ($authenticatedUser instanceof User && (int) $authenticatedUser->getKey() === (int) $applicant->getKey()) {
            Notification::make()
                ->title('Email verified')
                ->body('Your email address has been verified.')
                ->success()
                ->send();

            return redirect()->intended((string) Filament::getPanel('applicant')->getUrl());
        }

        return redirect()->route('filament.applicant.auth.login')
            ->with('status', 'Your email address has been verified. Sign in to continue your application.');
    }
}
