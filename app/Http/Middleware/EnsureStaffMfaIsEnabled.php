<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffMfaIsEnabled
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User
            && $user->isStaffCapable()
            && (blank($user->getAppAuthenticationSecret()) || $user->two_factor_recovery_codes_acknowledged_at === null)) {
            return redirect()->guest(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
        }

        return $next($request);
    }
}
