<?php

namespace App\Actions\Finance;

use App\Models\FeePlan;
use App\Models\FeePlanCharge;
use App\Models\FeePlanObligation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateFeePlanDraft
{
    /** @param list<array{code:string,label:string,amount:string,required_for_enrollment?:bool}> $charges */
    public function execute(FeePlan $feePlan, array $charges, User $actor): FeePlan
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only Accounting staff may edit a Draft Fee Plan.');
        }

        return DB::transaction(function () use ($feePlan, $charges): FeePlan {
            $locked = FeePlan::query()->whereKey($feePlan->id)->lockForUpdate()->firstOrFail();
            if ($locked->state !== FeePlan::StateDraft || $charges === []) {
                throw ValidationException::withMessages(['charges' => 'Only a Draft Fee Plan with fixed lines may be edited.']);
            }
            $locked->charges()->delete();
            $locked->obligations()->delete();
            foreach ($charges as $index => $charge) {
                if (blank($charge['code']) || blank($charge['label']) || (float) $charge['amount'] < 0) {
                    throw ValidationException::withMessages(['charges' => 'Each line requires a code, label, and non-negative fixed amount.']);
                }
                $amount = number_format((float) $charge['amount'], 2, '.', '');
                FeePlanCharge::query()->create(['fee_plan_id' => $locked->id, 'sequence' => $index + 1, 'code' => $charge['code'], 'label' => $charge['label'], 'amount' => $amount]);
                FeePlanObligation::query()->create(['fee_plan_id' => $locked->id, 'code' => $charge['code'], 'label' => $charge['label'], 'amount' => $amount, 'required_for_enrollment' => $charge['required_for_enrollment'] ?? true]);
            }

            return $locked->load(['charges', 'obligations']);
        }, attempts: 3);
    }
}
