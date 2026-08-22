<?php

namespace App\Actions\Finance;

use App\Models\ApprovedCoverage;
use App\Models\AssessmentObligation;
use App\Models\TermAccount;
use App\Models\User;
use App\Support\DecimalMoney;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordApprovedCoverage
{
    public function __construct(private readonly TermAccountProjection $projection, private readonly DecimalMoney $money) {}

    /** @param array{category:string,safe_source_description:string,authority_reference:string,authority_date:string,effective_date:string,amount:string,supersedes_coverage_id?:int|null} $data */
    public function execute(TermAccount $account, AssessmentObligation $obligation, array $data, User $actor): ApprovedCoverage
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only Accounting staff may record Approved Coverage.');
        }

        return DB::transaction(function () use ($account, $obligation, $data, $actor): ApprovedCoverage {
            $lockedAccount = TermAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $lockedObligation = AssessmentObligation::query()->with('assessment')->whereKey($obligation->id)->lockForUpdate()->firstOrFail();
            $superseded = isset($data['supersedes_coverage_id'])
                ? ApprovedCoverage::query()->whereKey((int) $data['supersedes_coverage_id'])->lockForUpdate()->first()
                : null;
            if ($superseded instanceof ApprovedCoverage && ($superseded->state !== ApprovedCoverage::StateApplied
                || (int) $superseded->assessment_obligation_id !== (int) $lockedObligation->id)) {
                throw ValidationException::withMessages(['coverage' => 'Only active coverage on the same obligation may be superseded.']);
            }

            $amount = $this->money->normalize($data['amount']);
            $activeCoverage = ApprovedCoverage::query()
                ->where('assessment_obligation_id', $lockedObligation->id)
                ->where('state', ApprovedCoverage::StateApplied)
                ->when($superseded instanceof ApprovedCoverage, fn ($query) => $query->whereKeyNot($superseded->id))
                ->lockForUpdate()
                ->sum('amount');
            $remaining = $this->money->subtract($lockedObligation->amount, (string) $activeCoverage);
            if ((int) $lockedObligation->assessment->term_account_id !== (int) $lockedAccount->id
                || ! $this->money->greaterThanZero($amount) || $this->money->toCents($amount) > $this->money->toCents($remaining)
                || ! in_array($data['category'], ApprovedCoverage::Categories, true)
                || blank($data['safe_source_description']) || blank($data['authority_reference'])
                || blank($data['authority_date']) || blank($data['effective_date'])) {
                throw ValidationException::withMessages(['coverage' => 'Coverage must belong to this exact obligation, remain within its amount, and cite authority.']);
            }

            $coverage = ApprovedCoverage::query()->create([
                'term_account_id' => $lockedAccount->id,
                'assessment_obligation_id' => $lockedObligation->id,
                'supersedes_coverage_id' => $superseded?->id,
                'state' => ApprovedCoverage::StateApplied,
                'category' => $data['category'],
                'safe_source_description' => trim($data['safe_source_description']),
                'amount' => $amount,
                'authority_reference' => trim($data['authority_reference']),
                'authority_date' => $data['authority_date'],
                'effective_date' => $data['effective_date'],
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);
            $superseded?->update(['state' => ApprovedCoverage::StateSuperseded]);
            $state = $this->projection->forAccount($lockedAccount);
            $lockedAccount->update(['state' => $state['state'] === 'Cleared' ? TermAccount::StateCleared : TermAccount::StateOpen]);

            return $coverage;
        }, attempts: 3);
    }
}
