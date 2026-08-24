<?php

namespace App\Actions\Authentication;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserSessionService
{
    public function idleTimeoutMinutes(User $user): int
    {
        return $user->isStaffCapable() ? 30 : 120;
    }

    public function rememberAllowed(User $user): bool
    {
        return ! $user->isStaffCapable();
    }

    public function revokeAll(User $user, ?string $exceptSessionId = null): int
    {
        $query = DB::table('sessions')->where('user_id', $user->id);

        if (filled($exceptSessionId)) {
            $query->where('id', '!=', $exceptSessionId);
        }

        return $query->delete();
    }
}
