<?php

namespace App\Http\Responses;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class RoleAwareLoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User && $user->hasAnyRole(User::staffRoleNames())) {
            return redirect()->intended('/admin');
        }

        return redirect()->intended(config('fortify.home'));
    }
}
