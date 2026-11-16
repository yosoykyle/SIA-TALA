<?php

namespace App\Actions\Finance;

use App\Models\Payment;
use App\Models\PaymentEvidenceVersion;
use Illuminate\Support\Collection;

class StudentPaymentEvidencePresenter
{
    public function __construct(private readonly ?SchoolPaymentChannels $paymentChannels = null) {}

    /**
     * @param  Collection<int, Payment>  $payments
     * @param  Collection<int, Payment>  $postedPayments
     * @param  Collection<int, PaymentEvidenceVersion>|null  $evidenceVersions
     * @return array{headline:string,explanation:string,required_action:string,responsible_office:string,ledger_state:string,or_mapping_state:string}
     */
    public function present(
        Collection $payments,
        Collection $postedPayments,
        bool $hasCurrentDue = false,
        ?Collection $evidenceVersions = null,
        ?bool $hasEnabledDigitalChannels = null,
    ): array {
        $digitalUploadsEnabled = $hasEnabledDigitalChannels
            ?? ($this->paymentChannels ?? app(SchoolPaymentChannels::class))->hasEnabledDigitalChannels();

        $defaultRequiredAction = $digitalUploadsEnabled
            ? 'Submit a receipt after paying via an approved channel, or pay directly at the Campus Cashier: Accounting records onsite payments directly.'
            : 'Electronic receipt uploads are currently turned off. Pay directly at the Campus Cashier: Accounting records onsite payments directly.';

        $orMappingState = $this->orMappingState($postedPayments);

        if ($postedPayments->isNotEmpty()) {
            if ($hasCurrentDue) {
                $requiredAction = $defaultRequiredAction;

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
                'explanation' => 'A verified payment is recorded in your student ledger. This confirms posting, not issuance of an Official Receipt (OR).',
                'required_action' => $orMappingState === 'Pending OR Mapping'
                    ? 'No new payment is needed. Accounting is completing OR mapping.'
                    : 'No action required. Keep your payment acknowledgement for reference.',
                'responsible_office' => 'Accounting',
                'ledger_state' => 'Posted',
                'or_mapping_state' => $orMappingState,
            ];
        }

        $hasSubmittedEvidence = $payments->contains(fn (Payment $payment): bool => $payment->evidence_status === 'under_review')
            || ($evidenceVersions instanceof Collection
                && $evidenceVersions->contains(fn (PaymentEvidenceVersion $version): bool => $version->state === PaymentEvidenceVersion::StateSubmitted));

        if ($hasSubmittedEvidence) {
            return $this->notPosted(
                headline: 'Payment Under Review',
                explanation: 'Accounting is reviewing the submitted receipt before it can be posted to the ledger.',
                requiredAction: 'Do not submit another payment. Contact Accounting only if they request supporting information.',
            );
        }

        $latestEvidence = $evidenceVersions instanceof Collection
            ? $evidenceVersions->sortByDesc(fn (PaymentEvidenceVersion $version): int => (int) $version->version)->first()
            : null;
        $isRejected = ($latestEvidence instanceof PaymentEvidenceVersion && $latestEvidence->state === PaymentEvidenceVersion::StateRejected)
            || $payments->contains(fn (Payment $payment): bool => $payment->evidence_status === 'rejected');

        if ($isRejected) {
            return $this->notPosted(
                headline: 'Payment Rejected',
                explanation: 'The submitted receipt could not be verified and was not posted to the ledger.',
                requiredAction: $defaultRequiredAction,
            );
        }

        return $this->notPosted(
            headline: 'No Payment Submitted',
            explanation: 'No submitted receipt or verified ledger posting is recorded for this assessment.',
            requiredAction: $defaultRequiredAction,
        );
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
