<?php

namespace App\Actions\Finance;

use App\Models\ApprovedCoverage;
use App\Models\AssessmentObligation;
use App\Models\TermAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordApprovedCoverage
{
    public function __construct(private readonly EnrollmentPaymentRequirementProjection $projection) {}

    public function execute(TermAccount $account, AssessmentObligation $obligation, string $amount, string $authorityReference, User $actor): ApprovedCoverage
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only Accounting staff may record Approved Coverage.');
        }

        return DB::transaction(function () use ($account, $obligation, $amount, $authorityReference, $actor): ApprovedCoverage {
            $lockedAccount = TermAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $lockedObligation = AssessmentObligation::query()->with('assessment')->whereKey($obligation->id)->lockForUpdate()->firstOrFail();

            if ((int) $lockedObligation->assessment->term_account_id !== (int) $lockedAccount->id
                || (float) $amount <= 0 || (float) $amount > (float) $lockedObligation->amount || blank($authorityReference)) {
                throw ValidationException::withMessages(['coverage' => 'Coverage must belong to this exact obligation, remain within its amount, and cite authority.']);
            }

            $coverage = ApprovedCoverage::query()->create([
                'term_account_id' => $lockedAccount->id,
                'assessment_obligation_id' => $lockedObligation->id,
                'amount' => number_format((float) $amount, 2, '.', ''),
                'authority_reference' => $authorityReference,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);
            $state = $this->projection->forEnrollment($lockedAccount->enrollment);
            $lockedAccount->update(['state' => $state['state'] === 'Cleared' ? TermAccount::StateCleared : TermAccount::StateOpen]);

            return $coverage;
        }, attempts: 3);
    }
}
