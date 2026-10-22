<?php

namespace App\Actions\Finance;

use App\Models\Assessment;
use App\Models\AssessmentEffectRevalidation;
use App\Models\TermAccount;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RevalidateAssessmentEffects
{
    public function __construct(private readonly TermAccountProjection $projection, private readonly DecimalMoney $money) {}

    public function execute(Assessment $assessment, User $actor, array $obligationMapping, ?string $authorityReference = null): AssessmentEffectRevalidation
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only Accounting staff may reconcile retained financial effects.');
        }

        return DB::transaction(function () use ($assessment, $actor, $obligationMapping, $authorityReference): AssessmentEffectRevalidation {
            $account = TermAccount::query()->lockForUpdate()->findOrFail($assessment->term_account_id);
            $current = Assessment::query()->with('obligations')->lockForUpdate()->findOrFail($assessment->id);
            if ($current->state !== Assessment::StateActive) {
                throw ValidationException::withMessages(['reconciliation' => 'Reconcile the current assessment with an Accounting decision.']);
            }
            $sources = $this->projection->retainedEffects($account, $current);
            $expected = collect($sources)->pluck('id')->sort()->values()->all();
            $provided = collect(array_keys($obligationMapping))->map(fn ($id): int => (int) $id)->sort()->values()->all();
            if ($sources === [] || $expected !== $provided) {
                throw ValidationException::withMessages(['reconciliation' => 'Every retained payment and coverage obligation needs an explicit current target.']);
            }

            $available = $current->obligations->mapWithKeys(function ($obligation): array {
                $effects = $this->projection->effects($obligation, CarbonImmutable::now(config('app.timezone')));

                return [$obligation->id => $this->money->toCents($obligation->amount) - $effects['payment'] - $effects['committed_coverage']];
            })->all();
            $mapping = [];
            foreach ($sources as $source) {
                $targetId = (int) $obligationMapping[$source['id']];
                $effect = $source['payment'] + $source['committed_coverage'];
                if (! array_key_exists($targetId, $available) || $source['payment'] < 0 || $effect > $available[$targetId]) {
                    throw ValidationException::withMessages(['reconciliation' => 'Retained effects exceed a named current obligation or have an unresolved correction. No money was moved.']);
                }
                $available[$targetId] -= $effect;
                $mapping[] = [
                    'source_obligation_id' => $source['id'], 'target_obligation_id' => $targetId,
                    'payment_amount' => $this->money->fromCents($source['payment']),
                    'coverage_amount' => $this->money->fromCents($source['committed_coverage']),
                ];
            }

            $reference = filled($authorityReference)
                ? trim((string) $authorityReference)
                : 'accounting-mapping:'.$current->id.':'.hash('sha256', json_encode($mapping, JSON_THROW_ON_ERROR));
            $existing = AssessmentEffectRevalidation::query()->where('assessment_id', $current->id)->latest('version')->first();
            if ($existing !== null && $existing->obligation_mapping == $mapping && $existing->authority_reference === $reference) {
                return $existing;
            }
            $record = AssessmentEffectRevalidation::query()->create([
                'term_account_id' => $account->id, 'assessment_id' => $current->id,
                'version' => ($existing?->version ?? 0) + 1, 'obligation_mapping' => $mapping,
                'authority_reference' => $reference, 'recorded_by' => $actor->id, 'recorded_at' => now(),
            ]);
            $position = $this->projection->forAccount($account);
            if ($position['state'] === 'Unavailable') {
                throw ValidationException::withMessages(['reconciliation' => 'The assessment source changed. Refresh before reconciling retained effects.']);
            }
            $account->update(['state' => $position['state'] === 'Cleared' ? TermAccount::StateCleared : TermAccount::StateOpen]);

            return $record;
        }, attempts: 3);
    }
}
