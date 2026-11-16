<?php

namespace App\Actions\Finance;

use App\Models\Assessment;
use App\Models\Payment;
use App\Models\PaymentEvidenceVersion;
use App\Models\PaymentScheduleRow;
use App\Support\DecimalMoney;
use Illuminate\Support\Collection;

/**
 * Shared resolver for the PRD payment status vocabulary (PRD 8.9.3 and 9.1.2).
 *
 * Both the SOA finance output and the COR output derive their displayed payment
 * status through this resolver so the two official outputs stay consistent.
 */
class PaymentStatusResolver
{
    public const StatusNoAssessment = 'No Active Assessment';

    public const StatusFullPaid = 'Full Paid';

    public const StatusUnpaid = 'Unpaid';

    public const StatusPartiallyPaid = 'Partially Paid';

    public const StatusInstallment = 'Installment';

    public const StatusPaymentUnderReview = 'Payment Under Review';

    public const StatusPaymentRejected = 'Payment Rejected';

    private const EvidenceUnderReview = 'under_review';

    private const EvidenceRejected = 'rejected';

    public function __construct(private readonly DecimalMoney $money) {}

    /**
     * Resolve the salient payment status for the given finance source state.
     *
     * Priority while a balance remains: an in-progress review, then a rejected-only
     * submission when nothing has posted yet, before falling back to the
     * ledger-derived Unpaid/Installment/Partially Paid states.
     *
     * @param  Collection<int, Payment>  $payments
     * @param  Collection<int, PaymentScheduleRow>  $scheduleRows
     * @param  Collection<int, PaymentEvidenceVersion>|null  $evidenceVersions
     */
    public function resolve(
        ?Assessment $assessment,
        string $balance,
        string $postedPayments,
        Collection $payments,
        Collection $scheduleRows,
        ?Collection $evidenceVersions = null,
    ): string {
        if (! $assessment instanceof Assessment) {
            return self::StatusNoAssessment;
        }

        if (! $this->money->greaterThanZero($balance)) {
            return self::StatusFullPaid;
        }

        if ($this->hasUnderReview($payments, $evidenceVersions)) {
            return self::StatusPaymentUnderReview;
        }

        if (! $this->money->greaterThanZero($postedPayments)) {
            return $this->hasRejectedSubmission($payments, $evidenceVersions)
                ? self::StatusPaymentRejected
                : self::StatusUnpaid;
        }

        if ($this->isInstallment($scheduleRows)) {
            return self::StatusInstallment;
        }

        return self::StatusPartiallyPaid;
    }

    /**
     * @param  Collection<int, Payment>  $payments
     * @param  Collection<int, PaymentEvidenceVersion>|null  $evidenceVersions
     */
    private function hasUnderReview(Collection $payments, ?Collection $evidenceVersions): bool
    {
        if ($payments->contains(fn (Payment $payment): bool => $payment->evidence_status === self::EvidenceUnderReview)) {
            return true;
        }

        return $evidenceVersions instanceof Collection
            && $evidenceVersions->contains(
                fn (PaymentEvidenceVersion $version): bool => $version->state === PaymentEvidenceVersion::StateSubmitted,
            );
    }

    /**
     * @param  Collection<int, Payment>  $payments
     * @param  Collection<int, PaymentEvidenceVersion>|null  $evidenceVersions
     */
    private function hasRejectedSubmission(Collection $payments, ?Collection $evidenceVersions): bool
    {
        if ($evidenceVersions instanceof Collection) {
            $latestVersion = $evidenceVersions
                ->sortByDesc(fn (PaymentEvidenceVersion $version): int => (int) $version->version)
                ->first();

            if ($latestVersion instanceof PaymentEvidenceVersion
                && $latestVersion->state === PaymentEvidenceVersion::StateRejected) {
                return true;
            }
        }

        return $payments->contains(fn (Payment $payment): bool => $payment->evidence_status === self::EvidenceRejected);
    }

    /**
     * @param  Collection<int, PaymentScheduleRow>  $scheduleRows
     */
    private function isInstallment(Collection $scheduleRows): bool
    {
        return $scheduleRows->contains(fn (PaymentScheduleRow $row): bool => $row->state === PaymentScheduleRow::StateDue);
    }
}
