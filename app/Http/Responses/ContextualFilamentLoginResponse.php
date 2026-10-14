<?php

namespace App\Http\Responses;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Filament\Applicant\Pages\Application;
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

        session()->forget('url.intended');

        $requested = session()->pull('tala.requested_context');
        $available = $this->contexts->availableContexts($user);
        $this->contexts->explainUnavailableEntry(is_string($requested) ? $requested : null, $available);

        if (is_string($requested) && array_key_exists($requested, $available)) {
            if ($requested === 'applicant' && session()->has('public_entry.program_id')) {
                $this->contexts->select($user, $requested);

                return redirect()->to(Application::getUrl(['program' => session('public_entry.program_id')], panel: 'applicant'));
            }

            return redirect()->to($this->contexts->select($user, $requested));
        }

        if (count($available) === 1) {
            $context = array_key_first($available);

            if ($context === 'applicant' && session()->has('public_entry.program_id')) {
                $this->contexts->select($user, $context);

                return redirect()->to(Application::getUrl(['program' => session('public_entry.program_id')], panel: 'applicant'));
            }

            return redirect()->to($this->contexts->select($user, $context));
        }

        return redirect()->route('workspace-chooser');
    }
}
