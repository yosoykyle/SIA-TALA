<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * PRD `13_system_admin_reports_audit.md` §13.6 MVP audit scope 1 (login and session
 * security events). Writes one `activity_log` row per login, logout, or failed-login
 * attempt, capturing source context (IP address, user agent) per §13.8's audit-log
 * interaction contract.
 */
class LogAuthenticationActivity
{
    public function __construct(private readonly Request $request) {}

    public function handleLogin(Login $event): void
    {
        $activity = activity()
            ->event('login')
            ->withProperties($this->sourceContext());

        if ($event->user instanceof Model) {
            $activity->causedBy($event->user);
        }

        $activity->log('User logged in');

        if ($event->user instanceof User) {
            $event->user->forceFill(['last_successful_sign_in_at' => now()])->save();
        }
    }

    public function handleLogout(Logout $event): void
    {
        $activity = activity()
            ->event('logout')
            ->withProperties($this->sourceContext());

        if ($event->user instanceof Model) {
            $activity->causedBy($event->user);
        }

        $activity->log('User logged out');
    }

    public function handleVerified(Verified $event): void
    {
        $activity = activity()->event('email_verified');

        if ($event->user instanceof Model) {
            $activity
                ->performedOn($event->user)
                ->causedBy($event->user);
        }

        $activity->log('Sign-in email verified');
    }

    /**
     * Failed events may only carry submitted credentials, with no resolved user.
     * The attempted identifier is logged for traceability; the password is never
     * captured.
     */
    public function handleFailed(Failed $event): void
    {
        activity()
            ->event('login_failed')
            ->withProperties([
                ...$this->sourceContext(),
                'attempted_identifier' => $event->credentials['email'] ?? null,
            ])
            ->log('Login attempt failed');
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceContext(): array
    {
        return [
            'ip' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
        ];
    }
}
