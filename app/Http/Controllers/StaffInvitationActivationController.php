<?php

namespace App\Http\Controllers;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\SystemAdministration\StaffInvitationService;
use App\Models\StaffInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\Response;

class StaffInvitationActivationController extends Controller
{
    public function store(
        Request $request,
        StaffInvitation $invitation,
        StaffInvitationService $service,
        WorkspaceContextResolver $contexts,
    ): Response {
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'confirmed', Password::min(15)->max(64)->uncompromised()],
        ]);

        $user = $service->activate($invitation, $validated['token'], $validated['password'], now());
        Auth::login($user);
        $staffContext = collect($invitation->staff_roles)
            ->first(fn (string $role): bool => in_array($role, $user->roles->pluck('name')->all(), true));

        if (is_string($staffContext)) {
            $contexts->select($user, $staffContext);
        }

        return redirect()->route('filament.admin.auth.multi-factor-authentication.set-up-required');
    }
}
