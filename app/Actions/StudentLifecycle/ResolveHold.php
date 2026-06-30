<?php

namespace App\Actions\StudentLifecycle;

use App\Models\Hold;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ResolveHold
{
    public function execute(Hold $hold, User $actor, string $evidence): Hold
    {
        if (! $this->authorized($hold, $actor)) {
            throw new AuthorizationException('The current office cannot resolve this hold.');
        }
        if (trim($evidence) === '') {
            throw new RuntimeException('Resolution evidence is required.');
        }

        return DB::transaction(function () use ($hold, $actor, $evidence): Hold {
            $locked = Hold::query()->lockForUpdate()->findOrFail($hold->id);
            if ($locked->status !== Hold::StatusActive) {
                return $locked;
            }
            $locked->update([
                'status' => Hold::StatusResolved,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
                'staff_only_reason' => trim(collect([$locked->staff_only_reason, 'Resolution evidence: '.$evidence])->filter()->implode("\n")),
            ]);

            return $locked->refresh();
        }, attempts: 3);
    }

    private function authorized(Hold $hold, User $actor): bool
    {
        return $actor->hasRole(User::StaffRoleSystemSuperAdmin)
            || ($hold->hold_type === Hold::TypeFinancial && $actor->hasRole(User::StaffRoleAccounting))
            || ($hold->hold_type !== Hold::TypeFinancial && $actor->hasRole(User::StaffRoleRegistrar));
    }
}
