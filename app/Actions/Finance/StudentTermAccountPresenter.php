<?php

namespace App\Actions\Finance;

use App\Models\ApprovedCoverage;
use App\Models\Payment;
use App\Models\TermAccount;
use App\Models\User;

class StudentTermAccountPresenter
{
    public function __construct(
        private readonly TermAccountProjection $projection,
        private readonly SchoolPaymentChannels $paymentChannels,
    ) {}

    /** @return array<string, mixed> */
    public function forUser(User $actor): array
    {
        $account = TermAccount::query()
            ->with([
                'term',
                'enrollment.studentProfile.program',
                'assessments' => fn ($query) => $query->where('state', 'ACTIVE')->latest('version'),
                'coverages.obligation',
                'payments.allocations.assessmentObligation',
                'paymentEvidenceVersions' => fn ($query) => $query->latest('version'),
            ])
            ->where('credential_user_id', $actor->id)
            ->latest('id')
            ->first();

        if (! $account instanceof TermAccount) {
            return ['available' => false, 'state' => ['notice' => 'No Term Account is available for this credential yet.']];
        }

        $position = $this->projection->forAccount($account);
        $assessment = $account->assessments->first();
        $payments = $account->payments->where('state', Payment::StatePosted)->sortByDesc('paid_at');
        $latestEvidence = $account->paymentEvidenceVersions->first();

        $howToPayDisplay = $this->paymentChannels->forDisplay();
        $hasEnabledDigitalChannels = (bool) ($howToPayDisplay['hasEnabledDigitalChannels'] ?? false);
        $manualPaymentInstruction = $hasEnabledDigitalChannels
            ? 'Pay through any enabled school channel and submit your receipt, or pay directly at the Campus Cashier: Accounting verifies or records payments directly.'
            : 'Electronic receipt uploads are currently turned off. Pay directly at the Campus Cashier where Accounting posts verified payments.';

        return [
            'available' => $assessment !== null,
            'account' => $account,
            'assessment' => $assessment,
            'latest_payment' => $payments->first(),
            'payment_channels' => $this->paymentChannels->enabledChannels(),
            'how_to_pay_display' => $howToPayDisplay,
            'where_to_pay_display' => $howToPayDisplay,
            'has_enabled_digital_channels' => $hasEnabledDigitalChannels,
            'state' => [
                'notice' => 'Balances come from the active Assessment, dated obligations, valid coverage, and verified postings.',
                'term' => $account->term->label,
                'account_reference' => 'TERM-ACCOUNT-'.$account->id,
                'assessment_version' => $assessment?->version,
                'assessment_basis' => $assessment?->assessment_basis,
                'current_due' => $position['state'] === 'Unavailable' ? 'Unavailable' : AccountingDisplay::money($position['current_due']),
                'remaining_balance' => $position['state'] === 'Unavailable' ? 'Unavailable' : AccountingDisplay::money($position['remaining_balance']),
                'status' => $position['state'],
                'as_of' => $position['as_of'],
                'manual_payment' => $manualPaymentInstruction,
                'latest_evidence_state' => $latestEvidence?->state,
                'obligations' => collect($position['obligations'])->map(fn (array $row): array => [
                    'label' => $row['label'],
                    'purpose' => $row['purpose'],
                    'due_at' => $row['due_at'],
                    'amount' => AccountingDisplay::money($row['amount']),
                    'balance' => AccountingDisplay::money($row['balance']),
                    'state' => $row['balance'] === '0.00' ? 'Satisfied' : ($row['is_due'] ? 'Due' : 'Later'),
                ])->all(),
                'coverages' => $account->coverages->map(fn (ApprovedCoverage $coverage): array => [
                    'category' => $coverage->category,
                    'source' => $coverage->safe_source_description,
                    'amount' => AccountingDisplay::money($coverage->amount),
                    'state' => $coverage->state,
                    'effective_date' => $coverage->effective_date?->toDateString(),
                ])->all(),
                'payments' => $payments->map(fn (Payment $payment): array => [
                    'reference' => $payment->provider_reference,
                    'channel' => $payment->channelDetailLabel(),
                    'amount' => AccountingDisplay::money($payment->amount),
                    'paid_at' => $payment->paid_at?->toIso8601String(),
                    'state' => 'Verified',
                ])->values()->all(),
            ],
        ];
    }
}
