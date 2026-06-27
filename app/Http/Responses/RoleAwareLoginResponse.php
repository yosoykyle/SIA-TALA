<?php

namespace App\Http\Responses;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class RoleAwareLoginResponse implements LoginResponseContract
{
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['two_factor' => false]);
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return redirect()->to(config('fortify.home'));
        }

        if (! $user->hasVerifiedEmail()) {
            return redirect()->route($this->verificationPromptRoute($user));
        }

        $workspacePath = match (true) {
            $user->hasAnyRole(User::staffRoleNames()) => '/admin',
            $user->hasRole('student') => '/student',
            $user->hasRole('applicant') => '/applicant',
            default => config('fortify.home'),
        };

        return redirect()->to($workspacePath);
    }

    private function verificationPromptRoute(User $user): string
    {
        return match (true) {
            $user->hasAnyRole(User::staffRoleNames()) => 'filament.admin.auth.email-verification.prompt',
            $user->hasRole('student') => 'filament.student.auth.email-verification.prompt',
            $user->hasRole('applicant') => 'filament.applicant.auth.email-verification.prompt',
            default => 'filament.applicant.auth.email-verification.prompt',
        };
    }
}
