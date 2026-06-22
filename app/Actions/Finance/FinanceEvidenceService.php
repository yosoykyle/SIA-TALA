<?php

namespace App\Actions\Finance;

use App\Models\Enrollment;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class FinanceEvidenceService
{
    public const EVIDENCE_DISCLAIMER = 'Internal student-account evidence only. This is not a BIR invoice or official receipt.';

    public function __construct(private readonly DecimalMoney $money) {}

    /**
     * @return array{
     *     enrollment: Enrollment,
     *     entries: Collection<int, LedgerEntry>,
     *     total_charges: string,
     *     total_credits: string,
     *     balance: string,
     *     generated_at: CarbonImmutable,
     *     disclaimer: string
     * }
     */
    public function statement(Enrollment $enrollment): array
    {
        $enrollment->loadMissing(['studentProfile.user', 'studentProfile.program', 'term']);

        $entries = $enrollment->ledgerEntries()
            ->with('poster')
            ->orderBy('posted_at')
            ->orderBy('id')
            ->get();

        $totalCharges = '0.00';
        $totalCredits = '0.00';

        foreach ($entries as $entry) {
            $amount = $this->money->normalize((string) $entry->amount);

            if ($this->money->greaterThanZero($amount)) {
                $totalCharges = $this->money->add($totalCharges, $amount);
            } else {
                $totalCredits = $this->money->add($totalCredits, $this->money->subtract('0.00', $amount));
            }
        }

        return [
            'enrollment' => $enrollment,
            'entries' => $entries,
            'total_charges' => $totalCharges,
            'total_credits' => $totalCredits,
            'balance' => $entries->isEmpty()
                ? '0.00'
                : $this->money->normalize((string) $entries->last()->running_balance),
            'generated_at' => CarbonImmutable::now(config('app.timezone')),
            'disclaimer' => self::EVIDENCE_DISCLAIMER,
        ];
    }

    /**
     * @return array{payment: Payment, generated_at: CarbonImmutable, disclaimer: string}
     */
    public function paymentAcknowledgement(Payment $payment): array
    {
        $payment->loadMissing(['studentProfile.user', 'studentProfile.program', 'term', 'enrollment', 'confirmer']);

        return [
            'payment' => $payment,
            'generated_at' => CarbonImmutable::now(config('app.timezone')),
            'disclaimer' => self::EVIDENCE_DISCLAIMER,
        ];
    }
}
