<?php

namespace App\Http\Controllers;

use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class SessionActivityController extends Controller
{
    /**
     * Confirm that the signed-in person is still present.
     *
     * The canonical session policy middleware on this route records the renewed
     * activity; it also ends a session that has already passed its idle limit.
     */
    public function continue(): Response
    {
        return response()->noContent();
    }

    /**
     * End the session after the on-screen inactivity countdown reaches zero and
     * return the person to the sign-in page of the workspace they were using.
     */
    public function expire(Request $request): RedirectResponse
    {
        $panelId = (string) $request->input('panel');
        $loginUrl = array_key_exists($panelId, Filament::getPanels())
            ? Filament::getPanel($panelId)->getLoginUrl()
            : null;

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Notification::make()
            ->title('Your session ended due to inactivity')
            ->body('Sign in again to continue.')
            ->warning()
            ->persistent()
            ->send();

        return redirect()->to($loginUrl ?? route('home'));
    }
}
