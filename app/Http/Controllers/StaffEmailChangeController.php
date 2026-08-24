<?php

namespace App\Http\Controllers;

use App\Models\PendingEmailChange;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class StaffEmailChangeController extends Controller
{
    public function __invoke(Request $request, PendingEmailChange $change): Response
    {
        $token = (string) $request->query('token');

        DB::transaction(function () use ($change, $token): void {
            $locked = PendingEmailChange::query()->lockForUpdate()->findOrFail($change->id);

            if ($locked->verified_at !== null
                || $locked->superseded_at !== null
                || $locked->expires_at->isPast()
                || ! hash_equals($locked->token_digest, hash('sha256', $token))) {
                throw ValidationException::withMessages([
                    'email' => 'This email-change link is expired or no longer valid.',
                ]);
            }

            $user = User::query()->lockForUpdate()->findOrFail($locked->user_id);

            if (User::query()->where('email', $locked->new_email)->whereKeyNot($user->id)->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'This email-change link is no longer valid. Ask a System Administrator to start again.',
                ]);
            }

            $oldDomain = str($user->email)->after('@')->toString();
            $user->forceFill([
                'email' => $locked->new_email,
                'email_verified_at' => now(),
            ])->save();
            $locked->update(['verified_at' => now()]);

            activity()
                ->performedOn($user)
                ->event('staff_email_changed')
                ->withProperties([
                    'old_email_domain' => $oldDomain,
                    'new_email_domain' => str($locked->new_email)->after('@')->toString(),
                ])
                ->log('Staff sign-in email changed after successor verification');
        });

        return redirect()->route('filament.admin.auth.login')
            ->with('status', 'The successor sign-in email was verified.');
    }
}
