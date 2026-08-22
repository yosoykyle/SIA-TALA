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
    /**
     * @param  list<array{code:string,label:string,category?:string,amount:string}>  $charges
     * @param  list<array{code:string,label:string,purpose:string,amount:string,due_at:string,required_for_enrollment?:bool}>  $obligations
     */
    public function execute(FeePlan $feePlan, array $charges, array $obligations, User $actor): FeePlan
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only Accounting staff may edit a Draft Fee Plan.');
        }

        return DB::transaction(function () use ($feePlan, $charges, $obligations): FeePlan {
            $locked = FeePlan::query()->whereKey($feePlan->id)->lockForUpdate()->firstOrFail();
            if ($locked->state !== FeePlan::StateDraft || $charges === [] || $obligations === []) {
                throw ValidationException::withMessages(['charges' => 'Only a Draft Fee Plan with fixed lines may be edited.']);
            }
            $locked->charges()->delete();
            $locked->obligations()->delete();
            foreach ($charges as $index => $charge) {
                if (blank($charge['code']) || blank($charge['label']) || (float) $charge['amount'] < 0) {
                    throw ValidationException::withMessages(['charges' => 'Each line requires a code, label, and non-negative fixed amount.']);
                }
                $amount = number_format((float) $charge['amount'], 2, '.', '');
                FeePlanCharge::query()->create(['fee_plan_id' => $locked->id, 'sequence' => $index + 1, 'code' => $charge['code'], 'label' => $charge['label'], 'category' => $charge['category'] ?? null, 'amount' => $amount]);
            }
            foreach ($obligations as $index => $obligation) {
                if (blank($obligation['code']) || blank($obligation['label']) || blank($obligation['purpose']) || blank($obligation['due_at']) || (float) $obligation['amount'] < 0) {
                    throw ValidationException::withMessages(['obligations' => 'Each obligation requires a code, label, purpose, due time, and non-negative amount.']);
                }
                FeePlanObligation::query()->create([
                    'fee_plan_id' => $locked->id, 'sequence' => $index + 1, 'code' => $obligation['code'],
                    'label' => $obligation['label'], 'purpose' => $obligation['purpose'],
                    'amount' => number_format((float) $obligation['amount'], 2, '.', ''), 'due_at' => $obligation['due_at'],
                    'required_for_enrollment' => $obligation['required_for_enrollment'] ?? false,
                ]);
            }
            if (number_format((float) collect($charges)->sum('amount'), 2, '.', '') !== number_format((float) collect($obligations)->sum('amount'), 2, '.', '')) {
                throw ValidationException::withMessages(['obligations' => 'Obligations must reconcile exactly to fixed charges.']);
            }

            return $locked->load(['charges', 'obligations']);
        }, attempts: 3);
    }
}
