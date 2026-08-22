<?php

namespace App\Actions\Finance;

use App\Models\FeePlan;
use App\Models\FeePlanCharge;
use App\Models\FeePlanObligation;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateFeePlanDraft
{
    /**
     * @param  list<array{code:string,label:string,category?:string,amount:string}>  $charges
     * @param  list<array{code:string,label:string,purpose:string,amount:string,due_at:string,required_for_enrollment?:bool}>  $obligations
     */
    public function execute(Program $program, Term $term, array $charges, User $actor, array $obligations = []): FeePlan
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only Accounting staff may prepare a Fee Plan.');
        }

        return DB::transaction(function () use ($program, $term, $charges, $actor, $obligations): FeePlan {
            Program::query()->whereKey($program->id)->lockForUpdate()->firstOrFail();
            Term::query()->whereKey($term->id)->lockForUpdate()->firstOrFail();

            if ($charges === [] || $obligations === []) {
                throw ValidationException::withMessages(['fee_plan' => 'A Fee Plan requires reconciled fixed charges and explicit dated obligations.']);
            }

            $version = ((int) FeePlan::query()->where('program_id', $program->id)->where('term_id', $term->id)->lockForUpdate()->max('version')) + 1;
            $plan = FeePlan::query()->create([
                'program_id' => $program->id,
                'term_id' => $term->id,
                'version' => $version,
                'state' => FeePlan::StateDraft,
                'currency' => 'PHP',
                'created_by' => $actor->id,
            ]);

            foreach ($charges as $index => $charge) {
                $amount = number_format((float) $charge['amount'], 2, '.', '');
                $code = trim($charge['code']);
                $label = trim($charge['label']);

                if ($code === '' || $label === '' || (float) $amount < 0) {
                    throw ValidationException::withMessages(['charges' => 'Each Fee Plan line requires a code, label, and non-negative fixed amount.']);
                }

                FeePlanCharge::query()->create([
                    'fee_plan_id' => $plan->id,
                    'sequence' => $index + 1,
                    'code' => $code,
                    'label' => $label,
                    'category' => $charge['category'] ?? null,
                    'amount' => $amount,
                ]);
            }

            foreach ($obligations as $index => $obligation) {
                $amount = number_format((float) $obligation['amount'], 2, '.', '');
                if (blank($obligation['code']) || blank($obligation['label']) || blank($obligation['purpose'])
                    || blank($obligation['due_at']) || (float) $amount < 0) {
                    throw ValidationException::withMessages(['obligations' => 'Each obligation requires a code, label, purpose, due time, and non-negative amount.']);
                }

                FeePlanObligation::query()->create([
                    'fee_plan_id' => $plan->id,
                    'sequence' => $index + 1,
                    'code' => trim($obligation['code']),
                    'label' => trim($obligation['label']),
                    'purpose' => trim($obligation['purpose']),
                    'amount' => $amount,
                    'due_at' => $obligation['due_at'],
                    'required_for_enrollment' => $obligation['required_for_enrollment'] ?? false,
                ]);
            }

            $chargeTotal = number_format((float) collect($charges)->sum('amount'), 2, '.', '');
            $obligationTotal = number_format((float) collect($obligations)->sum('amount'), 2, '.', '');
            if ($chargeTotal !== $obligationTotal) {
                throw ValidationException::withMessages(['obligations' => 'Obligations must reconcile exactly to fixed charges.']);
            }

            return $plan->load(['charges', 'obligations']);
        }, attempts: 3);
    }
}
