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
use Illuminate\Database\QueryException;
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
        try {
            $payment = DB::transaction(function () use ($evidence, $actor, $actualVerifiedAmount, $externalCheckReference, &$created): Payment {
                $account = TermAccount::query()->whereKey($evidence->term_account_id)->lockForUpdate()->firstOrFail();
                $locked = PaymentEvidenceVersion::query()->where('term_account_id', $account->id)->whereKey($evidence->id)->lockForUpdate()->firstOrFail();
                $existing = Payment::query()->where('payment_evidence_version_id', $locked->id)->lockForUpdate()->first();
                if ($existing instanceof Payment) {
                    return $existing;
                }
                $normalizedAmount = $this->money->normalize($actualVerifiedAmount);
                if ($locked->state !== PaymentEvidenceVersion::StateSubmitted || ! $this->money->greaterThanZero($normalizedAmount)
                    || blank($externalCheckReference) || blank($locked->channel) || $locked->paid_at === null) {
                    throw ValidationException::withMessages(['actual_verified_amount' => 'Submitted evidence requires an independently verified positive amount and source result.']);
                }

                $asOf = CarbonImmutable::now(config('app.timezone'));
                $position = $this->projection->forAccount($account, $asOf);
                $targetIds = collect($locked->obligation_snapshot ?? [])->pluck('id');
                $targets = collect($position['obligations'])->whereIn('id', $targetIds)->values();
                if ($position['state'] === 'Unavailable' || (int) $position['assessment_id'] !== (int) $locked->assessment_id
                    || $targetIds->isEmpty() || $targets->count() !== $targetIds->count()
                    || $this->money->toCents($normalizedAmount) > $targets->sum(fn (array $row): int => $this->money->toCents($row['available_balance']))) {
                    throw ValidationException::withMessages(['actual_verified_amount' => 'The captured assessment or named obligations changed, or the verified amount exceeds their unpaid balance. Refresh and resolve the exception before posting.']);
                }
                $duplicate = Payment::query()->where('state', Payment::StatePosted)
                    ->where(function ($query) use ($locked, $externalCheckReference): void {
                        $query->where('external_check_reference', trim($externalCheckReference))
                            ->orWhereHas('paymentEvidenceVersion', function ($evidenceQuery) use ($locked): void {
                                $evidenceQuery->where('checksum', $locked->checksum);
                                if (filled($locked->payment_reference)) {
                                    $evidenceQuery->orWhere(fn ($referenceQuery) => $referenceQuery->where('channel', $locked->channel)->where('payment_reference', $locked->payment_reference));
                                }
                            });
                    })->exists();
                if ($duplicate) {
                    throw ValidationException::withMessages(['evidence' => 'This receipt or independent source result already has a posting. Resolve the duplicate without posting it again.']);
                }

                $payment = Payment::query()->create([
                    'term_account_id' => $locked->term_account_id,
                    'payment_evidence_version_id' => $locked->id,
                    'evidence_checksum' => $locked->checksum,
                    'student_profile_id' => $account->enrollment?->student_profile_id,
                    'term_id' => $account->term_id,
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
                    'external_check_reference' => trim($externalCheckReference),
                    'provider_reference' => 'MANUAL-'.hash('sha256', filled($locked->payment_reference) ? $locked->channel.':'.$locked->payment_reference : trim($externalCheckReference)),
                ]);

                $remaining = $this->money->toCents($normalizedAmount);
                $sequence = 1;
                foreach ($targets as $row) {
                    if ($remaining === 0) {
                        continue;
                    }
                    $allocated = min($remaining, $this->money->toCents($row['available_balance']));
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
                $state = $this->projection->forAccount($account, $asOf);
                $account->update(['state' => $state['state'] === 'Cleared' ? TermAccount::StateCleared : TermAccount::StateOpen]);
                $created = true;

                return $payment->load('allocations');
            }, attempts: 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                throw ValidationException::withMessages(['evidence' => 'This receipt or external payment source already has a posting. Resolve the duplicate before retrying.']);
            }
            throw $exception;
        }

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
