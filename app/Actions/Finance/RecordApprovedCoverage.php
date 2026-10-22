<?php

namespace App\Actions\Finance;

use App\Models\ApprovedCoverage;
use App\Models\Assessment;
use App\Models\AssessmentObligation;
use App\Models\TermAccount;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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
        Validator::make($data, [
            'category' => ['required', 'in:'.implode(',', ApprovedCoverage::Categories)],
            'safe_source_description' => ['required', 'string', 'max:2000'], 'authority_reference' => ['required', 'string', 'max:255'],
            'authority_date' => ['required', 'date'], 'effective_date' => ['required', 'date'], 'amount' => ['required', 'numeric', 'gt:0'],
        ])->validate();

        return DB::transaction(function () use ($account, $obligation, $data, $actor): ApprovedCoverage {
            $lockedAccount = TermAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $lockedObligation = AssessmentObligation::query()->with('assessment')->whereKey($obligation->id)->lockForUpdate()->firstOrFail();
            $superseded = isset($data['supersedes_coverage_id'])
                ? ApprovedCoverage::query()->whereKey((int) $data['supersedes_coverage_id'])->lockForUpdate()->first()
                : null;
            $amount = $this->money->normalize($data['amount']);
            $existing = ApprovedCoverage::query()->where('term_account_id', $lockedAccount->id)
                ->where('assessment_obligation_id', $lockedObligation->id)
                ->where('authority_reference', trim($data['authority_reference']))->latest('id')->first();
            if ($existing instanceof ApprovedCoverage) {
                if ($existing->state === ApprovedCoverage::StateApplied && $existing->amount === $amount
                    && $existing->category === $data['category'] && $existing->safe_source_description === trim($data['safe_source_description'])
                    && $existing->authority_date?->toDateString() === CarbonImmutable::parse($data['authority_date'])->toDateString()
                    && $existing->effective_date?->toDateString() === CarbonImmutable::parse($data['effective_date'])->toDateString()) {
                    return $existing;
                }
                if ($superseded?->id !== $existing->id) {
                    throw ValidationException::withMessages(['coverage' => 'This authority already identifies coverage on this obligation. Record its explicit successor or reversal before changing its effect.']);
                }
            }
            if (isset($data['supersedes_coverage_id']) && (! $superseded instanceof ApprovedCoverage || $superseded->state !== ApprovedCoverage::StateApplied
                || (int) $superseded->assessment_obligation_id !== (int) $lockedObligation->id)) {
                throw ValidationException::withMessages(['coverage' => 'Only active coverage on the same obligation may be superseded.']);
            }
            $superseded?->update(['state' => ApprovedCoverage::StateSuperseded]);
            $position = $this->projection->forAccount($lockedAccount);
            $row = collect($position['obligations'])->firstWhere('id', $lockedObligation->id);
            $remaining = $row['available_balance'] ?? '0.00';
            if ((int) $lockedObligation->assessment->term_account_id !== (int) $lockedAccount->id
                || $lockedObligation->assessment->state !== Assessment::StateActive
                || $position['state'] === 'Unavailable'
                || (int) $position['assessment_id'] !== (int) $lockedObligation->assessment_id
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
            $state = $this->projection->forAccount($lockedAccount);
            $lockedAccount->update(['state' => $state['state'] === 'Cleared' ? TermAccount::StateCleared : TermAccount::StateOpen]);

            return $coverage;
        }, attempts: 3);
    }
}
