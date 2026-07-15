<?php

namespace App\Actions\Integrations\Payments;

use App\Actions\Finance\EnrollmentFinanceClearanceService;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use RuntimeException;

final class PayMongoPaymentPostingService
{
    public function __construct(
        private readonly DecimalMoney $money,
        private readonly EnrollmentFinanceClearanceService $financeClearanceService,
        private readonly PaymentPostedNotificationService $paymentPostedNotificationService,
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
        $enrollment = Enrollment::query()
            ->with('studentProfile')
            ->lockForUpdate()
            ->find($assessment->enrollment_id);
        $student = $enrollment?->studentProfile;

        if ($attempt->provider !== 'paymongo'
            || $assessment->state !== Assessment::StateActive
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

        $payment = Payment::query()->updateOrCreate(
            ['payment_attempt_id' => $attempt->id],
            [
                'student_profile_id' => $attempt->student_profile_id,
                'term_id' => $enrollment->term_id,
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

        $ledgerEntry = LedgerEntry::query()->firstOrCreate(
            [
                'source_type' => Payment::class,
                'source_id' => $payment->id,
                'direction' => LedgerEntry::DirectionPayment,
            ],
            [
                'student_profile_id' => $attempt->student_profile_id,
                'term_id' => $enrollment->term_id,
                'enrollment_id' => $enrollment->id,
                'category' => 'payment',
                'amount' => $normalizedAmount,
                'payment_id' => $payment->id,
                'payment_allocation_id' => null,
                'reverses_entry_id' => null,
                'adjusts_entry_id' => null,
                'description' => $description,
                'posted_by' => $actor?->id,
                'posted_at' => $timestamp,
                'state' => 'posted',
            ],
        );

        $attempt->forceFill(['status' => 'paid', 'paid_at' => $timestamp])->save();
        $clearance = $this->financeClearanceService->clearIfEligible(
            enrollment: $enrollment,
            studentProfile: $student->refresh(),
            currentBalance: $this->ledgerBalanceFor($student),
            actor: $actor,
            timestamp: $timestamp,
        );

        if ($ledgerEntry->wasRecentlyCreated) {
            $this->paymentPostedNotificationService->record($payment);
        }

        return [
            'status' => $wasPosted ? 'duplicate' : 'posted',
            'payment' => $payment,
            'ledger_entry' => $ledgerEntry,
            'finance_cleared' => $clearance['finance_cleared'],
        ];
    }

    private function ledgerBalanceFor(StudentProfile $student): string
    {
        $balance = '0.00';
        $entries = LedgerEntry::query()
            ->where('student_profile_id', $student->id)
            ->where('state', 'posted')
            ->get(['direction', 'amount']);

        foreach ($entries as $entry) {
            $amount = (string) $entry->amount;
            $balance = match ($entry->direction) {
                LedgerEntry::DirectionPayment,
                LedgerEntry::DirectionDiscount,
                LedgerEntry::DirectionScholarship,
                LedgerEntry::DirectionWaiver,
                LedgerEntry::DirectionReversal => $this->money->subtract($balance, $amount),
                default => $this->money->add($balance, $amount),
            };
        }

        return $balance;
    }
}
