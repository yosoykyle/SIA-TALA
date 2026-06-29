<?php

namespace App\Actions\Finance;

use App\Actions\Enrollment\AdmissionCapacityReservationService;
use App\Actions\Enrollment\AdmissionFinanceReadinessGateService;
use App\Actions\Enrollment\StudentEnrollmentService;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;

class EnrollmentFinanceClearanceService
{
    public function __construct(
        private readonly DecimalMoney $money,
        private readonly StudentEnrollmentService $studentEnrollmentService,
        private readonly AdmissionCapacityReservationService $capacityReservations,
        private readonly AdmissionFinanceReadinessGateService $admissionReadinessGate,
    ) {}

    /**
     * @return array{minimum_required_payment:string,total_confirmed_payments:string,finance_cleared:bool,enrollment_status:string}
     */
    public function clearIfEligible(
        Enrollment $enrollment,
        StudentProfile $studentProfile,
        string $currentBalance,
        ?User $actor,
        CarbonImmutable $timestamp,
    ): array {
        $netAssessment = $this->netAssessment($enrollment);
        $minimumRequiredPayment = $this->minimumRequiredPayment($enrollment, $studentProfile, $netAssessment);
        $totalConfirmedPayments = $this->totalConfirmedPayments($enrollment);
        $financeCleared = $this->shouldClearFinance($enrollment, $currentBalance, $minimumRequiredPayment, $totalConfirmedPayments, $netAssessment);

        if ($financeCleared) {
            $this->admissionReadinessGate->assertReadyForFinanceClearance($enrollment, $studentProfile, $timestamp);

            $payment = Payment::query()
                ->whereHas('ledgerEntries', fn ($query) => $query
                    ->where('enrollment_id', $enrollment->id)
                    ->where('direction', LedgerEntry::DirectionPayment)
                    ->where('state', 'posted'))
                ->where('evidence_status', 'verified')
                ->latest('verified_at')
                ->latest('id')
                ->first();
            $ledgerEntry = $payment instanceof Payment ? $payment->ledgerEntry : null;

            $this->capacityReservations->secureForFinanceClearedEnrollment(
                enrollment: $enrollment,
                studentProfile: $studentProfile,
                payment: $payment,
                ledgerEntry: $ledgerEntry,
                securedAt: $timestamp,
            );

            if (! in_array($enrollment->status, ['pre_enrolled', 'officially_enrolled'], true)) {
                $enrollment->forceFill([
                    'status' => 'pre_enrolled',
                    'pre_enrolled_at' => $enrollment->pre_enrolled_at ?? $timestamp,
                ])->save();
            }

            $enrollment = $this->studentEnrollmentService->completeFinanceClearedHandover($enrollment, $actor, $timestamp);
        }

        return [
            'minimum_required_payment' => $minimumRequiredPayment,
            'total_confirmed_payments' => $totalConfirmedPayments,
            'finance_cleared' => $financeCleared,
            'enrollment_status' => (string) $enrollment->fresh()->status,
        ];
    }

    private function minimumRequiredPayment(Enrollment $enrollment, StudentProfile $studentProfile, string $netAssessment): string
    {
        $requiredDownpayment = Assessment::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('state', Assessment::StateActive)
            ->latest('version')
            ->latest('id')
            ->value('required_downpayment');

        if ($requiredDownpayment !== null && $this->money->greaterThanZero((string) $requiredDownpayment)) {
            return $this->money->normalize((string) $requiredDownpayment);
        }

        return $this->money->multiplyPercent($netAssessment, '20.00');
    }

    private function netAssessment(Enrollment $enrollment): string
    {
        $entries = LedgerEntry::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('state', 'posted')
            ->whereIn('direction', [
                LedgerEntry::DirectionCharge,
                LedgerEntry::DirectionPenalty,
                LedgerEntry::DirectionDiscount,
                LedgerEntry::DirectionScholarship,
                LedgerEntry::DirectionWaiver,
            ])
            ->get(['direction', 'amount']);

        $balance = '0.00';

        foreach ($entries as $entry) {
            $amount = (string) $entry->amount;
            $balance = match ($entry->direction) {
                LedgerEntry::DirectionDiscount,
                LedgerEntry::DirectionScholarship,
                LedgerEntry::DirectionWaiver => $this->money->subtract($balance, $amount),
                default => $this->money->add($balance, $amount),
            };
        }

        return $balance;
    }

    private function totalConfirmedPayments(Enrollment $enrollment): string
    {
        $sum = LedgerEntry::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('direction', LedgerEntry::DirectionPayment)
            ->where('state', 'posted')
            ->sum('amount');

        return $this->money->normalize((string) $sum);
    }

    private function shouldClearFinance(Enrollment $enrollment, string $currentBalance, string $minimumRequiredPayment, string $totalConfirmedPayments, string $netAssessment): bool
    {
        if (! $this->money->greaterThanZero($netAssessment)) {
            return false;
        }

        if ($this->money->isZeroOrNegative($currentBalance)) {
            return true;
        }

        return $this->money->toCents($totalConfirmedPayments) >= $this->money->toCents($minimumRequiredPayment)
            && $this->money->greaterThanZero($minimumRequiredPayment);
    }
}
