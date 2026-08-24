<?php

namespace App\Http\Controllers;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceContextController extends Controller
{
    public function show(Request $request, WorkspaceContextResolver $resolver): View|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $contexts = $resolver->availableContexts($user);

        if (count($contexts) === 1) {
            $context = array_key_first($contexts);

            return redirect()->to($resolver->select($user, $context));
        }

        return view('auth.workspace-chooser', compact('contexts'));
    }

    public function store(Request $request, WorkspaceContextResolver $resolver): RedirectResponse
    {
        $validated = $request->validate(['context' => ['required', 'string']]);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return redirect()->to($resolver->select($user, $validated['context']));
    }
}
