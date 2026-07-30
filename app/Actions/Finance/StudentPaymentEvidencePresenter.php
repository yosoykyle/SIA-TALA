<?php

namespace App\Actions\Finance;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use Illuminate\Support\Collection;

class StudentPaymentEvidencePresenter
{
    /**
     * @param  Collection<int, PaymentAttempt>  $attempts
     * @param  Collection<int, Payment>  $payments
     * @param  Collection<int, Payment>  $postedPayments
     * @return array{headline:string,explanation:string,required_action:string,responsible_office:string,ledger_state:string,or_mapping_state:string}
     */
    public function present(
        Collection $attempts,
        Collection $payments,
        Collection $postedPayments,
        bool $hasCurrentDue = false,
    ): array {
        $orMappingState = $this->orMappingState($postedPayments);

        if ($postedPayments->isNotEmpty()) {
            if ($hasCurrentDue) {
                $requiredAction = 'Use Pay Current Due for the remaining amount.';

                if ($orMappingState === 'Pending OR Mapping') {
                    $requiredAction .= ' Accounting is also completing the pending OR mapping.';
                }

                return [
                    'headline' => 'Payment Partially Posted',
                    'explanation' => 'A verified payment is recorded in your student ledger, but the active assessment still has a remaining amount due.',
                    'required_action' => $requiredAction,
                    'responsible_office' => 'Accounting',
                    'ledger_state' => 'Partially posted',
                    'or_mapping_state' => $orMappingState,
                ];
            }

            return [
                'headline' => 'Payment Posted',
                'explanation' => 'A verified payment is recorded in your student ledger. This confirms posting, not issuance of an official receipt.',
                'required_action' => $orMappingState === 'Pending OR Mapping'
                    ? 'No new payment is needed. Accounting is completing OR mapping.'
                    : 'No action required. Keep your payment acknowledgement for reference.',
                'responsible_office' => 'Accounting',
                'ledger_state' => 'Posted',
                'or_mapping_state' => $orMappingState,
            ];
        }

        if ($payments->contains(fn (Payment $payment): bool => $payment->evidence_status === 'under_review')
            || $attempts->contains(fn (PaymentAttempt $attempt): bool => $attempt->status === 'under_review')) {
            return $this->notPosted(
                headline: 'Payment Under Review',
                explanation: 'Accounting is reviewing the submitted payment evidence before it can be posted to the ledger.',
                requiredAction: 'Do not submit another payment. Contact Accounting only if they request supporting information.',
            );
        }

        $latestAttempt = $attempts
            ->sortByDesc(fn (PaymentAttempt $attempt): int => (int) $attempt->id)
            ->first();
        $status = $latestAttempt instanceof PaymentAttempt ? $latestAttempt->status : null;

        return match ($status) {
            'pending' => $this->notPosted(
                headline: 'Payment Pending',
                explanation: 'The checkout was created, but no verified ledger posting has been recorded yet.',
                requiredAction: 'Wait for payment confirmation. Do not repeat the checkout while this attempt is pending.',
            ),
            'failed', 'rejected' => $this->notPosted(
                headline: 'Payment Rejected',
                explanation: 'The payment attempt did not produce acceptable evidence and was not posted to the ledger.',
                requiredAction: 'Start a new checkout or contact Accounting if you believe the payment succeeded.',
            ),
            'expired', 'cancelled' => $this->notPosted(
                headline: 'Checkout Closed',
                explanation: 'The checkout ended without a verified ledger posting.',
                requiredAction: 'Start a new checkout when you are ready to pay.',
            ),
            default => $this->notPosted(
                headline: 'No Payment Submitted',
                explanation: 'No payment attempt or verified ledger posting is recorded for this assessment.',
                requiredAction: 'Use Pay Current Due when you are ready to submit a payment.',
            ),
        };
    }

    /** @return array{headline:string,explanation:string,required_action:string,responsible_office:string,ledger_state:string,or_mapping_state:string} */
    private function notPosted(string $headline, string $explanation, string $requiredAction): array
    {
        return [
            'headline' => $headline,
            'explanation' => $explanation,
            'required_action' => $requiredAction,
            'responsible_office' => 'Accounting',
            'ledger_state' => 'Not posted',
            'or_mapping_state' => 'Not applicable',
        ];
    }

    /** @param Collection<int, Payment> $postedPayments */
    private function orMappingState(Collection $postedPayments): string
    {
        if ($postedPayments->isEmpty()) {
            return 'Not applicable';
        }

        if ($postedPayments->contains(fn (Payment $payment): bool => blank($payment->or_number))) {
            return 'Pending OR Mapping';
        }

        if ($postedPayments->count() === 1) {
            return 'Mapped OR '.$postedPayments->first()->or_number;
        }

        return 'Mapped ('.$postedPayments->count().' OR records)';
    }
}
