<?php

namespace App\Actions\Finance;

use App\Actions\Enrollment\StudentEnrollmentService;
use App\Models\Enrollment;
use App\Models\FeeTemplate;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class EnrollmentFinanceClearanceService
{
    public function __construct(
        private readonly DecimalMoney $money,
        private readonly StudentEnrollmentService $studentEnrollmentService,
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
        $minimumRequiredPayment = $this->minimumRequiredPayment($enrollment, $studentProfile);
        $totalConfirmedPayments = $this->totalConfirmedPayments($enrollment);
        $financeCleared = $this->shouldClearFinance($enrollment, $currentBalance, $minimumRequiredPayment, $totalConfirmedPayments);

        if ($financeCleared) {
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

    private function minimumRequiredPayment(Enrollment $enrollment, StudentProfile $studentProfile): string
    {
        $netAssessment = $this->netAssessment($enrollment);
        $feeTemplate = $this->feeTemplateFromAssessment($enrollment)
            ?? $this->resolveFeeTemplate($enrollment, $studentProfile);
        $percentage = $feeTemplate instanceof FeeTemplate
            ? (string) $feeTemplate->minimum_downpayment_percentage
            : '20.00';

        return $this->money->multiplyPercent($netAssessment, $percentage);
    }

    private function netAssessment(Enrollment $enrollment): string
    {
        $sum = LedgerEntry::query()
            ->where('enrollment_id', $enrollment->id)
            ->whereIn('entry_type', ['assessment', 'discount'])
            ->sum('amount');

        return $this->money->normalize((string) $sum);
    }

    private function totalConfirmedPayments(Enrollment $enrollment): string
    {
        $sum = Payment::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('status', 'confirmed')
            ->sum('amount');

        return $this->money->normalize((string) $sum);
    }

    private function shouldClearFinance(Enrollment $enrollment, string $currentBalance, string $minimumRequiredPayment, string $totalConfirmedPayments): bool
    {
        if ($this->hasActivePromissory($enrollment)) {
            return false;
        }

        if ($this->money->isZeroOrNegative($currentBalance)) {
            return true;
        }

        return $this->money->toCents($totalConfirmedPayments) >= $this->money->toCents($minimumRequiredPayment)
            && $this->money->greaterThanZero($minimumRequiredPayment);
    }

    private function hasActivePromissory(Enrollment $enrollment): bool
    {
        return DB::table('promissory_notes')
            ->where('enrollment_id', $enrollment->id)
            ->whereIn('status', ['approved', 'active'])
            ->whereDate('due_date', '>=', now(config('app.timezone'))->toDateString())
            ->exists();
    }

    private function feeTemplateFromAssessment(Enrollment $enrollment): ?FeeTemplate
    {
        $feeTemplateId = LedgerEntry::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('reference_type', 'fee_template')
            ->value('reference_id');

        return $feeTemplateId !== null ? FeeTemplate::query()->find((int) $feeTemplateId) : null;
    }

    private function resolveFeeTemplate(Enrollment $enrollment, StudentProfile $studentProfile): ?FeeTemplate
    {
        return FeeTemplate::query()
            ->where('is_active', true)
            ->where('education_level', $studentProfile->education_level)
            ->where(function ($query) use ($studentProfile): void {
                $query->whereNull('program_id');

                if ($studentProfile->program_id !== null) {
                    $query->orWhere('program_id', $studentProfile->program_id);
                }
            })
            ->where(function ($query) use ($enrollment): void {
                $query->whereNull('year_level');

                if ($enrollment->year_level !== null) {
                    $query->orWhere('year_level', $enrollment->year_level);
                }
            })
            ->orderByRaw('CASE WHEN program_id IS NULL THEN 0 ELSE 1 END DESC')
            ->orderByRaw('CASE WHEN year_level IS NULL THEN 0 ELSE 1 END DESC')
            ->orderByDesc('id')
            ->first();
    }
}
