<?php

namespace App\Http\Middleware;

use App\Actions\Authentication\UserSessionService;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceCanonicalSessionPolicy
{
    public function __construct(private readonly UserSessionService $sessions) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $lastActivity = $request->session()->get('tala.last_activity_at');
        $idleSeconds = $this->sessions->idleTimeoutMinutes($user) * 60;

        if (is_int($lastActivity) && now()->timestamp - $lastActivity > $idleSeconds) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('home')->with('status', 'Your session ended due to inactivity. Sign in again.');
        }

        $request->session()->put('tala.last_activity_at', now()->timestamp);

        return $next($request);
    }
}
