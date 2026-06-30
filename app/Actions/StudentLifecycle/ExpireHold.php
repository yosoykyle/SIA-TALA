<?php

namespace App\Actions\StudentLifecycle;

use App\Models\Hold;

class ExpireHold
{
    public function execute(Hold $hold): Hold
    {
        if ($hold->status === Hold::StatusActive && $hold->expires_at?->isPast()) {
            $hold->update(['status' => Hold::StatusExpired]);
        }

        return $hold->refresh();
    }
}
