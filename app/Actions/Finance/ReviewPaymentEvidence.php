<?php

namespace App\Actions\Finance;

use App\Actions\Integrations\Payments\PaymentPostedNotificationService;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentEvidenceVersion;
use App\Models\TermAccount;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewPaymentEvidence
{
    public function __construct(
        private readonly TermAccountProjection $projection,
        private readonly DecimalMoney $money,
        private readonly PaymentPostedNotificationService $notificationService,
    ) {}

    public function verify(PaymentEvidenceVersion $evidence, User $actor, string $actualVerifiedAmount, string $externalCheckReference): Payment
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only Accounting staff may review payment evidence.');
        }

        $created = false;
        $payment = DB::transaction(function () use ($evidence, $actor, $actualVerifiedAmount, $externalCheckReference, &$created): Payment {
            $locked = PaymentEvidenceVersion::query()->with('termAccount')->whereKey($evidence->id)->lockForUpdate()->firstOrFail();
            $existing = Payment::query()->where('payment_evidence_version_id', $locked->id)->lockForUpdate()->first();
            if ($existing instanceof Payment) {
                return $existing;
            }
            $normalizedAmount = $this->money->normalize($actualVerifiedAmount);
            if ($locked->state !== PaymentEvidenceVersion::StateSubmitted || ! $this->money->greaterThanZero($normalizedAmount)
                || blank($externalCheckReference) || blank($locked->channel) || $locked->paid_at === null) {
                throw ValidationException::withMessages(['actual_verified_amount' => 'Submitted evidence requires an independently verified positive amount and source result.']);
            }

            $asOf = CarbonImmutable::parse($locked->paid_at ?? now(), config('app.timezone'));
            $position = $this->projection->forAccount($locked->termAccount, $asOf);
            if ($this->money->toCents($normalizedAmount) > $this->money->toCents($position['current_due'])) {
                throw ValidationException::withMessages(['actual_verified_amount' => 'The verified amount exceeds currently due obligations and must remain an exception.']);
            }

            $payment = Payment::query()->create([
                'term_account_id' => $locked->term_account_id,
                'payment_evidence_version_id' => $locked->id,
                'student_profile_id' => $locked->termAccount->enrollment?->student_profile_id,
                'term_id' => $locked->termAccount->term_id,
                'method' => 'manual_evidence',
                'channel' => $locked->channel,
                'amount' => $normalizedAmount,
                'currency' => 'PHP',
                'evidence_status' => 'verified',
                'state' => Payment::StatePosted,
                'paid_at' => $locked->paid_at,
                'verified_at' => now(),
                'verified_by' => $actor->id,
                'verification_basis' => 'IndependentSourceCheck',
                'external_check_reference' => $externalCheckReference,
                'provider_reference' => 'EVIDENCE-'.$locked->id,
            ]);

            $remaining = $this->money->toCents($normalizedAmount);
            $sequence = 1;
            foreach ($position['obligations'] as $row) {
                if (! $row['is_due'] || $remaining === 0) {
                    continue;
                }
                $allocated = min($remaining, $this->money->toCents($row['balance']));
                if ($allocated === 0) {
                    continue;
                }
                PaymentAllocation::query()->create([
                    'payment_id' => $payment->id,
                    'sequence' => $sequence++,
                    'assessment_obligation_id' => $row['id'],
                    'amount' => $this->money->fromCents($allocated),
                ]);
                $remaining -= $allocated;
            }
            $locked->update([
                'state' => PaymentEvidenceVersion::StateVerified, 'reviewed_by' => $actor->id, 'reviewed_at' => now(),
                'external_check_reference' => $externalCheckReference, 'actual_verified_amount' => $normalizedAmount,
            ]);
            $state = $this->projection->forAccount($locked->termAccount, $asOf);
            $locked->termAccount->update(['state' => $state['state'] === 'Cleared' ? TermAccount::StateCleared : TermAccount::StateOpen]);
            $created = true;

            return $payment->load('allocations');
        }, attempts: 3);

        if ($created) {
            DB::afterCommit(fn () => $this->notificationService->record($payment));
        }

        return $payment;
    }

    public function reject(PaymentEvidenceVersion $evidence, User $actor, string $safeReason): PaymentEvidenceVersion
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only Accounting staff may review payment evidence.');
        }

        return DB::transaction(function () use ($evidence, $actor, $safeReason): PaymentEvidenceVersion {
            $locked = PaymentEvidenceVersion::query()->whereKey($evidence->id)->lockForUpdate()->firstOrFail();
            if ($locked->state !== PaymentEvidenceVersion::StateSubmitted) {
                throw ValidationException::withMessages(['evidence' => 'Only submitted evidence may be rejected.']);
            }
            $locked->update(['state' => PaymentEvidenceVersion::StateRejected, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $safeReason]);

            return $locked->refresh();
        }, attempts: 3);
    }
}
