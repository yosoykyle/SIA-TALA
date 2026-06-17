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
use RuntimeException;

class PaymentConfirmationService
{
    public function __construct(
        private readonly DecimalMoney $money,
        private readonly EnrollmentFinanceClearanceService $financeClearanceService,
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
    ): array {
        if (! $actor->can('process-payments')) {
            throw new AuthorizationException('Only Accounting/Cashier can confirm payments.');
        }

        $normalizedAmount = $this->money->normalize($amount);

        if (! $this->money->greaterThanZero($normalizedAmount)) {
            throw new RuntimeException('Payment amount must be greater than zero.');
        }

        $timestamp = $confirmedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($enrollmentId, $normalizedAmount, $channel, $paymentReference, $actor, $timestamp): array {
            if ($paymentReference !== null && trim($paymentReference) !== '' && Payment::query()->where('payment_reference', $paymentReference)->exists()) {
                throw new RuntimeException('Payment reference already exists.');
            }

            $enrollment = Enrollment::query()
                ->with(['studentProfile.user'])
                ->lockForUpdate()
                ->findOrFail($enrollmentId);

            $studentProfile = StudentProfile::query()
                ->lockForUpdate()
                ->findOrFail($enrollment->student_profile_id);

            $payment = Payment::query()->create([
                'student_profile_id' => $studentProfile->id,
                'term_id' => $enrollment->term_id,
                'enrollment_id' => $enrollment->id,
                'payment_reference' => $paymentReference !== null && trim($paymentReference) !== '' ? trim($paymentReference) : null,
                'channel' => $channel,
                'amount' => $normalizedAmount,
                'status' => 'confirmed',
                'confirmed_at' => $timestamp,
                'confirmed_by' => $actor->id,
                'meta' => [
                    'source' => 'filament_manual_confirmation',
                ],
            ]);

            $currentBalance = $this->money->normalize((string) $studentProfile->current_balance);
            $paymentLedgerAmount = $this->money->subtract('0.00', $normalizedAmount);
            $newBalance = $this->money->add($currentBalance, $paymentLedgerAmount);

            $ledgerEntry = LedgerEntry::query()->create([
                'student_profile_id' => $studentProfile->id,
                'term_id' => $enrollment->term_id,
                'enrollment_id' => $enrollment->id,
                'entry_type' => 'payment',
                'reference_type' => 'payment',
                'reference_id' => $payment->id,
                'description' => 'Accounting-confirmed payment',
                'amount' => $paymentLedgerAmount,
                'running_balance' => $newBalance,
                'posted_at' => $timestamp,
                'posted_by' => $actor->id,
            ]);

            $payment->forceFill([
                'ledger_entry_id' => $ledgerEntry->id,
            ])->save();

            $studentProfile->forceFill([
                'current_balance' => $newBalance,
            ])->save();

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

            $this->recordPaymentAudit($enrollment, $payment, $ledgerEntry, $financeCleared, $actor, $timestamp);

            return [
                'payment_id' => $payment->id,
                'ledger_entry_id' => $ledgerEntry->id,
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
}
