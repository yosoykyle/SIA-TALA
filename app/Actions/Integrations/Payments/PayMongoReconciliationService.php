<?php

namespace App\Actions\Integrations\Payments;

use App\Jobs\ProcessPayMongoWebhookCall;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\LedgerEntry;
use App\Models\OperationalEvent;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

final class PayMongoReconciliationService
{
    /** @var list<string> */
    private const ConfirmableReasons = [
        'missing_tala_reference',
        'reference_mismatch',
    ];

    /** @var list<string> */
    private const RefundReasons = [
        'refund_or_reversal',
        'unknown_refund_payment',
    ];

    public function __construct(
        private readonly DecimalMoney $money,
        private readonly PayMongoPaymentPostingService $paymentPostingService,
    ) {}

    /** @return array{status:string,event_id:int,webhook_call_id:int} */
    public function linkAndReprocess(int $eventId, int $attemptId, string $reason, User $actor): array
    {
        $this->authorize($actor);
        $reason = $this->normalizedReason($reason);

        $result = DB::transaction(function () use ($eventId, $attemptId, $reason, $actor): array {
            $event = $this->eventForUpdate($eventId);

            if ($event->status !== OperationalEvent::StatusReviewRequired
                || $this->eventReason($event) !== 'unknown_reference') {
                throw new RuntimeException('Only an unknown PayMongo reference can be linked and reprocessed.');
            }

            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            $this->assertAttemptSource($attempt);

            if (! in_array($attempt->status, PaymentAttempt::ActiveStatuses, true)) {
                throw new RuntimeException('The selected Payment Attempt is no longer eligible for reconciliation.');
            }

            [$webhookCallId, $webhookEvent, $context] = $this->evidenceFor($event);
            $this->assertPaidPhpEvidence($event, $webhookEvent, $context);
            $this->assertCompatibleAttempt($attempt, $context);

            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $event->forceFill([
                'status' => OperationalEvent::StatusPending,
                'processed_at' => null,
                'failed_at' => null,
                'user_id' => $actor->id,
                'related_record_type' => PaymentAttempt::class,
                'related_record_id' => $attempt->id,
                'diagnostics' => [
                    ...($event->diagnostics ?? []),
                    'reconciliation' => [
                        'action' => 'linked_for_reprocessing',
                        'linked_attempt_id' => $attempt->id,
                        'actor_id' => $actor->id,
                        'reason' => $reason,
                        'decided_at' => $timestamp->toIso8601String(),
                    ],
                ],
            ])->save();
            $this->resetWebhookCall($webhookCallId, $timestamp);
            $this->recordActivity($event, $actor, 'paymongo_reference_linked', $reason, $timestamp, [
                'payment_attempt_id' => $attempt->id,
            ]);

            return ['status' => 'requeued', 'event_id' => $event->id, 'webhook_call_id' => $webhookCallId];
        }, attempts: 3);

        ProcessPayMongoWebhookCall::dispatch($result['webhook_call_id'], $result['event_id'])->afterCommit();

        return $result;
    }

    /** @return array{status:string,event_id:int,webhook_call_id:int} */
    public function reprocess(int $eventId, string $reason, User $actor): array
    {
        $this->authorize($actor);
        $reason = $this->normalizedReason($reason);

        $result = DB::transaction(function () use ($eventId, $reason, $actor): array {
            $event = $this->eventForUpdate($eventId);

            if ($event->status !== OperationalEvent::StatusFailed || $this->eventReason($event) !== 'processing_failed') {
                throw new RuntimeException('Only a failed PayMongo processing event can be reprocessed.');
            }

            [$webhookCallId] = $this->evidenceFor($event);
            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $event->forceFill([
                'status' => OperationalEvent::StatusPending,
                'processed_at' => null,
                'failed_at' => null,
                'user_id' => $actor->id,
                'diagnostics' => [
                    ...($event->diagnostics ?? []),
                    'reconciliation' => [
                        'action' => 'requeued',
                        'actor_id' => $actor->id,
                        'reason' => $reason,
                        'decided_at' => $timestamp->toIso8601String(),
                    ],
                ],
            ])->save();
            $this->resetWebhookCall($webhookCallId, $timestamp);
            $this->recordActivity($event, $actor, 'paymongo_event_requeued', $reason, $timestamp);

            return ['status' => 'requeued', 'event_id' => $event->id, 'webhook_call_id' => $webhookCallId];
        }, attempts: 3);

        ProcessPayMongoWebhookCall::dispatch($result['webhook_call_id'], $result['event_id'])->afterCommit();

        return $result;
    }

    /** @return array{status:string,payment_id:int,ledger_entry_id:int,finance_cleared:bool} */
    public function confirm(int $eventId, string $reason, User $actor): array
    {
        $this->authorize($actor);
        $reason = $this->normalizedReason($reason);

        return DB::transaction(function () use ($eventId, $reason, $actor): array {
            $event = $this->eventForUpdate($eventId);
            $resolution = data_get($event->diagnostics, 'resolution.action');

            if ($event->status === OperationalEvent::StatusProcessed && $resolution === 'confirmed') {
                $payment = $this->relatedPayment($event);
                $ledger = $payment->ledgerEntry;

                if (! $ledger instanceof LedgerEntry) {
                    throw new RuntimeException('Confirmed PayMongo evidence has no ledger posting.');
                }

                return [
                    'status' => 'duplicate',
                    'payment_id' => $payment->id,
                    'ledger_entry_id' => $ledger->id,
                    'finance_cleared' => false,
                ];
            }

            if ($event->status !== OperationalEvent::StatusReviewRequired
                || ! in_array($this->eventReason($event), self::ConfirmableReasons, true)) {
                throw new RuntimeException('This PayMongo evidence cannot be confirmed as a payment.');
            }

            $payment = $this->relatedPayment($event);
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($payment->payment_attempt_id);
            $this->assertAttemptSource($attempt);
            [$webhookCallId, $webhookEvent, $context] = $this->evidenceFor($event);
            $this->assertPaidPhpEvidence($event, $webhookEvent, $context);
            $this->assertConfirmationIdentifiers(
                $attempt,
                $context,
                allowReferenceMismatch: $this->eventReason($event) === 'reference_mismatch',
            );

            if ($context['amount_centavos'] === null || $context['provider_reference'] === null) {
                throw new RuntimeException('This PayMongo evidence cannot be confirmed as a payment.');
            }

            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $posting = $this->paymentPostingService->post(
                attempt: $attempt,
                amount: $this->money->fromCents($context['amount_centavos']),
                providerReference: 'paymongo:'.$context['provider_reference'],
                actor: $actor,
                timestamp: $timestamp,
                description: 'Accounting-confirmed PayMongo payment',
            );
            $payment = $posting['payment'];
            $ledger = $posting['ledger_entry'];
            $this->markWebhookProcessed($webhookCallId, $timestamp);
            $event->forceFill([
                'status' => OperationalEvent::StatusProcessed,
                'processed_at' => $timestamp,
                'failed_at' => null,
                'user_id' => $actor->id,
                'related_record_type' => Payment::class,
                'related_record_id' => $payment->id,
                'diagnostics' => [
                    ...($event->diagnostics ?? []),
                    'outcome' => 'processed',
                    'resolution' => [
                        'action' => 'confirmed',
                        'actor_id' => $actor->id,
                        'reason' => $reason,
                        'payment_id' => $payment->id,
                        'ledger_entry_id' => $ledger->id,
                        'decided_at' => $timestamp->toIso8601String(),
                    ],
                ],
            ])->save();
            $this->recordActivity($event, $actor, 'paymongo_payment_confirmed', $reason, $timestamp, [
                'payment_id' => $payment->id,
                'ledger_entry_id' => $ledger->id,
            ]);

            return [
                'status' => 'confirmed',
                'payment_id' => $payment->id,
                'ledger_entry_id' => $ledger->id,
                'finance_cleared' => $posting['finance_cleared'],
            ];
        }, attempts: 3);
    }

    /** @return array{status:string,event_id:int,payment_id?:int} */
    public function reject(int $eventId, string $reason, User $actor): array
    {
        $this->authorize($actor);
        $reason = $this->normalizedReason($reason);

        return DB::transaction(function () use ($eventId, $reason, $actor): array {
            $event = $this->eventForUpdate($eventId);
            $resolution = data_get($event->diagnostics, 'resolution.action');

            if ($event->status === OperationalEvent::StatusProcessed && $resolution === 'rejected') {
                return array_filter([
                    'status' => 'duplicate',
                    'event_id' => $event->id,
                    'payment_id' => $event->related_record_type === Payment::class ? $event->related_record_id : null,
                ], fn (mixed $value): bool => $value !== null);
            }

            $eventReason = $this->eventReason($event);

            if ($event->status !== OperationalEvent::StatusReviewRequired
                || in_array($eventReason, self::RefundReasons, true)) {
                throw new RuntimeException('This PayMongo evidence cannot be rejected through payment reconciliation.');
            }

            [$webhookCallId] = $this->evidenceFor($event);
            $payment = $event->related_record_type === Payment::class && $event->related_record_id !== null
                ? Payment::query()->with('ledgerEntry')->lockForUpdate()->find($event->related_record_id)
                : null;
            $attempt = $payment instanceof Payment && $payment->payment_attempt_id !== null
                ? PaymentAttempt::query()->lockForUpdate()->find($payment->payment_attempt_id)
                : null;

            if ($payment instanceof Payment && ($payment->evidence_status === 'verified' || $payment->ledgerEntry instanceof LedgerEntry)) {
                throw new RuntimeException('Posted PayMongo evidence cannot be rejected.');
            }

            $timestamp = CarbonImmutable::now(config('app.timezone'));

            if ($payment instanceof Payment) {
                $payment->forceFill(['evidence_status' => 'rejected', 'verified_at' => null, 'verified_by' => null])->save();
            }

            if ($attempt instanceof PaymentAttempt && in_array($attempt->status, PaymentAttempt::ActiveStatuses, true)) {
                $attempt->forceFill(['status' => PaymentAttempt::StatusFailed])->save();
            }

            $this->markWebhookProcessed($webhookCallId, $timestamp);
            $event->forceFill([
                'status' => OperationalEvent::StatusProcessed,
                'processed_at' => $timestamp,
                'failed_at' => null,
                'user_id' => $actor->id,
                'diagnostics' => [
                    ...($event->diagnostics ?? []),
                    'outcome' => 'processed',
                    'resolution' => [
                        'action' => 'rejected',
                        'actor_id' => $actor->id,
                        'reason' => $reason,
                        'payment_id' => $payment?->id,
                        'decided_at' => $timestamp->toIso8601String(),
                    ],
                ],
            ])->save();
            $this->recordActivity($event, $actor, 'paymongo_evidence_rejected', $reason, $timestamp, [
                'payment_id' => $payment?->id,
                'payment_attempt_id' => $attempt?->id,
            ]);

            return array_filter([
                'status' => 'rejected',
                'event_id' => $event->id,
                'payment_id' => $payment?->id,
            ], fn (mixed $value): bool => $value !== null);
        }, attempts: 3);
    }

    /** @return array{status:string,event_id:int,payment_id:int,reversal_id:int} */
    public function recordRefundReversal(
        int $eventId,
        Payment $payment,
        Payment $reversal,
        string $reason,
        User $actor,
    ): array {
        $this->authorize($actor);
        $reason = $this->normalizedReason($reason);

        return DB::transaction(function () use ($eventId, $payment, $reversal, $reason, $actor): array {
            $event = $this->eventForUpdate($eventId);
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $lockedReversal = Payment::query()->lockForUpdate()->findOrFail($reversal->id);

            if ($event->status === OperationalEvent::StatusProcessed
                && data_get($event->diagnostics, 'resolution.action') === 'reversed'
                && (int) data_get($event->diagnostics, 'resolution.reversal_id') === $lockedReversal->id) {
                return [
                    'status' => 'duplicate',
                    'event_id' => $event->id,
                    'payment_id' => $lockedPayment->id,
                    'reversal_id' => $lockedReversal->id,
                ];
            }

            if ($event->status !== OperationalEvent::StatusReviewRequired
                || ! in_array($this->eventReason($event), self::RefundReasons, true)
                || $event->related_record_type !== Payment::class
                || (int) $event->related_record_id !== $lockedPayment->id
                || $lockedReversal->state !== Payment::StateReversal
                || (int) $lockedReversal->reverses_payment_id !== $lockedPayment->id
                || $lockedReversal->term_account_id !== $lockedPayment->term_account_id) {
                throw new RuntimeException('The PayMongo refund evidence does not match this payment reversal.');
            }

            $timestamp = CarbonImmutable::now(config('app.timezone'));
            [$webhookCallId] = $this->evidenceFor($event);
            $this->markWebhookProcessed($webhookCallId, $timestamp);
            $event->forceFill([
                'status' => OperationalEvent::StatusProcessed,
                'processed_at' => $timestamp,
                'failed_at' => null,
                'user_id' => $actor->id,
                'related_record_type' => Payment::class,
                'related_record_id' => $lockedReversal->id,
                'diagnostics' => [
                    ...($event->diagnostics ?? []),
                    'outcome' => 'processed',
                    'resolution' => [
                        'action' => 'reversed',
                        'actor_id' => $actor->id,
                        'reason' => $reason,
                        'payment_id' => $lockedPayment->id,
                        'reversal_id' => $lockedReversal->id,
                        'decided_at' => $timestamp->toIso8601String(),
                    ],
                ],
            ])->save();
            $this->recordActivity($event, $actor, 'paymongo_refund_reversal_recorded', $reason, $timestamp, [
                'payment_id' => $lockedPayment->id,
                'reversal_id' => $lockedReversal->id,
            ]);

            return [
                'status' => 'reversed',
                'event_id' => $event->id,
                'payment_id' => $lockedPayment->id,
                'reversal_id' => $lockedReversal->id,
            ];
        }, attempts: 3);
    }

    private function authorize(User $actor): void
    {
        if (! $actor->canProcessPayments()) {
            throw new AuthorizationException('Only Accounting/Cashier can reconcile PayMongo evidence.');
        }
    }

    private function normalizedReason(string $reason): string
    {
        $reason = trim($reason);

        if (Str::length($reason) < 5 || Str::length($reason) > 1000) {
            throw new RuntimeException('A reconciliation reason between 5 and 1000 characters is required.');
        }

        return $reason;
    }

    private function eventForUpdate(int $eventId): OperationalEvent
    {
        $event = OperationalEvent::query()->lockForUpdate()->findOrFail($eventId);

        if ($event->event_domain !== OperationalEvent::DomainIntegration
            || $event->integration !== OperationalEvent::IntegrationPayMongo
            || $event->channel !== OperationalEvent::ChannelWebhook) {
            throw new RuntimeException('The selected event is not PayMongo webhook evidence.');
        }

        return $event;
    }

    /**
     * @return array{0:int,1:PayMongoWebhookEvent,2:array<string,mixed>}
     */
    private function evidenceFor(OperationalEvent $event): array
    {
        $webhookCallId = data_get($event->diagnostics, 'latest_webhook_call_id')
            ?? data_get($event->diagnostics, 'webhook_call_id');

        if (! is_int($webhookCallId) && ! (is_string($webhookCallId) && ctype_digit($webhookCallId))) {
            throw new RuntimeException('Persisted PayMongo webhook evidence is unavailable.');
        }

        $webhookCallId = (int) $webhookCallId;
        $webhookCall = DB::table('webhook_calls')
            ->where('id', $webhookCallId)
            ->where('name', 'paymongo')
            ->lockForUpdate()
            ->first();

        if (! $webhookCall instanceof stdClass || ! is_string($webhookCall->payload)) {
            throw new RuntimeException('Persisted PayMongo webhook evidence is unavailable.');
        }

        $webhookEvent = PayMongoWebhookEvent::fromRawBody($webhookCall->payload);
        $storedHash = data_get($event->diagnostics, 'payload_sha256');

        if ($webhookEvent->eventId !== $event->external_id
            || $webhookEvent->eventType !== $event->event_type
            || $webhookEvent->livemode !== (bool) data_get($event->payload, 'livemode')
            || ! is_string($storedHash)
            || preg_match('/\A[a-f0-9]{64}\z/', $storedHash) !== 1) {
            throw new RuntimeException('Persisted PayMongo evidence does not match the canonical event.');
        }

        return [$webhookCallId, $webhookEvent, $webhookEvent->paymentContext()];
    }

    /** @param array<string, mixed> $context */
    private function assertPaidPhpEvidence(OperationalEvent $event, PayMongoWebhookEvent $webhookEvent, array $context): void
    {
        if (! in_array($webhookEvent->eventType, ['checkout_session.payment.paid', 'payment.paid'], true)
            || $webhookEvent->livemode !== (bool) config('tala_integrations.payments.paymongo.livemode', false)
            || $context['status'] !== 'paid'
            || $context['currency'] !== 'PHP'
            || ! is_int($context['amount_centavos'])
            || $context['amount_centavos'] <= 0
            || $context['provider_reference'] === null
            || $context['is_disputed'] === true
            || $context['has_refunds'] === true
            || in_array($this->eventReason($event), self::RefundReasons, true)) {
            throw new RuntimeException('This PayMongo evidence cannot be confirmed as a payment.');
        }
    }

    private function assertAttemptSource(PaymentAttempt $attempt): void
    {
        $assessment = Assessment::query()->lockForUpdate()->findOrFail($attempt->assessment_id);
        $enrollment = Enrollment::query()->lockForUpdate()->find($assessment->enrollment_id);

        if ($attempt->provider !== 'paymongo'
            || $attempt->term_account_id === null
            || $attempt->snapshot_checksum === null
            || $assessment->state !== Assessment::StateActive
            || $assessment->term_account_id !== $attempt->term_account_id
            || (int) $assessment->version !== (int) $attempt->assessment_version
            || ! $enrollment instanceof Enrollment
            || $enrollment->student_profile_id !== $attempt->student_profile_id) {
            throw new RuntimeException('The selected Payment Attempt is not a valid PayMongo source.');
        }
    }

    /** @param array<string, mixed> $context */
    private function assertCompatibleAttempt(PaymentAttempt $attempt, array $context): void
    {
        if ($context['checkout_session_id'] === null
            && $context['payment_intent_id'] === null
            && $context['tala_reference'] === null) {
            throw new RuntimeException('PayMongo evidence has no source identifier that can be linked safely.');
        }

        $this->assertConfirmationIdentifiers($attempt, $context, allowUnassignedCheckout: true);

        $matchedElsewhere = PaymentAttempt::query()
            ->whereKeyNot($attempt->id)
            ->where(function ($query) use ($context): void {
                if ($context['checkout_session_id'] !== null) {
                    $query->orWhere('provider_checkout_id', $context['checkout_session_id']);
                }

                if ($context['payment_intent_id'] !== null) {
                    $query->orWhere('provider_intent_id', $context['payment_intent_id']);
                }

                if ($context['tala_reference'] !== null) {
                    $query->orWhere('internal_reference', $context['tala_reference']);
                }
            })
            ->lockForUpdate()
            ->exists();

        if ($matchedElsewhere) {
            throw new RuntimeException('PayMongo evidence is already compatible with another Payment Attempt.');
        }
    }

    /** @param array<string, mixed> $context */
    private function assertConfirmationIdentifiers(
        PaymentAttempt $attempt,
        array $context,
        bool $allowUnassignedCheckout = false,
        bool $allowReferenceMismatch = false,
    ): void {
        if ($context['checkout_session_id'] !== null
            && $attempt->provider_checkout_id !== null
            && $context['checkout_session_id'] !== $attempt->provider_checkout_id) {
            throw new RuntimeException('PayMongo checkout ownership conflicts with the selected Payment Attempt.');
        }

        if (! $allowUnassignedCheckout
            && $context['checkout_session_id'] !== null
            && $attempt->provider_checkout_id === null) {
            throw new RuntimeException('PayMongo checkout ownership is not linked to the Payment Attempt.');
        }

        if ($context['payment_intent_id'] !== null
            && $attempt->provider_intent_id !== null
            && $context['payment_intent_id'] !== $attempt->provider_intent_id) {
            throw new RuntimeException('PayMongo intent ownership conflicts with the selected Payment Attempt.');
        }

        if ($context['tala_reference'] !== null
            && $context['tala_reference'] !== $attempt->internal_reference
            && ! $allowReferenceMismatch) {
            throw new RuntimeException('PayMongo institutional reference conflicts with the selected Payment Attempt.');
        }
    }

    private function eventReason(OperationalEvent $event): ?string
    {
        $reason = data_get($event->diagnostics, 'reason');

        return is_string($reason) ? $reason : null;
    }

    private function relatedPayment(OperationalEvent $event): Payment
    {
        if ($event->related_record_type !== Payment::class || $event->related_record_id === null) {
            throw new RuntimeException('PayMongo review evidence is not linked to a Payment.');
        }

        return Payment::query()->with('ledgerEntry')->lockForUpdate()->findOrFail($event->related_record_id);
    }

    private function resetWebhookCall(int $webhookCallId, CarbonImmutable $timestamp): void
    {
        DB::table('webhook_calls')->where('id', $webhookCallId)->update([
            'exception' => null,
            'processed_at' => null,
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }

    private function markWebhookProcessed(int $webhookCallId, CarbonImmutable $timestamp): void
    {
        DB::table('webhook_calls')->where('id', $webhookCallId)->update([
            'exception' => null,
            'processed_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function recordActivity(
        OperationalEvent $event,
        User $actor,
        string $action,
        string $reason,
        CarbonImmutable $timestamp,
        array $extra = [],
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'paymongo_reconciliation',
            'description' => str($action)->headline()->toString().'.',
            'subject_type' => OperationalEvent::class,
            'subject_id' => $event->id,
            'event' => $action,
            'causer_type' => User::class,
            'causer_id' => $actor->id,
            'properties' => json_encode([
                'reason' => $reason,
                'external_event_id' => $event->external_id,
                ...$extra,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }
}
