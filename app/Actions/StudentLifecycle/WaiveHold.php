<?php

namespace App\Actions\StudentLifecycle;

use App\Models\Hold;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WaiveHold
{
    public function execute(Hold $hold, User $actor, string $authority, string $reason): Hold
    {
        if (! $actor->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAccounting, User::StaffRoleSystemSuperAdmin])) {
            throw new AuthorizationException('The current user cannot waive holds.');
        }
        if (blank($authority) || blank($reason)) {
            throw new RuntimeException('Waiver authority and reason are required.');
        }

        return DB::transaction(function () use ($hold, $actor, $authority, $reason): Hold {
            $locked = Hold::query()->lockForUpdate()->findOrFail($hold->id);
            if ($locked->status !== Hold::StatusActive) {
                return $locked;
            }
            $locked->update([
                'status' => Hold::StatusWaived,
                'waived_by' => $actor->id,
                'waived_at' => now(),
                'staff_only_reason' => trim(collect([$locked->staff_only_reason, "Waived by authority [$authority]: $reason"])->filter()->implode("\n")),
            ]);

            return $locked->refresh();
        }, attempts: 3);
    }
}
