<?php

namespace App\Actions\Finance;

use App\Models\ApprovedCoverage;
use App\Models\Assessment;
use App\Models\Payment;
use App\Models\TermAccount;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;

class TermAccountProjection
{
    public function __construct(private readonly DecimalMoney $money) {}

    /**
     * @return array{state:string,current_due:string,remaining_balance:string,assessment_id:?int,payment_applied:string,coverage_applied:string,next_obligation:?array<string,mixed>,as_of:string,obligations:list<array<string,mixed>>}
     */
    public function forAccount(TermAccount $account, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now(config('app.timezone'));
        $assessment = Assessment::query()
            ->with(['obligations.paymentAllocations.payment', 'obligations.coverages'])
            ->where('term_account_id', $account->id)
            ->where('state', Assessment::StateActive)
            ->latest('version')
            ->first();

        if (! $assessment instanceof Assessment) {
            return ['state' => 'Unavailable', 'current_due' => '0.00', 'remaining_balance' => '0.00', 'assessment_id' => null, 'payment_applied' => '0.00', 'coverage_applied' => '0.00', 'next_obligation' => null, 'as_of' => $asOf->toIso8601String(), 'obligations' => []];
        }

        $currentDueCents = 0;
        $remainingCents = 0;
        $paymentCents = 0;
        $coverageCents = 0;
        $rows = [];
        $next = null;

        foreach ($assessment->obligations->sortBy([['due_at', 'asc'], ['sequence', 'asc'], ['id', 'asc']]) as $obligation) {
            $posted = $obligation->paymentAllocations
                ->filter(fn ($allocation): bool => $allocation->payment?->state === Payment::StatePosted)
                ->sum(fn ($allocation): int => $this->money->toCents($allocation->amount));
            $reversed = $obligation->paymentAllocations
                ->filter(fn ($allocation): bool => $allocation->payment?->state === Payment::StateReversal)
                ->sum(fn ($allocation): int => $this->money->toCents($allocation->amount));
            $paymentEffect = max(0, $posted - $reversed);
            $coverageEffect = $obligation->coverages
                ->filter(fn (ApprovedCoverage $coverage): bool => $coverage->state === ApprovedCoverage::StateApplied
                    && $coverage->effective_date !== null && $coverage->effective_date->startOfDay()->lessThanOrEqualTo($asOf))
                ->sum(fn (ApprovedCoverage $coverage): int => $this->money->toCents($coverage->amount));
            $balance = max(0, $this->money->toCents($obligation->amount) - $paymentEffect - $coverageEffect);
            $due = $obligation->due_at !== null && $obligation->due_at->lessThanOrEqualTo($asOf);
            $remainingCents += $balance;
            $currentDueCents += $due ? $balance : 0;
            $paymentCents += $paymentEffect;
            $coverageCents += $coverageEffect;
            $row = [
                'id' => $obligation->id, 'code' => $obligation->code, 'label' => $obligation->label,
                'purpose' => $obligation->purpose, 'due_at' => $obligation->due_at?->toIso8601String(),
                'amount' => $this->money->normalize($obligation->amount), 'balance' => $this->money->fromCents($balance),
                'is_due' => $due, 'required_for_enrollment' => (bool) $obligation->required_for_enrollment,
            ];
            $rows[] = $row;
            if ($next === null && $balance > 0) {
                $next = $row;
            }
        }

        return [
            'state' => $currentDueCents === 0 ? 'Cleared' : 'ActionNeeded',
            'current_due' => $this->money->fromCents($currentDueCents),
            'remaining_balance' => $this->money->fromCents($remainingCents),
            'assessment_id' => $assessment->id,
            'payment_applied' => $this->money->fromCents($paymentCents),
            'coverage_applied' => $this->money->fromCents($coverageCents),
            'next_obligation' => $next,
            'as_of' => $asOf->toIso8601String(),
            'obligations' => $rows,
        ];
    }
}
