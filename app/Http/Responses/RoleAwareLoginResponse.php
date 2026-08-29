<?php

namespace App\Http\Responses;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class RoleAwareLoginResponse implements LoginResponseContract
{
    public function __construct(private readonly WorkspaceContextResolver $contexts) {}

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

        $available = $this->contexts->availableContexts($user);
        $requested = $request->input('context');
        $this->contexts->explainUnavailableEntry(is_string($requested) ? $requested : null, $available);
        $contextCount = count($available);

        $workspacePath = match (true) {
            is_string($requested) && array_key_exists($requested, $available) => $this->contexts->select($user, $requested),
            $contextCount === 1 => $this->contexts->select($user, array_key_first($available)),
            $contextCount > 1 => route('workspace-chooser'),
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
