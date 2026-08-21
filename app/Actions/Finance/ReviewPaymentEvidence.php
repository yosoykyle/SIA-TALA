<?php

namespace App\Actions\Finance;

use App\Models\AssessmentObligation;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentEvidenceVersion;
use App\Models\TermAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewPaymentEvidence
{
    public function __construct(private readonly EnrollmentPaymentRequirementProjection $projection) {}

    /**
     * @param  array<int,string>  $allocations  obligation id => amount
     */
    public function verify(PaymentEvidenceVersion $evidence, User $actor, array $allocations, ?string $officialReceiptNumber = null): Payment
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only Accounting staff may review payment evidence.');
        }

        return DB::transaction(function () use ($evidence, $actor, $allocations, $officialReceiptNumber): Payment {
            $locked = PaymentEvidenceVersion::query()->with('termAccount')->whereKey($evidence->id)->lockForUpdate()->firstOrFail();
            $existing = Payment::query()->where('payment_evidence_version_id', $locked->id)->lockForUpdate()->first();
            if ($existing instanceof Payment) {
                return $existing;
            }
            if ($locked->state !== PaymentEvidenceVersion::StateSubmitted || $allocations === []) {
                throw ValidationException::withMessages(['evidence' => 'Only submitted evidence with exact obligation allocations may be verified.']);
            }

            $total = 0.0;
            $obligations = AssessmentObligation::query()->whereIn('id', array_keys($allocations))->lockForUpdate()->get()->keyBy('id');
            foreach ($allocations as $obligationId => $amount) {
                $obligation = $obligations->get((int) $obligationId);
                if (! $obligation instanceof AssessmentObligation
                    || (int) $obligation->assessment?->term_account_id !== (int) $locked->term_account_id
                    || (float) $amount <= 0 || (float) $amount > (float) $obligation->amount) {
                    throw ValidationException::withMessages(['allocations' => 'Every allocation must target an exact current-account obligation within its amount.']);
                }
                $total += (float) $amount;
            }
            if ($total > (float) $locked->claimed_amount + 0.004) {
                throw ValidationException::withMessages(['allocations' => 'Allocated payment cannot exceed verified evidence amount.']);
            }

            $payment = Payment::query()->create([
                'term_account_id' => $locked->term_account_id,
                'payment_evidence_version_id' => $locked->id,
                'student_profile_id' => $locked->termAccount->enrollment?->student_profile_id,
                'term_id' => $locked->termAccount->term_id,
                'method' => 'manual_evidence',
                'channel' => 'bank_transfer',
                'amount' => number_format($total, 2, '.', ''),
                'currency' => 'PHP',
                'evidence_status' => 'verified',
                'paid_at' => $locked->submitted_at,
                'verified_at' => now(),
                'verified_by' => $actor->id,
                'or_number' => $officialReceiptNumber,
                'provider_reference' => 'EVIDENCE-'.$locked->id,
            ]);
            foreach ($allocations as $obligationId => $amount) {
                PaymentAllocation::query()->create([
                    'payment_id' => $payment->id,
                    'assessment_obligation_id' => (int) $obligationId,
                    'amount' => number_format((float) $amount, 2, '.', ''),
                ]);
            }
            $locked->update(['state' => PaymentEvidenceVersion::StateVerified, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
            $state = $this->projection->forEnrollment($locked->termAccount->enrollment);
            $locked->termAccount->update(['state' => $state['state'] === 'Cleared' ? TermAccount::StateCleared : TermAccount::StateOpen]);

            return $payment->load('allocations');
        }, attempts: 3);
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
