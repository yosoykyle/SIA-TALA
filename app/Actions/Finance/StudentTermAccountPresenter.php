<?php

namespace App\Actions\Finance;

use App\Models\ApprovedCoverage;
use App\Models\Payment;
use App\Models\TermAccount;
use App\Models\User;

class StudentTermAccountPresenter
{
    public function __construct(private readonly TermAccountProjection $projection) {}

    /** @return array<string, mixed> */
    public function forUser(User $actor): array
    {
        $account = TermAccount::query()
            ->with([
                'term', 'enrollment.studentProfile.program',
                'assessments' => fn ($query) => $query->where('state', 'ACTIVE')->latest('version'),
                'coverages.obligation', 'payments.allocations.assessmentObligation',
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

        return [
            'available' => $assessment !== null,
            'account' => $account,
            'assessment' => $assessment,
            'latest_payment' => $payments->first(),
            'state' => [
                'notice' => 'Balances come from the active Assessment, dated obligations, valid coverage, and verified postings.',
                'term' => $account->term->label,
                'account_reference' => 'TERM-ACCOUNT-'.$account->id,
                'assessment_version' => $assessment?->version,
                'assessment_basis' => $assessment?->assessment_basis,
                'current_due' => 'PHP '.number_format((float) $position['current_due'], 2),
                'remaining_balance' => 'PHP '.number_format((float) $position['remaining_balance'], 2),
                'status' => $position['state'],
                'as_of' => $position['as_of'],
                'manual_payment' => 'Submit private payment evidence from Enrollment. Accounting must independently verify it before any balance changes.',
                'paymongo' => 'Online PayMongo checkout is not active. Manual payment evidence remains fully supported.',
                'latest_evidence_state' => $latestEvidence?->state,
                'obligations' => collect($position['obligations'])->map(fn (array $row): array => [
                    'label' => $row['label'], 'purpose' => $row['purpose'], 'due_at' => $row['due_at'],
                    'amount' => 'PHP '.number_format((float) $row['amount'], 2),
                    'balance' => 'PHP '.number_format((float) $row['balance'], 2),
                    'state' => $row['balance'] === '0.00' ? 'Satisfied' : ($row['is_due'] ? 'Due' : 'Later'),
                ])->all(),
                'coverages' => $account->coverages->map(fn (ApprovedCoverage $coverage): array => [
                    'category' => $coverage->category, 'source' => $coverage->safe_source_description,
                    'amount' => 'PHP '.number_format((float) $coverage->amount, 2), 'state' => $coverage->state,
                    'effective_date' => $coverage->effective_date?->toDateString(),
                ])->all(),
                'payments' => $payments->map(fn (Payment $payment): array => [
                    'reference' => $payment->provider_reference,
                    'channel' => $payment->channelLabel(),
                    'amount' => 'PHP '.number_format((float) $payment->amount, 2),
                    'paid_at' => $payment->paid_at?->toIso8601String(), 'state' => 'Verified',
                ])->values()->all(),
            ],
        ];
    }
}
