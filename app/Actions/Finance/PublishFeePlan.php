<?php

namespace App\Actions\Finance;

use App\Models\FeePlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublishFeePlan
{
    public function execute(FeePlan $feePlan, User $actor, string $authorityReference, ?CarbonImmutable $authorityDate = null): FeePlan
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only Accounting staff may publish a Fee Plan.');
        }

        return DB::transaction(function () use ($feePlan, $actor, $authorityReference, $authorityDate): FeePlan {
            $locked = FeePlan::query()->with(['charges', 'obligations'])->whereKey($feePlan->id)->lockForUpdate()->firstOrFail();

            if ($locked->state === FeePlan::StatePublished) {
                return $locked;
            }
            if ($locked->state !== FeePlan::StateDraft || $locked->charges->isEmpty()
                || $locked->obligations->isEmpty() || blank($authorityReference) || ! $authorityDate instanceof CarbonImmutable) {
                throw ValidationException::withMessages(['fee_plan' => 'Only a complete Draft with recorded authority may be published.']);
            }

            $otherPublished = FeePlan::query()
                ->where('program_id', $locked->program_id)
                ->where('term_id', $locked->term_id)
                ->where('state', FeePlan::StatePublished)
                ->whereKeyNot($locked->id)
                ->lockForUpdate()
                ->first();

            if ($otherPublished instanceof FeePlan
                && (int) $locked->supersedes_fee_plan_id !== (int) $otherPublished->id) {
                throw ValidationException::withMessages(['fee_plan' => 'Publish the recorded successor instead of creating two current Fee Plans.']);
            }
            if ($otherPublished instanceof FeePlan) {
                $otherPublished->update(['state' => FeePlan::StateSuperseded]);
            }

            $payload = [
                'program_id' => $locked->program_id,
                'term_id' => $locked->term_id,
                'version' => $locked->version,
                'charges' => $locked->charges->map->only(['sequence', 'code', 'label', 'category', 'amount'])->all(),
                'obligations' => $locked->obligations->map->only(['sequence', 'code', 'label', 'purpose', 'amount', 'due_at', 'required_for_enrollment'])->all(),
            ];
            $locked->update([
                'state' => FeePlan::StatePublished,
                'authority_reference' => $authorityReference,
                'authority_date' => $authorityDate->toDateString(),
                'content_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                'published_by' => $actor->id,
                'published_at' => now(),
            ]);

            return $locked->refresh()->load(['charges', 'obligations']);
        }, attempts: 3);
    }
}
