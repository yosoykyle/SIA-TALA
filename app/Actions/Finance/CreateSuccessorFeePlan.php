<?php

namespace App\Actions\Finance;

use App\Models\FeePlan;
use App\Models\FeePlanCharge;
use App\Models\FeePlanObligation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateSuccessorFeePlan
{
    public function execute(FeePlan $publishedPlan, User $actor): FeePlan
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only Accounting staff may prepare a successor Fee Plan.');
        }

        return DB::transaction(function () use ($publishedPlan, $actor): FeePlan {
            $locked = FeePlan::query()->with(['charges', 'obligations'])->whereKey($publishedPlan->id)->lockForUpdate()->firstOrFail();

            if ($locked->state !== FeePlan::StatePublished) {
                throw ValidationException::withMessages(['fee_plan' => 'Only the current Published Fee Plan may receive a successor.']);
            }

            $existing = FeePlan::query()->where('supersedes_fee_plan_id', $locked->id)->lockForUpdate()->first();
            if ($existing instanceof FeePlan) {
                return $existing->load(['charges', 'obligations']);
            }

            $successor = FeePlan::query()->create([
                'program_id' => $locked->program_id,
                'term_id' => $locked->term_id,
                'supersedes_fee_plan_id' => $locked->id,
                'version' => $locked->version + 1,
                'state' => FeePlan::StateDraft,
                'currency' => $locked->currency,
                'created_by' => $actor->id,
            ]);
            foreach ($locked->charges as $charge) {
                FeePlanCharge::query()->create(['fee_plan_id' => $successor->id, ...$charge->only(['sequence', 'code', 'label', 'amount'])]);
            }
            foreach ($locked->obligations as $obligation) {
                FeePlanObligation::query()->create(['fee_plan_id' => $successor->id, ...$obligation->only(['code', 'label', 'amount', 'required_for_enrollment'])]);
            }

            return $successor->load(['charges', 'obligations']);
        }, attempts: 3);
    }
}
