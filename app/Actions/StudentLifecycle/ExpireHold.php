<?php

namespace App\Actions\StudentLifecycle;

use App\Models\Hold;

class ExpireHold
{
    public function execute(Hold $hold): Hold
    {
        if ($hold->status === Hold::StatusActive && $hold->expires_at?->isPast()) {
            $statusBefore = $hold->status;
            $hold->update(['status' => Hold::StatusExpired]);

            activity()
                ->performedOn($hold)
                ->event('hold_expired')
                ->withProperties([
                    'hold_type' => $hold->hold_type,
                    'blocking_level' => $hold->blocking_level,
                    'status_before' => $statusBefore,
                    'status_after' => Hold::StatusExpired,
                ])
                ->log('Hold expired');
        }

        return $hold->refresh();
    }
}
