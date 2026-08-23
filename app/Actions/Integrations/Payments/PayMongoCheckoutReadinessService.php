<?php

namespace App\Actions\Integrations\Payments;

use App\Models\PaymentAttempt;
use App\Models\StudentProfile;
use App\Models\TermAccount;
use App\Models\User;

final class PayMongoCheckoutReadinessService
{
    public function __construct(private readonly ExactDuePaymentSnapshotService $snapshots) {}

    /**
     * @return array{enabled:bool,reason:string,amount:?string,obligations:list<array{id:int,sequence:int,code:string,label:string,amount:string}>}
     */
    public function for(User $actor, TermAccount $account): array
    {
        if ($account->credential_user_id !== $actor->id) {
            return $this->blocked('This Term Account does not belong to the signed-in credential.');
        }

        $profile = $account->enrollment?->studentProfile;

        if ($profile instanceof StudentProfile && $profile->lifecycle_status === StudentProfile::LifecycleCompleted) {
            return $this->blocked('Completed alumni accounts are read-only.');
        }

        $active = PaymentAttempt::query()
            ->where('term_account_id', $account->id)
            ->whereIn('status', PaymentAttempt::ActiveStatuses)
            ->latest('id')
            ->first();

        if ($active instanceof PaymentAttempt) {
            return $this->blocked($active->status === PaymentAttempt::StatusReviewRequired
                ? 'Accounting must resolve the current Payment Exception before another checkout.'
                : 'Payment confirmation is pending. Do not start another checkout.');
        }

        if (config('tala_integrations.payments.driver') !== 'paymongo'
            || blank(config('tala_integrations.payments.paymongo.secret_key'))
            || blank(config('tala_integrations.payments.paymongo.webhook_signature'))) {
            return $this->blocked('Online checkout is unavailable. Manual payment evidence remains available.');
        }

        if (config('queue.default') === 'sync') {
            return $this->blocked('Online confirmation is unavailable until the payment queue is active.');
        }

        try {
            $snapshot = $this->snapshots->forAccount($account);
        } catch (PaymentAttemptSnapshotException $exception) {
            return $this->blocked($exception->reason === 'positive_current_due_unavailable'
                ? 'There is no positive current due to pay online.'
                : 'The current Assessment is not ready for online checkout.');
        }

        return [
            'enabled' => true,
            'reason' => 'Pay only the exact current due shown below. PayMongo confirmation may remain pending briefly.',
            'amount' => $snapshot['amount'],
            'obligations' => $snapshot['obligations'],
        ];
    }

    /** @return array{enabled:false,reason:string,amount:null,obligations:array{}} */
    private function blocked(string $reason): array
    {
        return ['enabled' => false, 'reason' => $reason, 'amount' => null, 'obligations' => []];
    }
}
