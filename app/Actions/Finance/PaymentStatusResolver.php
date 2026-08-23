<?php

namespace App\Actions\Finance;

use App\Models\Assessment;
use App\Models\Payment;
use App\Models\PaymentAttempt;
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

    public const StatusPaymentPending = 'Payment Pending';

    public const StatusPaymentUnderReview = 'Payment Under Review';

    public const StatusPaymentRejected = 'Payment Rejected';

    private const AttemptPending = PaymentAttempt::StatusPending;

    private const AttemptUnderReview = PaymentAttempt::StatusReviewRequired;

    private const AttemptFailed = PaymentAttempt::StatusFailed;

    private const EvidenceUnderReview = 'under_review';

    public function __construct(private readonly DecimalMoney $money) {}

    /**
     * Resolve the salient payment status for the given finance source state.
     *
     * Priority while a balance remains: an in-progress review, then a pending
     * checkout, then a rejected-only attempt, before falling back to the
     * ledger-derived Unpaid/Installment/Partially Paid states.
     *
     * @param  Collection<int, PaymentAttempt>  $attempts
     * @param  Collection<int, Payment>  $payments
     * @param  Collection<int, PaymentScheduleRow>  $scheduleRows
     */
    public function resolve(
        ?Assessment $assessment,
        string $balance,
        string $postedPayments,
        Collection $attempts,
        Collection $payments,
        Collection $scheduleRows,
    ): string {
        if (! $assessment instanceof Assessment) {
            return self::StatusNoAssessment;
        }

        if (! $this->money->greaterThanZero($balance)) {
            return self::StatusFullPaid;
        }

        if ($this->hasUnderReview($attempts, $payments)) {
            return self::StatusPaymentUnderReview;
        }

        $latestAttemptStatus = $this->latestAttemptStatus($attempts);

        if ($latestAttemptStatus === self::AttemptPending) {
            return self::StatusPaymentPending;
        }

        if (! $this->money->greaterThanZero($postedPayments)) {
            return $latestAttemptStatus === self::AttemptFailed
                ? self::StatusPaymentRejected
                : self::StatusUnpaid;
        }

        if ($this->isInstallment($scheduleRows)) {
            return self::StatusInstallment;
        }

        return self::StatusPartiallyPaid;
    }

    /**
     * @param  Collection<int, PaymentAttempt>  $attempts
     * @param  Collection<int, Payment>  $payments
     */
    private function hasUnderReview(Collection $attempts, Collection $payments): bool
    {
        return $attempts->contains(fn (PaymentAttempt $attempt): bool => $attempt->status === self::AttemptUnderReview)
            || $payments->contains(fn (Payment $payment): bool => $payment->evidence_status === self::EvidenceUnderReview);
    }

    /**
     * The status of the most recent payment attempt, used to distinguish a
     * pending checkout from a rejected one while a balance remains.
     *
     * @param  Collection<int, PaymentAttempt>  $attempts
     */
    private function latestAttemptStatus(Collection $attempts): ?string
    {
        $latest = $attempts
            ->sortByDesc(fn (PaymentAttempt $attempt): int => (int) $attempt->id)
            ->first();

        return $latest instanceof PaymentAttempt ? $latest->status : null;
    }

    /**
     * @param  Collection<int, PaymentScheduleRow>  $scheduleRows
     */
    private function isInstallment(Collection $scheduleRows): bool
    {
        return $scheduleRows->contains(fn (PaymentScheduleRow $row): bool => $row->state === PaymentScheduleRow::StateDue);
    }
}
