<?php

namespace App\Actions\Integrations\Payments;

use App\Actions\Finance\EnrollmentPaymentRequirementProjection;
use App\Actions\Finance\PaymentAllocationService;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\StudentProfile;
use App\Models\TermAccount;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use RuntimeException;

final class PayMongoPaymentPostingService
{
    public function __construct(
        private readonly DecimalMoney $money,
        private readonly EnrollmentPaymentRequirementProjection $paymentRequirementProjection,
        private readonly PaymentAllocationService $paymentAllocationService,
        private readonly PaymentPostedNotificationService $paymentPostedNotificationService,
        private readonly ExactDuePaymentSnapshotService $snapshots,
    ) {}

    /**
     * @return array{status:string,payment:Payment,ledger_entry:LedgerEntry,finance_cleared:bool}
     */
    public function post(
        PaymentAttempt $attempt,
        string $amount,
        string $providerReference,
        ?User $actor,
        CarbonImmutable $timestamp,
        string $description,
    ): array {
        $normalizedAmount = $this->money->normalize($amount);

        if (! $this->money->greaterThanZero($normalizedAmount)) {
            throw new RuntimeException('PayMongo payment amount must be greater than zero.');
        }

        $assessment = Assessment::query()->lockForUpdate()->findOrFail($attempt->assessment_id);
        $account = $attempt->term_account_id !== null
            ? TermAccount::query()->lockForUpdate()->find($attempt->term_account_id)
            : null;
        $enrollment = Enrollment::query()
            ->with('studentProfile')
            ->lockForUpdate()
            ->find($assessment->enrollment_id);
        $student = $enrollment?->studentProfile;

        if ($attempt->provider !== 'paymongo'
            || $assessment->state !== Assessment::StateActive
            || ! $account instanceof TermAccount
            || $assessment->term_account_id !== $account->id
            || $account->enrollment_id !== $assessment->enrollment_id
            || ! $enrollment instanceof Enrollment
            || ! $student instanceof StudentProfile
            || $enrollment->student_profile_id !== $attempt->student_profile_id) {
            throw new RuntimeException('PayMongo payment source is no longer eligible for posting.');
        }

        $conflict = Payment::query()
            ->where('provider_reference', $providerReference)
            ->where('payment_attempt_id', '!=', $attempt->id)
            ->lockForUpdate()
            ->exists();

        if ($conflict) {
            throw new RuntimeException('PayMongo provider reference is already linked to another payment.');
        }

        $existingPayment = Payment::query()
            ->with('ledgerEntry')
            ->where('payment_attempt_id', $attempt->id)
            ->lockForUpdate()
            ->first();
        $wasPosted = $existingPayment instanceof Payment
            && $existingPayment->evidence_status === 'verified'
            && $existingPayment->ledgerEntry instanceof LedgerEntry;

        if ($wasPosted) {
            $attempt->forceFill([
                'status' => PaymentAttempt::StatusConfirmed,
                'paid_at' => $existingPayment->paid_at,
            ])->save();

            return [
                'status' => 'duplicate',
                'payment' => $existingPayment,
                'ledger_entry' => $existingPayment->ledgerEntry,
                'finance_cleared' => $account->state === TermAccount::StateCleared,
            ];
        }

        if ($normalizedAmount !== $this->money->normalize((string) $attempt->amount)) {
            throw new PaymentAttemptSnapshotException('amount_mismatch');
        }

        $this->snapshots->assertCurrent($attempt);

        $payment = Payment::query()->updateOrCreate(
            ['payment_attempt_id' => $attempt->id],
            [
                'student_profile_id' => $attempt->student_profile_id,
                'term_id' => $enrollment->term_id,
                'term_account_id' => $assessment->term_account_id,
                'method' => 'paymongo',
                'channel' => $attempt->channel,
                'amount' => $normalizedAmount,
                'currency' => 'PHP',
                'evidence_status' => 'verified',
                'paid_at' => $timestamp,
                'verified_at' => $timestamp,
                'verified_by' => $actor?->id,
                'provider_reference' => $providerReference,
            ],
        );

        $ledgerEntries = $this->paymentAllocationService->post(
            payment: $payment,
            enrollment: $enrollment,
            amount: $normalizedAmount,
            requested: $this->snapshots->allocationTargets($attempt),
            actor: $actor,
            timestamp: $timestamp,
            description: $description,
        );
        $ledgerEntry = $ledgerEntries->first();

        if (! $ledgerEntry instanceof LedgerEntry) {
            throw new RuntimeException('Verified payment did not produce a ledger posting.');
        }

        $attempt->forceFill(['status' => PaymentAttempt::StatusConfirmed, 'paid_at' => $timestamp])->save();
        $projection = $this->paymentRequirementProjection->forEnrollment($enrollment->refresh());
        $account->forceFill([
            'state' => $projection['state'] === 'Cleared' ? TermAccount::StateCleared : TermAccount::StateOpen,
        ])->save();
        $financeCleared = $projection['state'] === 'Cleared';

        if ($ledgerEntries->contains(fn (LedgerEntry $entry): bool => $entry->wasRecentlyCreated)) {
            $this->paymentPostedNotificationService->record($payment);
        }

        return [
            'status' => 'posted',
            'payment' => $payment,
            'ledger_entry' => $ledgerEntry,
            'finance_cleared' => $financeCleared,
        ];
    }
}
