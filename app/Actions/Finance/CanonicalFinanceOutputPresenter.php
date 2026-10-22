<?php

namespace App\Actions\Finance;

use App\Models\ApprovedCoverage;
use App\Models\Assessment;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;

class CanonicalFinanceOutputPresenter
{
    public function __construct(private readonly TermAccountProjection $projection) {}

    /** @return array<string, mixed> */
    public function statement(Assessment $assessment, User $actor): array
    {
        $assessment->loadMissing(['termAccount.credentialUser', 'enrollment.studentProfile.program', 'termAccount.term']);
        $isAccounting = $actor->hasRole(User::StaffRoleAccounting);
        abort_unless($assessment->termAccount !== null && ($isAccounting
            || (int) $assessment->termAccount->credential_user_id === (int) $actor->id), 403);
        $position = $this->projection->forAccount($assessment->termAccount);
        $requestedAssessment = $assessment;
        $assessment = $assessment->termAccount->assessments()->with('lines')
            ->find($position['assessment_id']) ?? $requestedAssessment;
        $assessment->loadMissing('lines');
        $account = $requestedAssessment->termAccount;
        $account->loadMissing(['payments.reversals', 'coverages']);
        $activity = $account->payments->map(fn (Payment $payment): array => [
            'date' => $payment->state === Payment::StateReversal ? ($payment->external_refund_at ?? $payment->paid_at) : $payment->paid_at,
            'description' => $payment->state === Payment::StateReversal ? 'Payment reversal' : 'Verified payment',
            'details' => $payment->channelLabel(),
            'amount' => $payment->amount,
            'reference' => $payment->provider_reference,
            'state' => $payment->state === Payment::StateReversal ? 'Reversal recorded' : ($payment->reversals->isNotEmpty() ? 'Has recorded reversal' : 'Posted'),
            'external_reference' => $this->maskedExternalReference($payment->external_check_reference),
        ])->concat($account->coverages->map(fn (ApprovedCoverage $coverage): array => [
            'date' => $coverage->effective_date ?? $coverage->approved_at,
            'description' => 'Approved coverage',
            'details' => $coverage->safe_source_description,
            'amount' => $coverage->amount,
            'reference' => $coverage->authority_reference,
            'state' => $coverage->state,
            'external_reference' => null,
        ]))->sortBy(fn (array $event): int => $event['date']?->getTimestamp() ?? PHP_INT_MAX)->values();

        return [
            'assessment' => $assessment,
            'account' => $account,
            'requested_assessment' => $requestedAssessment,
            'has_current_assessment' => $position['assessment_id'] !== null,
            'activity' => $activity,
            'generated_at' => CarbonImmutable::now(config('app.timezone')),
            'owner' => $assessment->enrollment?->studentProfile?->user?->getFilamentName() ?? $assessment->termAccount->credentialUser?->getFilamentName() ?? 'Learner account',
            'program' => $assessment->enrollment?->studentProfile?->program->code ?? 'Not assigned',
            'term' => $assessment->termAccount->term->label,
            'position' => $position,
            'authority_reference' => $assessment->authority_reference,
            'authority_date' => $assessment->authority_date?->toDateString(),
            'is_historical' => $requestedAssessment->state !== Assessment::StateActive,
            'disclaimer' => 'This authenticated statement reflects TALA account records and is not a tax receipt.',
        ];
    }

    /** @return array<string, mixed> */
    public function acknowledgement(Payment $payment, User $actor): array
    {
        $payment->loadMissing([
            'termAccount.credentialUser',
            'termAccount.term',
            'allocations.assessmentObligation',
            'reversals',
            'paymentEvidenceVersion',
        ]);
        abort_unless($payment->state === Payment::StatePosted && $payment->evidence_status === 'verified' && $payment->termAccount !== null
            && ($actor->hasRole(User::StaffRoleAccounting) || (int) $payment->termAccount->credential_user_id === (int) $actor->id), 403);

        $hasSuccessorEvidence = $payment->paymentEvidenceVersion !== null
            && $payment->paymentEvidenceVersion->newQuery()
                ->where('supersedes_version_id', $payment->paymentEvidenceVersion->id)
                ->exists();
        $reversed = $payment->reversals->where('state', Payment::StateReversal)->sortBy(fn (Payment $reversal) => $reversal->external_refund_at ?? $reversal->paid_at)->values();
        $paymentCents = (int) round(((float) $payment->amount) * 100);
        $reversedCents = $reversed->sum(fn (Payment $reversal): int => (int) round(((float) $reversal->amount) * 100));
        $postingStatus = match (true) {
            $reversed->isNotEmpty() && $reversedCents >= $paymentCents => 'Reversed',
            $reversed->isNotEmpty() => 'Partly refunded',
            $hasSuccessorEvidence => 'Superseded evidence',
            default => 'Posted',
        };

        return [
            'payment' => $payment,
            'generated_at' => CarbonImmutable::now(config('app.timezone')),
            'owner' => $payment->termAccount->credentialUser->getFilamentName(),
            'term' => $payment->termAccount->term->label,
            'posting_status' => $postingStatus,
            'reversal' => $reversed->last(),
            'corrections' => $reversed->map(fn (Payment $reversal): array => [
                'date' => $reversal->external_refund_at ?? $reversal->paid_at,
                'amount' => (string) $reversal->amount,
                'reason' => $reversal->reversal_reason,
                'reference' => $reversal->provider_reference,
                'authority' => $reversal->reversal_authority_reference,
            ])->all(),
            'reversed_total' => $reversed->isNotEmpty() ? number_format($reversedCents / 100, 2, '.', '') : null,
            'net_amount' => $reversed->isNotEmpty() ? number_format(max(0, $paymentCents - $reversedCents) / 100, 2, '.', '') : null,
            'allocations' => $payment->allocations->map(fn ($allocation): array => [
                'target' => $allocation->assessmentObligation->label,
                'amount' => (string) $allocation->amount,
            ])->all(),
            'external_reference' => $this->maskedExternalReference($payment->external_check_reference),
            'disclaimer' => 'This acknowledges a verified posting in TALA. It is not an official receipt or tax document.',
        ];
    }

    private function maskedExternalReference(?string $reference): ?string
    {
        if (blank($reference)) {
            return null;
        }

        return mb_strlen($reference) > 4 ? '••••'.mb_substr($reference, -4) : 'Recorded';
    }
}
