<?php

namespace App\Actions\Finance;

use App\Models\ApprovedCoverage;
use App\Models\Assessment;
use App\Models\AssessmentEffectRevalidation;
use App\Models\AssessmentObligation;
use App\Models\Enrollment;
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
            return ['state' => 'Unavailable', 'reason' => 'Accounting must assess the current reserved proposal.', 'current_due' => null, 'remaining_balance' => null, 'assessment_id' => null, 'payment_applied' => '0.00', 'coverage_applied' => '0.00', 'next_obligation' => null, 'as_of' => $asOf->toIso8601String(), 'obligations' => [], 'reconciliation_required' => false, 'retained_payment' => '0.00', 'retained_coverage' => '0.00'];
        }

        $retained = $this->retainedEffects($account, $assessment, $asOf);
        $revalidation = AssessmentEffectRevalidation::query()->where('assessment_id', $assessment->id)->latest('version')->first();
        $mapping = collect($revalidation?->obligation_mapping ?? [])->keyBy('source_obligation_id');
        $currentIds = $assessment->obligations->pluck('id');
        $reconciliationRequired = collect($retained)->contains(fn (array $source): bool => ! $mapping->has($source['id'])
            || ! $currentIds->contains($mapping->get($source['id'])['target_obligation_id'])
            || $source['payment'] > $this->money->toCents($mapping->get($source['id'])['payment_amount'] ?? '0.00')
            || $source['committed_coverage'] > $this->money->toCents($mapping->get($source['id'])['coverage_amount'] ?? '0.00'));
        $invalid = $assessment->obligations->isEmpty() || $reconciliationRequired;
        $enrollment = $account->enrollment()->first();
        if ($enrollment?->canonical_outcome === Enrollment::OutcomeInProgress
            && (int) $assessment->source_proposal_version_id !== (int) $enrollment->current_proposal_version_id) {
            $invalid = true;
        }

        $currentDueCents = 0;
        $remainingCents = 0;
        $paymentCents = 0;
        $coverageCents = 0;
        $rows = [];
        $next = null;

        foreach ($assessment->obligations->sortBy([['due_at', 'asc'], ['sequence', 'asc'], ['id', 'asc']]) as $obligation) {
            $effects = $this->effects($obligation, $asOf);
            if (! $reconciliationRequired) {
                foreach ($retained as $source) {
                    if ((int) data_get($mapping->get($source['id']), 'target_obligation_id') === (int) $obligation->id) {
                        foreach (['payment', 'coverage', 'committed_coverage'] as $key) {
                            $effects[$key] += $source[$key];
                        }
                    }
                }
            }
            $paymentEffect = $effects['payment'];
            $coverageEffect = $effects['coverage'];
            if ($paymentEffect < 0 || $paymentEffect + $effects['committed_coverage'] > $this->money->toCents($obligation->amount)) {
                $invalid = true;
            }
            $balance = max(0, $this->money->toCents($obligation->amount) - $paymentEffect - $coverageEffect);
            $due = ($obligation->due_at !== null && $obligation->due_at->lessThanOrEqualTo($asOf))
                || ($enrollment?->canonical_outcome === Enrollment::OutcomeInProgress && $obligation->required_for_enrollment);
            $remainingCents += $balance;
            $currentDueCents += $due ? $balance : 0;
            $paymentCents += $paymentEffect;
            $coverageCents += $coverageEffect;
            $row = [
                'id' => $obligation->id, 'code' => $obligation->code, 'label' => $obligation->label,
                'purpose' => $obligation->purpose, 'due_at' => $obligation->due_at?->toIso8601String(),
                'amount' => $this->money->normalize($obligation->amount), 'balance' => $this->money->fromCents($balance),
                'is_due' => $due, 'required_for_enrollment' => (bool) $obligation->required_for_enrollment,
                'payment_applied' => $this->money->fromCents($paymentEffect),
                'coverage_applied' => $this->money->fromCents($coverageEffect),
                'available_balance' => $this->money->fromCents(max(0, $this->money->toCents($obligation->amount) - $paymentEffect - $effects['committed_coverage'])),
            ];
            $rows[] = $row;
            if ($next === null && $balance > 0) {
                $next = $row;
            }
        }

        return [
            'state' => $invalid ? 'Unavailable' : ($currentDueCents === 0 ? 'Cleared' : 'ActionNeeded'),
            'reason' => $invalid ? 'Accounting must reconcile the current assessment and retained payment or coverage sources.' : null,
            'current_due' => $invalid ? null : $this->money->fromCents($currentDueCents),
            'remaining_balance' => $invalid ? null : $this->money->fromCents($remainingCents),
            'assessment_id' => $assessment->id,
            'payment_applied' => $this->money->fromCents($paymentCents),
            'coverage_applied' => $this->money->fromCents($coverageCents),
            'next_obligation' => $next,
            'as_of' => $asOf->toIso8601String(),
            'obligations' => $rows,
            'reconciliation_required' => $reconciliationRequired,
            'retained_payment' => $this->money->fromCents((int) collect($retained)->sum('payment')),
            'retained_coverage' => $this->money->fromCents((int) collect($retained)->sum('committed_coverage')),
        ];
    }

    /** @return array{payment:int,coverage:int,committed_coverage:int} */
    public function effects(AssessmentObligation $obligation, CarbonImmutable $asOf): array
    {
        $obligation->loadMissing(['paymentAllocations.payment', 'coverages']);
        $payment = $obligation->paymentAllocations->sum(fn ($allocation): int => match ($allocation->payment?->state) {
            Payment::StatePosted => $this->money->toCents($allocation->amount),
            Payment::StateReversal => -$this->money->toCents($allocation->amount),
            default => 0,
        });
        $coverage = $obligation->coverages->where('state', ApprovedCoverage::StateApplied);

        return [
            'payment' => (int) $payment,
            'coverage' => (int) $coverage->filter(fn (ApprovedCoverage $row): bool => $row->effective_date !== null && $row->effective_date->copy()->startOfDay()->lessThanOrEqualTo($asOf))->sum(fn (ApprovedCoverage $row): int => $this->money->toCents($row->amount)),
            'committed_coverage' => (int) $coverage->sum(fn (ApprovedCoverage $row): int => $this->money->toCents($row->amount)),
        ];
    }

    /** @return list<array{id:int,code:string,label:string,payment:int,coverage:int,committed_coverage:int}> */
    public function retainedEffects(TermAccount $account, Assessment $assessment, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now(config('app.timezone'));

        return AssessmentObligation::query()
            ->with(['paymentAllocations.payment', 'coverages'])
            ->where('assessment_id', '!=', $assessment->id)
            ->whereHas('assessment', fn ($query) => $query->where('term_account_id', $account->id)->where('version', '<', $assessment->version))
            ->orderBy('id')->get()
            ->map(fn (AssessmentObligation $obligation): array => ['id' => $obligation->id, 'code' => $obligation->code, 'label' => $obligation->label, ...$this->effects($obligation, $asOf)])
            ->filter(fn (array $row): bool => $row['payment'] !== 0 || $row['committed_coverage'] !== 0)
            ->values()->all();
    }
}
