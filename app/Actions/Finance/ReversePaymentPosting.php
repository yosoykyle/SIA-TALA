<?php

namespace App\Actions\Finance;

use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReversePaymentPosting
{
    public function __construct(private readonly TermAccountProjection $projection) {}

    public function execute(Payment $payment, User $actor, string $authorityReference, string $safeReason): Payment
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only Accounting staff may reverse a payment posting.');
        }

        return DB::transaction(function () use ($payment, $actor, $authorityReference, $safeReason): Payment {
            $locked = Payment::query()->with('allocations')->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $existing = Payment::query()->where('reverses_payment_id', $locked->id)->lockForUpdate()->first();
            if ($existing instanceof Payment) {
                return $existing->load('allocations');
            }
            if ($locked->state !== Payment::StatePosted || blank($authorityReference) || blank($safeReason)) {
                throw ValidationException::withMessages(['payment' => 'Only a posted payment may be reversed with attributable authority and a safe reason.']);
            }
            $reversal = Payment::query()->create([
                'reverses_payment_id' => $locked->id,
                'term_account_id' => $locked->term_account_id,
                'student_profile_id' => $locked->student_profile_id,
                'term_id' => $locked->term_id,
                'method' => $locked->method,
                'channel' => $locked->channel,
                'amount' => $locked->amount,
                'currency' => $locked->currency,
                'evidence_status' => 'verified',
                'state' => Payment::StateReversal,
                'paid_at' => now(),
                'verified_at' => now(),
                'verified_by' => $actor->id,
                'verification_basis' => 'AuthorizedReversal',
                'reversal_reason' => trim($safeReason),
                'reversal_authority_reference' => trim($authorityReference),
                'provider_reference' => 'REVERSAL-'.$locked->id,
            ]);
            foreach ($locked->allocations as $allocation) {
                PaymentAllocation::query()->create([
                    'payment_id' => $reversal->id,
                    'sequence' => $allocation->sequence,
                    'assessment_obligation_id' => $allocation->assessment_obligation_id,
                    'amount' => $allocation->amount,
                ]);
            }
            $position = $this->projection->forAccount($locked->termAccount);
            $locked->termAccount->update(['state' => $position['state'] === 'Cleared' ? 'Cleared' : 'Open']);

            return $reversal->load('allocations');
        }, attempts: 3);
    }
}
