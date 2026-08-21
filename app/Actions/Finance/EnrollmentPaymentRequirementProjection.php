<?php

namespace App\Actions\Finance;

use App\Models\Assessment;
use App\Models\Enrollment;

class EnrollmentPaymentRequirementProjection
{
    /**
     * @return array{state:string,basis:?string,total:?string,satisfied:?string,balance:?string,assessment_id:?int,payment_applied:?string,coverage_applied:?string,satisfaction_basis:string,later_obligation:bool,as_of:string}
     */
    public function forEnrollment(Enrollment $enrollment): array
    {
        $assessment = Assessment::query()
            ->with(['obligations.paymentAllocations.payment', 'obligations.coverages'])
            ->where('enrollment_id', $enrollment->id)
            ->where('state', Assessment::StateActive)
            ->where('source_proposal_version_id', $enrollment->current_proposal_version_id)
            ->latest('version')
            ->first();

        if (! $assessment instanceof Assessment) {
            return $this->unavailable();
        }

        $required = $assessment->obligations->where('required_for_enrollment', true);
        if ($required->isEmpty()) {
            return [...$this->unavailable(), 'basis' => $assessment->assessment_basis, 'assessment_id' => $assessment->id];
        }

        $total = (float) $required->sum(fn ($obligation): float => (float) $obligation->amount);
        $satisfied = 0.0;
        $paymentApplied = 0.0;
        $coverageApplied = 0.0;
        foreach ($required as $obligation) {
            $payments = (float) $obligation->paymentAllocations
                ->filter(fn ($allocation): bool => $allocation->payment?->evidence_status === 'verified')
                ->sum(fn ($allocation): float => (float) $allocation->amount);
            $coverage = (float) $obligation->coverages
                ->whereNull('reversed_at')
                ->sum(fn ($item): float => (float) $item->amount);
            $applied = min((float) $obligation->amount, $payments + $coverage);
            $paymentApplied += min($applied, $payments);
            $coverageApplied += min(max(0, $applied - $payments), $coverage);
            $satisfied += $applied;
        }

        $balance = max(0, $total - $satisfied);
        $state = $balance < 0.005 ? 'Cleared' : ($satisfied > 0 ? 'PartiallySatisfied' : 'PaymentRequired');
        if ($total < 0.005) {
            $state = 'Cleared';
        }
        $satisfactionBasis = match (true) {
            $state !== 'Cleared' => 'None',
            $total < 0.005 => 'NoPaymentRequired',
            $paymentApplied > 0 && $coverageApplied > 0 => 'Mixed',
            $paymentApplied > 0 => 'VerifiedPayment',
            $coverageApplied > 0 => 'ApprovedCoverage',
            default => 'None',
        };

        return [
            'state' => $state,
            'basis' => $total < 0.005 ? 'NoPaymentRequired' : $assessment->assessment_basis,
            'total' => number_format($total, 2, '.', ''),
            'satisfied' => number_format($satisfied, 2, '.', ''),
            'balance' => number_format($balance, 2, '.', ''),
            'assessment_id' => $assessment->id,
            'payment_applied' => number_format($paymentApplied, 2, '.', ''),
            'coverage_applied' => number_format($coverageApplied, 2, '.', ''),
            'satisfaction_basis' => $satisfactionBasis,
            'later_obligation' => $assessment->obligations->where('required_for_enrollment', false)->isNotEmpty(),
            'as_of' => now()->toIso8601String(),
        ];
    }

    /** @return array{state:string,basis:null,total:null,satisfied:null,balance:null,assessment_id:null,payment_applied:null,coverage_applied:null,satisfaction_basis:string,later_obligation:bool,as_of:string} */
    private function unavailable(): array
    {
        return [
            'state' => 'Unavailable',
            'basis' => null,
            'total' => null,
            'satisfied' => null,
            'balance' => null,
            'assessment_id' => null,
            'payment_applied' => null,
            'coverage_applied' => null,
            'satisfaction_basis' => 'None',
            'later_obligation' => false,
            'as_of' => now()->toIso8601String(),
        ];
    }
}
