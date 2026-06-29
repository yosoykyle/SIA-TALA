<?php

namespace App\Actions\Finance;

use App\Models\Enrollment;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PaymentConfirmationService
{
    public function __construct(
        private readonly DecimalMoney $money,
        private readonly EnrollmentFinanceClearanceService $financeClearanceService,
        private readonly PromissoryNoteLifecycleService $promissoryNoteLifecycleService,
    ) {}

    /**
     * @return array{payment_id:int, ledger_entry_id:int, current_balance:string, minimum_required_payment:string, total_confirmed_payments:string, finance_cleared:bool}
     *
     * @throws AuthorizationException
     */
    public function confirmManualPayment(
        int $enrollmentId,
        string $amount,
        string $channel,
        ?string $paymentReference,
        User $actor,
        ?CarbonImmutable $confirmedAt = null,
        ?array $allocations = null,
        ?string $orNumber = null,
        ?string $orAttachmentPath = null,
    ): array {
        if (! $actor->can('process-payments')) {
            throw new AuthorizationException('Only Accounting/Cashier can confirm payments.');
        }

        $normalizedAmount = $this->money->normalize($amount);

        if (! $this->money->greaterThanZero($normalizedAmount)) {
            throw new RuntimeException('Payment amount must be greater than zero.');
        }

        $normalizedChannel = strtolower(trim($channel));

        if (! array_key_exists($normalizedChannel, Payment::manualConfirmationChannelOptions())) {
            throw new RuntimeException('Unsupported manual payment channel.');
        }

        $normalizedReference = trim((string) $paymentReference);

        if ($normalizedReference === '') {
            throw new RuntimeException('Payment reference is required.');
        }

        if (Str::length($normalizedReference) > 255) {
            throw new RuntimeException('Payment reference must not exceed 255 characters.');
        }

        $now = CarbonImmutable::now(config('app.timezone'));
        $timestamp = $confirmedAt ?? $now;

        if ($timestamp->greaterThan($now)) {
            throw new RuntimeException('Payment confirmation date cannot be in the future.');
        }

        if ($allocations !== null) {
            $totalAllocationAmount = '0.00';
            foreach ($allocations as $allocation) {
                if (empty($allocation['description'])) {
                    throw new RuntimeException('Allocation description is required.');
                }
                $allocAmount = $this->money->normalize($allocation['amount']);
                if (! $this->money->greaterThanZero($allocAmount)) {
                    throw new RuntimeException('Allocation amount must be greater than zero.');
                }
                $totalAllocationAmount = $this->money->add($totalAllocationAmount, $allocAmount);
            }
            if ($this->money->normalize($amount) !== $totalAllocationAmount) {
                throw new RuntimeException('The sum of allocations must equal the total payment amount.');
            }
        }

        return DB::transaction(function () use ($enrollmentId, $normalizedAmount, $normalizedChannel, $normalizedReference, $actor, $timestamp, $allocations, $orNumber): array {
            $enrollment = Enrollment::query()
                ->with(['studentProfile.user'])
                ->lockForUpdate()
                ->findOrFail($enrollmentId);

            $studentProfile = StudentProfile::query()
                ->lockForUpdate()
                ->findOrFail($enrollment->student_profile_id);

            if (Payment::query()->where('provider_reference', $normalizedReference)->exists()) {
                throw new RuntimeException('Payment reference already exists.');
            }

            if ($orNumber !== null) {
                $trimmedOrNumber = trim($orNumber);
                if ($trimmedOrNumber !== '') {
                    if (Payment::query()->where('or_number', $trimmedOrNumber)->exists()) {
                        throw new RuntimeException('Official Receipt number already exists.');
                    }
                } else {
                    $orNumber = null;
                }
            }

            if (! LedgerEntry::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('direction', LedgerEntry::DirectionCharge)
                ->where('state', 'posted')
                ->exists()) {
                throw new RuntimeException('Enrollment must be assessed before payment confirmation.');
            }

            $payment = Payment::query()->create([
                'student_profile_id' => $studentProfile->id,
                'term_id' => $enrollment->term_id,
                'method' => $normalizedChannel,
                'channel' => $normalizedChannel,
                'amount' => $normalizedAmount,
                'currency' => 'PHP',
                'evidence_status' => 'verified',
                'paid_at' => $timestamp,
                'verified_at' => $timestamp,
                'verified_by' => $actor->id,
                'or_number' => $orNumber !== null ? trim($orNumber) : null,
                'provider_reference' => $normalizedReference,
            ]);

            $ledgerEntries = [];

            if ($allocations !== null && count($allocations) > 0) {
                throw new RuntimeException('Detailed payment allocations require TAL-69 allocation targets before posting.');
            }

            $ledgerEntry = LedgerEntry::query()->firstOrCreate(
                [
                    'source_type' => Payment::class,
                    'source_id' => $payment->id,
                    'direction' => LedgerEntry::DirectionPayment,
                ],
                [
                    'student_profile_id' => $studentProfile->id,
                    'term_id' => $enrollment->term_id,
                    'enrollment_id' => $enrollment->id,
                    'category' => 'payment',
                    'amount' => $normalizedAmount,
                    'payment_id' => $payment->id,
                    'payment_allocation_id' => null,
                    'reverses_entry_id' => null,
                    'adjusts_entry_id' => null,
                    'description' => 'Accounting-confirmed payment',
                    'posted_at' => $timestamp,
                    'posted_by' => $actor->id,
                    'state' => 'posted',
                ],
            );
            $ledgerEntries[] = $ledgerEntry;

            $newBalance = $this->ledgerBalanceFor($studentProfile);

            $this->promissoryNoteLifecycleService->settleEligibleForEnrollment(
                enrollment: $enrollment,
                actor: $actor,
                settledAt: $timestamp,
            );

            $clearance = $this->financeClearanceService->clearIfEligible(
                enrollment: $enrollment,
                studentProfile: $studentProfile->refresh(),
                currentBalance: $newBalance,
                actor: $actor,
                timestamp: $timestamp,
            );
            $minimumRequiredPayment = $clearance['minimum_required_payment'];
            $totalConfirmedPayments = $clearance['total_confirmed_payments'];
            $financeCleared = $clearance['finance_cleared'];
            $enrollment = $enrollment->fresh();

            $this->recordPaymentAudit($enrollment, $payment, $ledgerEntries[0], $financeCleared, $actor, $timestamp);

            return [
                'payment_id' => $payment->id,
                'ledger_entry_id' => $ledgerEntries[0]->id,
                'current_balance' => $newBalance,
                'minimum_required_payment' => $minimumRequiredPayment,
                'total_confirmed_payments' => $totalConfirmedPayments,
                'finance_cleared' => $financeCleared,
            ];
        });
    }

    private function recordPaymentAudit(
        Enrollment $enrollment,
        Payment $payment,
        LedgerEntry $ledgerEntry,
        bool $financeCleared,
        User $actor,
        CarbonImmutable $recordedAt,
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'payment_confirmation',
            'description' => 'Accounting-confirmed payment posted.',
            'subject_type' => Enrollment::class,
            'subject_id' => $enrollment->id,
            'event' => $financeCleared ? 'finance_cleared' : 'payment_confirmed',
            'causer_type' => User::class,
            'causer_id' => $actor->id,
            'properties' => json_encode([
                'payment_id' => $payment->id,
                'ledger_entry_id' => $ledgerEntry->id,
                'amount' => $payment->amount,
                'status_after' => $enrollment->fresh()->status,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $recordedAt->toDateTimeString(),
            'updated_at' => $recordedAt->toDateTimeString(),
        ]);
    }

    private function ledgerBalanceFor(StudentProfile $studentProfile): string
    {
        $entries = LedgerEntry::query()
            ->where('student_profile_id', $studentProfile->id)
            ->where('state', 'posted')
            ->get(['direction', 'amount']);

        $balance = '0.00';

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
