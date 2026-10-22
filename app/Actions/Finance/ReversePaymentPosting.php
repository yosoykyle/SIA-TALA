<?php

namespace App\Actions\Finance;

use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\TermAccount;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReversePaymentPosting
{
    public function __construct(private readonly TermAccountProjection $projection, private readonly DecimalMoney $money) {}

    public function execute(Payment $payment, User $actor, string $authorityReference, string $safeReason, string $externalRefundReference, string $refundAmount, mixed $refundedAt): Payment
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only Accounting staff may reverse a payment posting.');
        }

        Validator::make(['reference' => $externalRefundReference, 'amount' => $refundAmount, 'refunded_at' => $refundedAt], [
            'reference' => ['required', 'string', 'max:255'], 'amount' => ['required', 'numeric', 'gt:0'], 'refunded_at' => ['required', 'date', 'before_or_equal:now'],
        ])->validate();

        return DB::transaction(function () use ($payment, $actor, $authorityReference, $safeReason, $externalRefundReference, $refundAmount, $refundedAt): Payment {
            $account = TermAccount::query()->whereKey($payment->term_account_id)->lockForUpdate()->firstOrFail();
            $locked = Payment::query()->with('allocations')->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $amount = $this->money->normalize($refundAmount);
            $existing = Payment::query()->where('term_account_id', $account->id)->where('external_refund_reference', trim($externalRefundReference))->lockForUpdate()->first();
            if ($existing instanceof Payment) {
                if ((int) $existing->reverses_payment_id !== (int) $locked->id || $existing->amount !== $amount) {
                    throw ValidationException::withMessages(['payment' => 'The external refund reference already identifies a different correction.']);
                }

                return $existing->load('allocations');
            }
            if ($locked->state !== Payment::StatePosted || blank($authorityReference) || blank($safeReason)) {
                throw ValidationException::withMessages(['payment' => 'Only a posted payment may be reversed with attributable authority and a safe reason.']);
            }
            $previous = PaymentAllocation::query()->whereHas('payment', fn ($query) => $query->where('reverses_payment_id', $locked->id)->where('state', Payment::StateReversal))->get()->groupBy('assessment_obligation_id');
            $available = $locked->allocations->mapWithKeys(fn (PaymentAllocation $allocation): array => [$allocation->assessment_obligation_id => $this->money->toCents($allocation->amount) - (int) collect($previous->get($allocation->assessment_obligation_id, []))->sum(fn ($row): int => $this->money->toCents($row->amount))]);
            if ($this->money->toCents($amount) > $available->sum() || CarbonImmutable::parse($refundedAt)->lessThan($locked->paid_at)) {
                throw ValidationException::withMessages(['payment' => 'The actual refund must follow the original payment and fit its remaining posted allocations.']);
            }
            $reversal = Payment::query()->create([
                'reverses_payment_id' => $locked->id,
                'term_account_id' => $locked->term_account_id,
                'student_profile_id' => $locked->student_profile_id,
                'term_id' => $locked->term_id,
                'method' => $locked->method,
                'channel' => $locked->channel,
                'amount' => $amount,
                'currency' => $locked->currency,
                'evidence_status' => 'verified',
                'state' => Payment::StateReversal,
                'paid_at' => $refundedAt,
                'verified_at' => now(),
                'verified_by' => $actor->id,
                'verification_basis' => 'VerifiedExternalRefund',
                'reversal_reason' => trim($safeReason),
                'reversal_authority_reference' => trim($authorityReference),
                'external_refund_reference' => trim($externalRefundReference),
                'external_refund_amount' => $amount,
                'external_refund_at' => $refundedAt,
                'provider_reference' => 'REFUND-'.hash('sha256', $account->id.':'.trim($externalRefundReference)),
            ]);
            $remaining = $this->money->toCents($amount);
            foreach ($locked->allocations as $allocation) {
                $allocated = min($remaining, $available->get($allocation->assessment_obligation_id, 0));
                if ($allocated <= 0) {
                    continue;
                }
                PaymentAllocation::query()->create([
                    'payment_id' => $reversal->id,
                    'sequence' => $allocation->sequence,
                    'assessment_obligation_id' => $allocation->assessment_obligation_id,
                    'amount' => $this->money->fromCents($allocated),
                ]);
                $remaining -= $allocated;
            }
            $position = $this->projection->forAccount($account);
            $account->update(['state' => $position['state'] === 'Cleared' ? TermAccount::StateCleared : TermAccount::StateOpen]);

            return $reversal->load('allocations');
        }, attempts: 3);
    }
}
