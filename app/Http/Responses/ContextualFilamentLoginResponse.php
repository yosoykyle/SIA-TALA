<?php

namespace App\Http\Responses;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Models\User;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class ContextualFilamentLoginResponse implements LoginResponse
{
    public function __construct(private readonly WorkspaceContextResolver $contexts) {}

    public function toResponse($request): RedirectResponse|Redirector
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return redirect('/');
        }

        $requested = session()->pull('tala.requested_context');
        $available = $this->contexts->availableContexts($user);
        $this->contexts->explainUnavailableEntry(is_string($requested) ? $requested : null, $available);

        if (is_string($requested) && array_key_exists($requested, $available)) {
            return redirect()->intended($this->contexts->select($user, $requested));
        }

        if (count($available) === 1) {
            $context = array_key_first($available);

            return redirect()->intended($this->contexts->select($user, $context));
        }

        return redirect()->route('workspace-chooser');
    }
}
