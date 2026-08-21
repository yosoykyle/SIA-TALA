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
     * @param  list<array{code:string,label:string,amount:string,required_for_enrollment?:bool}>  $charges
     */
    public function execute(Program $program, Term $term, array $charges, User $actor): FeePlan
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only Accounting staff may prepare a Fee Plan.');
        }

        return DB::transaction(function () use ($program, $term, $charges, $actor): FeePlan {
            Program::query()->whereKey($program->id)->lockForUpdate()->firstOrFail();
            Term::query()->whereKey($term->id)->lockForUpdate()->firstOrFail();

            if ($charges === []) {
                throw ValidationException::withMessages(['charges' => 'A Fee Plan requires at least one fixed charge or explicit no-payment obligation.']);
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
                    'amount' => $amount,
                ]);
                FeePlanObligation::query()->create([
                    'fee_plan_id' => $plan->id,
                    'code' => $code,
                    'label' => $label,
                    'amount' => $amount,
                    'required_for_enrollment' => $charge['required_for_enrollment'] ?? true,
                ]);
            }

            return $plan->load(['charges', 'obligations']);
        }, attempts: 3);
    }
}
