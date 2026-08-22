<?php

namespace App\Actions\Finance;

use App\Models\Enrollment;
use App\Models\TermAccount;
use App\Support\DecimalMoney;

class EnrollmentPaymentRequirementProjection
{
    public function __construct(private readonly TermAccountProjection $accounts, private readonly DecimalMoney $money) {}

    /**
     * @return array{state:string,basis:?string,total:?string,satisfied:?string,balance:?string,assessment_id:?int,payment_applied:?string,coverage_applied:?string,satisfaction_basis:string,later_obligation:bool,as_of:string}
     */
    public function forEnrollment(Enrollment $enrollment): array
    {
        $account = TermAccount::query()->where('enrollment_id', $enrollment->id)->first();
        if (! $account instanceof TermAccount) {
            return $this->unavailable();
        }
        $position = $this->accounts->forAccount($account);
        if ($position['assessment_id'] === null) {
            return $this->unavailable();
        }
        $required = collect($position['obligations'])->where('required_for_enrollment', true);
        $totalCents = $required->sum(fn (array $row): int => $this->money->toCents($row['amount']));
        $balanceCents = $required->sum(fn (array $row): int => $this->money->toCents($row['balance']));
        $satisfiedCents = max(0, $totalCents - $balanceCents);
        $state = $balanceCents === 0 ? 'Cleared' : 'ActionNeeded';
        $satisfactionBasis = match (true) {
            $state !== 'Cleared' => 'None',
            $totalCents === 0 => 'NoPaymentRequired',
            $this->money->greaterThanZero($position['payment_applied']) && $this->money->greaterThanZero($position['coverage_applied']) => 'Mixed',
            $this->money->greaterThanZero($position['payment_applied']) => 'VerifiedPayment',
            $this->money->greaterThanZero($position['coverage_applied']) => 'ApprovedCoverage',
            default => 'None',
        };

        return [
            'state' => $state,
            'basis' => $totalCents === 0 ? 'NoPaymentRequired' : 'CanonicalObligations',
            'total' => $this->money->fromCents($totalCents),
            'satisfied' => $this->money->fromCents($satisfiedCents),
            'balance' => $this->money->fromCents($balanceCents),
            'assessment_id' => $position['assessment_id'],
            'payment_applied' => $position['payment_applied'],
            'coverage_applied' => $position['coverage_applied'],
            'satisfaction_basis' => $satisfactionBasis,
            'later_obligation' => collect($position['obligations'])->contains(fn (array $row): bool => ! $row['required_for_enrollment']),
            'as_of' => $position['as_of'],
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
