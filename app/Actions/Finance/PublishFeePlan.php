<?php

namespace App\Actions\Finance;

use App\Models\FeePlan;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublishFeePlan
{
    public function __construct(private readonly DecimalMoney $money) {}

    public function execute(FeePlan $feePlan, User $actor, string $authorityReference, ?CarbonImmutable $authorityDate = null, ?string $noPaymentRequiredAuthorityReference = null, ?string $expectedContentFingerprint = null): FeePlan
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only Accounting staff may publish a Fee Plan.');
        }

        return DB::transaction(function () use ($feePlan, $actor, $authorityReference, $authorityDate, $noPaymentRequiredAuthorityReference, $expectedContentFingerprint): FeePlan {
            $locked = FeePlan::query()->with(['charges', 'obligations'])->whereKey($feePlan->id)->lockForUpdate()->firstOrFail();
            if ($expectedContentFingerprint !== null && ! hash_equals($expectedContentFingerprint, $locked->contentFingerprint())) {
                throw ValidationException::withMessages(['fee_plan' => 'This Fee Plan changed after you opened it. Close this form and review the current charges and payment dates before publishing.']);
            }

            if ($locked->state === FeePlan::StatePublished) {
                return $locked;
            }
            $this->assertStructure($locked);
            if ($this->money->toCents($locked->obligations->first()->amount) === 0 && blank($noPaymentRequiredAuthorityReference)) {
                throw ValidationException::withMessages(['no_payment_required_authority_reference' => 'A zero enrollment requirement needs explicit institutional NoPaymentRequired authority.']);
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
                'no_payment_required_authority_reference' => $noPaymentRequiredAuthorityReference,
                'charges' => $locked->charges->map->only(['sequence', 'code', 'label', 'category', 'amount'])->all(),
                'obligations' => $locked->obligations->map->only(['sequence', 'code', 'label', 'purpose', 'amount', 'due_at', 'required_for_enrollment'])->all(),
            ];
            $locked->update([
                'state' => FeePlan::StatePublished,
                'authority_reference' => $authorityReference,
                'authority_date' => $authorityDate->toDateString(),
                'no_payment_required_authority_reference' => filled($noPaymentRequiredAuthorityReference) ? trim($noPaymentRequiredAuthorityReference) : null,
                'content_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                'published_by' => $actor->id,
                'published_at' => now(),
            ]);

            return $locked->refresh()->load(['charges', 'obligations']);
        }, attempts: 3);
    }

    public function readiness(FeePlan $plan): ?string
    {
        try {
            $this->assertStructure($plan);

            return null;
        } catch (ValidationException $exception) {
            return collect($exception->errors())->flatten()->first();
        }
    }

    private function assertStructure(FeePlan $plan): void
    {
        $plan->loadMissing(['charges', 'obligations']);
        if ($plan->charges->isEmpty() || $plan->obligations->isEmpty()) {
            throw ValidationException::withMessages(['fee_plan' => 'Add charges and dated payments before requesting approval.']);
        }
        if ($plan->charges->contains(fn ($line): bool => blank($line->code) || blank($line->label))) {
            throw ValidationException::withMessages(['fee_plan' => 'Each charge needs a recorded code and label.']);
        }
        if ($plan->obligations->contains(fn ($row): bool => blank($row->code) || blank($row->label) || blank($row->purpose))) {
            throw ValidationException::withMessages(['fee_plan' => 'Each payment needs a recorded code, label and purpose.']);
        }
        app(CreateFeePlanDraft::class)->validateRows($plan->charges->toArray(), $plan->obligations->toArray());
        $enrollmentPayments = $plan->obligations->where('required_for_enrollment', true);
        if ($enrollmentPayments->count() !== 1 || $enrollmentPayments->first()->id !== $plan->obligations->first()->id) {
            throw ValidationException::withMessages(['fee_plan' => 'The first payment must be the only enrollment-required payment.']);
        }
        if ($plan->charges->contains(fn ($line): bool => $this->money->toCents($line->amount) < 0)
            || $plan->obligations->contains(fn ($row): bool => $this->money->toCents($row->amount) < 0)) {
            throw ValidationException::withMessages(['fee_plan' => 'Charge and payment amounts must be non-negative.']);
        }
        $chargeTotal = $plan->charges->sum(fn ($line): int => $this->money->toCents($line->amount));
        $paymentTotal = $plan->obligations->sum(fn ($row): int => $this->money->toCents($row->amount));
        if ($chargeTotal !== $paymentTotal) {
            throw ValidationException::withMessages(['fee_plan' => 'Payment amounts must add up exactly to the total charges.']);
        }
    }
}
