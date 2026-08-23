<?php

namespace App\Actions\Integrations\Payments;

use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\OperationalEvent;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PayMongoCheckoutRecoveryService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly DecimalMoney $money,
        private readonly PayMongoPaymentPostingService $postingService,
    ) {}

    /**
     * Retrieve one known checkout from PayMongo without treating the provider response as an automatic ledger posting.
     *
     * @return array{status:string,payment_attempt_id:int,event_id?:int}
     */
    public function recover(int $paymentAttemptId, User $actor): array
    {
        $this->authorize($actor);

        $attempt = PaymentAttempt::query()->findOrFail($paymentAttemptId);
        $this->assertRecoverableAttempt($attempt);

        $session = $this->gateway->retrieveCheckoutSession((string) $attempt->provider_checkout_id);

        if ($session->provider !== 'paymongo' || $session->checkoutSessionId !== $attempt->provider_checkout_id) {
            throw new RuntimeException('The provider response does not belong to the selected Payment Attempt.');
        }

        return DB::transaction(function () use ($actor, $attempt, $session): array {
            $lockedAttempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $this->assertRecoverableAttempt($lockedAttempt);

            $paidPayments = collect($session->payments)
                ->filter(fn (array $payment): bool => ($payment['status'] ?? null) === 'paid')
                ->values();

            if ($paidPayments->isEmpty()) {
                $hasFailedPayment = collect($session->payments)
                    ->contains(fn (array $payment): bool => ($payment['status'] ?? null) === 'failed');
                $status = match (true) {
                    $session->status === 'expired' => PaymentAttempt::StatusExpired,
                    in_array($session->status, ['cancelled', 'canceled'], true) => PaymentAttempt::StatusCancelled,
                    $hasFailedPayment || $session->status === 'failed' => PaymentAttempt::StatusFailed,
                    default => PaymentAttempt::StatusPending,
                };
                $lockedAttempt->forceFill(['status' => $status])->save();

                $event = OperationalEvent::query()->firstOrCreate(
                    [
                        'event_domain' => OperationalEvent::DomainIntegration,
                        'external_id' => "paymongo-recovery:attempt:{$lockedAttempt->id}:state:{$status}",
                    ],
                    [
                        'integration' => OperationalEvent::IntegrationPayMongo,
                        'channel' => OperationalEvent::ChannelProviderApi,
                        'direction' => OperationalEvent::DirectionInbound,
                        'event_type' => 'checkout_session.recovered',
                        'event_version' => '1',
                        'user_id' => $actor->id,
                        'status' => OperationalEvent::StatusProcessed,
                        'occurred_at' => now(),
                        'processed_at' => now(),
                        'related_record_type' => PaymentAttempt::class,
                        'related_record_id' => $lockedAttempt->id,
                        'diagnostics' => [
                            'reason' => 'provider_state_recovered',
                            'outcome' => $status,
                        ],
                        'payload' => [
                            'checkout_session_id' => $session->checkoutSessionId,
                            'reference_number' => $session->referenceNumber,
                            'provider_status' => $session->status,
                            'payment_count' => count($session->payments),
                        ],
                    ],
                );

                return [
                    'status' => $status,
                    'payment_attempt_id' => $lockedAttempt->id,
                    'event_id' => $event->id,
                ];
            }

            /** @var array<string, mixed> $payment */
            $payment = $paidPayments->first();
            $externalId = "paymongo-recovery:attempt:{$lockedAttempt->id}:payment:".(string) $payment['id'];
            $event = OperationalEvent::query()->firstOrCreate(
                [
                    'event_domain' => OperationalEvent::DomainIntegration,
                    'external_id' => $externalId,
                ],
                [
                    'integration' => OperationalEvent::IntegrationPayMongo,
                    'channel' => OperationalEvent::ChannelProviderApi,
                    'direction' => OperationalEvent::DirectionInbound,
                    'event_type' => 'checkout_session.payment.recovered',
                    'event_version' => '1',
                    'user_id' => $actor->id,
                    'status' => OperationalEvent::StatusReviewRequired,
                    'occurred_at' => now(),
                    'related_record_type' => PaymentAttempt::class,
                    'related_record_id' => $lockedAttempt->id,
                    'diagnostics' => [
                        'reason' => 'recovered_paid_without_webhook',
                        'outcome' => 'accounting_confirmation_required',
                    ],
                    'payload' => [
                        'checkout_session_id' => $session->checkoutSessionId,
                        'reference_number' => $session->referenceNumber,
                        'provider_status' => $session->status,
                        'payment_count' => $paidPayments->count(),
                        'payment' => $payment,
                    ],
                ],
            );

            if ($event->status === OperationalEvent::StatusProcessed) {
                return [
                    'status' => 'duplicate',
                    'payment_attempt_id' => $lockedAttempt->id,
                    'event_id' => $event->id,
                ];
            }

            $lockedAttempt->forceFill(['status' => PaymentAttempt::StatusReviewRequired])->save();

            return [
                'status' => 'review_required',
                'payment_attempt_id' => $lockedAttempt->id,
                'event_id' => $event->id,
            ];
        }, attempts: 3);
    }

    /**
     * @return array{status:string,event_id:int,payment_id:int}
     */
    public function confirm(int $eventId, string $reason, User $actor): array
    {
        $this->authorize($actor);
        $reason = $this->normalizedReason($reason);

        return DB::transaction(function () use ($actor, $eventId, $reason): array {
            $event = $this->recoveryEventForUpdate($eventId);
            $attempt = $this->attemptForEvent($event);
            $existingPayment = Payment::query()
                ->with('ledgerEntry')
                ->where('payment_attempt_id', $attempt->id)
                ->lockForUpdate()
                ->first();

            if ($event->status === OperationalEvent::StatusProcessed
                && $existingPayment instanceof Payment
                && $existingPayment->ledgerEntry !== null) {
                return [
                    'status' => 'duplicate',
                    'event_id' => $event->id,
                    'payment_id' => $existingPayment->id,
                ];
            }

            if ($event->status !== OperationalEvent::StatusReviewRequired) {
                throw new RuntimeException('The recovered PayMongo evidence is no longer awaiting confirmation.');
            }

            $payment = data_get($event->payload, 'payment');

            if (! is_array($payment)) {
                throw new RuntimeException('The recovered PayMongo evidence is incomplete.');
            }

            $this->assertExactPaymentEvidence($event, $attempt, $payment);

            if ($attempt->provider_intent_id === null) {
                $attempt->forceFill([
                    'provider_intent_id' => $payment['payment_intent_id'],
                ])->save();
            }

            $timestamp = is_int($payment['paid_at'] ?? null)
                ? CarbonImmutable::createFromTimestamp($payment['paid_at'], config('app.timezone'))
                : CarbonImmutable::now(config('app.timezone'));
            $result = $this->postingService->post(
                attempt: $attempt,
                amount: $this->money->fromCents((int) $payment['amount_centavos']),
                providerReference: 'paymongo:'.(string) $payment['id'],
                actor: $actor,
                timestamp: $timestamp,
                description: 'PayMongo payment recovered by Accounting from the provider checkout.',
            );
            $postedPayment = $result['payment'];

            $event->forceFill([
                'status' => OperationalEvent::StatusProcessed,
                'processed_at' => now(),
                'failed_at' => null,
                'user_id' => $actor->id,
                'related_record_type' => Payment::class,
                'related_record_id' => $postedPayment->id,
                'diagnostics' => [
                    ...($event->diagnostics ?? []),
                    'outcome' => 'processed',
                    'resolution' => [
                        'action' => 'confirmed',
                        'actor_id' => $actor->id,
                        'reason' => $reason,
                        'payment_id' => $postedPayment->id,
                        'decided_at' => now()->toIso8601String(),
                    ],
                ],
            ])->save();
            $this->recordActivity(
                event: $event,
                actor: $actor,
                action: 'paymongo_recovered_payment_confirmed',
                reason: $reason,
                paymentAttemptId: $attempt->id,
                paymentId: $postedPayment->id,
            );

            return [
                'status' => 'confirmed',
                'event_id' => $event->id,
                'payment_id' => $postedPayment->id,
            ];
        }, attempts: 3);
    }

    /**
     * @return array{status:string,event_id:int,payment_attempt_id:int}
     */
    public function reject(int $eventId, string $reason, User $actor): array
    {
        $this->authorize($actor);
        $reason = $this->normalizedReason($reason);

        return DB::transaction(function () use ($actor, $eventId, $reason): array {
            $event = $this->recoveryEventForUpdate($eventId);
            $attempt = $this->attemptForEvent($event);

            if ($event->status !== OperationalEvent::StatusReviewRequired) {
                throw new RuntimeException('The recovered PayMongo evidence is no longer awaiting a decision.');
            }

            if (Payment::query()
                ->where('payment_attempt_id', $attempt->id)
                ->where('evidence_status', 'verified')
                ->lockForUpdate()
                ->exists()) {
                throw new RuntimeException('Posted PayMongo evidence cannot be rejected.');
            }

            $attempt->forceFill(['status' => PaymentAttempt::StatusFailed])->save();
            $event->forceFill([
                'status' => OperationalEvent::StatusProcessed,
                'processed_at' => now(),
                'failed_at' => null,
                'user_id' => $actor->id,
                'diagnostics' => [
                    ...($event->diagnostics ?? []),
                    'outcome' => 'processed',
                    'resolution' => [
                        'action' => 'rejected',
                        'actor_id' => $actor->id,
                        'reason' => $reason,
                        'decided_at' => now()->toIso8601String(),
                    ],
                ],
            ])->save();
            $this->recordActivity(
                event: $event,
                actor: $actor,
                action: 'paymongo_recovered_payment_rejected',
                reason: $reason,
                paymentAttemptId: $attempt->id,
            );

            return [
                'status' => 'rejected',
                'event_id' => $event->id,
                'payment_attempt_id' => $attempt->id,
            ];
        }, attempts: 3);
    }

    private function authorize(User $actor): void
    {
        if (! $actor->canProcessPayments()) {
            throw new AuthorizationException('Only Accounting/Cashier can recover PayMongo checkout evidence.');
        }
    }

    private function assertRecoverableAttempt(PaymentAttempt $attempt): void
    {
        $assessment = Assessment::query()->find($attempt->assessment_id);
        $enrollment = $assessment instanceof Assessment
            ? Enrollment::query()->find($assessment->enrollment_id)
            : null;

        if ($attempt->provider !== 'paymongo'
            || ! filled($attempt->provider_checkout_id)
            || ! in_array($attempt->status, PaymentAttempt::ActiveStatuses, true)
            || $attempt->term_account_id === null
            || $attempt->snapshot_checksum === null
            || ! $assessment instanceof Assessment
            || $assessment->state !== Assessment::StateActive
            || $assessment->term_account_id !== $attempt->term_account_id
            || ! $enrollment instanceof Enrollment
            || $enrollment->student_profile_id !== $attempt->student_profile_id) {
            throw new RuntimeException('The selected Payment Attempt is not eligible for PayMongo recovery.');
        }
    }

    private function recoveryEventForUpdate(int $eventId): OperationalEvent
    {
        $event = OperationalEvent::query()->lockForUpdate()->findOrFail($eventId);

        if ($event->event_domain !== OperationalEvent::DomainIntegration
            || $event->integration !== OperationalEvent::IntegrationPayMongo
            || $event->channel !== OperationalEvent::ChannelProviderApi
            || $event->event_type !== 'checkout_session.payment.recovered') {
            throw new RuntimeException('The selected event is not recovered PayMongo checkout evidence.');
        }

        return $event;
    }

    private function attemptForEvent(OperationalEvent $event): PaymentAttempt
    {
        $attemptId = $event->related_record_type === PaymentAttempt::class
            ? $event->related_record_id
            : data_get($event->diagnostics, 'resolution.payment_attempt_id');

        if ($event->related_record_type === Payment::class && $event->related_record_id !== null) {
            $payment = Payment::query()->find($event->related_record_id);
            $attemptId = $payment?->payment_attempt_id;
        }

        if (! is_numeric($attemptId)) {
            throw new RuntimeException('The recovered PayMongo evidence is not linked to a Payment Attempt.');
        }

        return PaymentAttempt::query()->lockForUpdate()->findOrFail((int) $attemptId);
    }

    /** @param array<string, mixed> $payment */
    private function assertExactPaymentEvidence(
        OperationalEvent $event,
        PaymentAttempt $attempt,
        array $payment,
    ): void {
        $paymentCount = data_get($event->payload, 'payment_count');
        $amountCentavos = $payment['amount_centavos'] ?? null;
        $paymentIntentId = $payment['payment_intent_id'] ?? null;
        $paidAt = $payment['paid_at'] ?? null;

        if (data_get($event->payload, 'checkout_session_id') !== $attempt->provider_checkout_id
            || data_get($event->payload, 'reference_number') !== $attempt->internal_reference
            || $paymentCount !== 1
            || ! filled($payment['id'] ?? null)
            || ($payment['status'] ?? null) !== 'paid'
            || ! is_int($amountCentavos)
            || $amountCentavos <= 0
            || $amountCentavos !== $this->money->toCents((string) $attempt->amount)
            || ($payment['currency'] ?? null) !== 'PHP'
            || ($payment['livemode'] ?? null) !== (bool) config('tala_integrations.payments.paymongo.livemode', false)
            || ! is_string($paymentIntentId)
            || $paymentIntentId === ''
            || ($attempt->provider_intent_id !== null && $paymentIntentId !== $attempt->provider_intent_id)
            || ($payment['disputed'] ?? true) !== false
            || ($payment['has_refunds'] ?? true) !== false
            || ($paidAt !== null && ! is_int($paidAt))) {
            throw new RuntimeException('Recovered PayMongo evidence does not exactly match the selected Payment Attempt.');
        }
    }

    private function normalizedReason(string $reason): string
    {
        $reason = trim($reason);
        $length = mb_strlen($reason);

        if ($length < 5 || $length > 1000) {
            throw new RuntimeException('An Accounting reason between 5 and 1000 characters is required.');
        }

        return $reason;
    }

    private function recordActivity(
        OperationalEvent $event,
        User $actor,
        string $action,
        string $reason,
        int $paymentAttemptId,
        ?int $paymentId = null,
    ): void {
        activity('paymongo_reconciliation')
            ->performedOn($event)
            ->causedBy($actor)
            ->withProperties(array_filter([
                'reason' => $reason,
                'payment_attempt_id' => $paymentAttemptId,
                'payment_id' => $paymentId,
            ], fn (mixed $value): bool => $value !== null))
            ->event($action)
            ->log(str($action)->headline()->toString());
    }
}
