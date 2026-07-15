<?php

namespace App\Actions\Integrations\Payments;

use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\LedgerEntry;
use App\Models\OperationalEvent;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\StudentProfile;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

class PayMongoWebhookProcessor
{
    private const PaymentFailed = 'payment.failed';

    private const PaymentRefunded = 'payment.refunded';

    private const PaymentRefundUpdated = 'payment.refund.updated';

    public function __construct(
        private readonly DecimalMoney $money,
        private readonly PayMongoPaymentPostingService $paymentPostingService,
    ) {}

    /** @return array{status:string, reason?:string, payment_id?:int, ledger_entry_id?:int, finance_cleared?:bool} */
    public function process(int $webhookCallId, ?int $operationalEventId = null): array
    {
        $webhookCall = DB::table('webhook_calls')->find($webhookCallId);

        if (! $webhookCall instanceof stdClass || ! is_string($webhookCall->payload)) {
            throw new RuntimeException('PayMongo webhook call was not found or has no valid payload.');
        }

        $event = PayMongoWebhookEvent::fromRawBody($webhookCall->payload);
        $context = $event->paymentContext();

        return DB::transaction(function () use ($webhookCallId, $operationalEventId, $event, $context): array {
            $operationalEvent = $this->operationalEventFor($event, $webhookCallId, $operationalEventId);

            if (
                $operationalEvent->status === OperationalEvent::StatusReviewRequired
                && data_get($operationalEvent->diagnostics, 'reason') === 'event_id_payload_conflict'
            ) {
                $this->markCallReviewRequired($webhookCallId, 'event_id_payload_conflict');

                return ['status' => 'review_required', 'reason' => 'event_id_payload_conflict'];
            }

            if ($operationalEvent->status === OperationalEvent::StatusProcessed) {
                return $this->duplicateResult($webhookCallId, $operationalEvent);
            }

            if ($event->livemode !== (bool) config('tala_integrations.payments.paymongo.livemode', false)) {
                $this->markReviewRequired($webhookCallId, $operationalEvent, 'livemode_mismatch');

                return ['status' => 'review_required', 'reason' => 'livemode_mismatch'];
            }

            if (! $event->isSupported()) {
                $this->markIgnored($webhookCallId, $operationalEvent);

                return ['status' => 'ignored', 'reason' => 'unsupported_event'];
            }

            if (in_array($event->eventType, [self::PaymentRefunded, self::PaymentRefundUpdated], true)) {
                return $this->routeRefundToReview($context, $webhookCallId, $operationalEvent);
            }

            if ($context['evidence_reason'] !== null) {
                $this->markReviewRequired($webhookCallId, $operationalEvent, $context['evidence_reason']);

                return ['status' => 'review_required', 'reason' => $context['evidence_reason']];
            }

            if ($context['provider_reference'] === null) {
                $this->markReviewRequired($webhookCallId, $operationalEvent, 'missing_provider_reference');

                return ['status' => 'review_required', 'reason' => 'missing_provider_reference'];
            }

            $attempt = $this->findPaymentAttempt($context, $operationalEvent);

            if (! $attempt instanceof PaymentAttempt) {
                $this->markReviewRequired($webhookCallId, $operationalEvent, 'unknown_reference');

                return ['status' => 'review_required', 'reason' => 'unknown_reference'];
            }

            $assessment = $this->assessmentFor($attempt);
            $sourceReviewReason = $this->sourceReviewReason($attempt, $assessment);

            if ($sourceReviewReason !== null) {
                $this->markReviewRequired(
                    $webhookCallId,
                    $operationalEvent,
                    $sourceReviewReason,
                    PaymentAttempt::class,
                    $attempt->id,
                );

                return ['status' => 'review_required', 'reason' => $sourceReviewReason];
            }

            if ($event->eventType === self::PaymentFailed) {
                return $this->markAttemptFailed($attempt, $context, $webhookCallId, $operationalEvent);
            }

            return $this->postSuccessfulPayment($attempt, $assessment, $context, $webhookCallId, $operationalEvent);
        }, attempts: 3);
    }

    /** @param array{checkout_session_id:?string,payment_intent_id:?string,tala_reference:?string} $context */
    private function findPaymentAttempt(array $context, OperationalEvent $operationalEvent): ?PaymentAttempt
    {
        $linkedAttemptId = data_get($operationalEvent->diagnostics, 'reconciliation.linked_attempt_id');

        if (is_int($linkedAttemptId) || (is_string($linkedAttemptId) && ctype_digit($linkedAttemptId))) {
            $linkedAttempt = PaymentAttempt::query()->lockForUpdate()->find((int) $linkedAttemptId);

            if ($linkedAttempt instanceof PaymentAttempt) {
                return $linkedAttempt;
            }
        }

        if ($context['checkout_session_id'] === null
            && $context['payment_intent_id'] === null
            && $context['tala_reference'] === null) {
            return null;
        }

        return PaymentAttempt::query()
            ->lockForUpdate()
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
            ->first();
    }

    /** @return array{status:string, payment_id?:int, ledger_entry_id?:int, finance_cleared?:bool} */
    private function duplicateResult(int $webhookCallId, OperationalEvent $operationalEvent): array
    {
        $this->markCallProcessed($webhookCallId);

        if ($operationalEvent->related_record_type !== Payment::class || $operationalEvent->related_record_id === null) {
            return ['status' => 'duplicate'];
        }

        $payment = Payment::query()->with('ledgerEntry')->find($operationalEvent->related_record_id);

        if (! $payment instanceof Payment || ! $payment->ledgerEntry instanceof LedgerEntry) {
            return ['status' => 'duplicate'];
        }

        return [
            'status' => 'duplicate',
            'payment_id' => $payment->id,
            'ledger_entry_id' => $payment->ledgerEntry->id,
            'finance_cleared' => false,
        ];
    }

    /**
     * @param  array{payment_id:?string,tala_reference:?string}  $context
     * @return array{status:string, reason:string, payment_id?:int}
     */
    private function routeRefundToReview(array $context, int $webhookCallId, OperationalEvent $operationalEvent): array
    {
        $payment = null;

        if ($context['payment_id'] !== null) {
            $payment = Payment::query()
                ->where('provider_reference', 'paymongo:'.$context['payment_id'])
                ->lockForUpdate()
                ->first();

            if (! $payment instanceof Payment) {
                $attemptId = PaymentAttempt::query()
                    ->where('metadata->last_webhook->payment_id', $context['payment_id'])
                    ->value('id');

                if ($attemptId !== null) {
                    $payment = Payment::query()->where('payment_attempt_id', $attemptId)->lockForUpdate()->first();
                }
            }
        }

        if (! $payment instanceof Payment && $context['tala_reference'] !== null) {
            $attemptId = PaymentAttempt::query()
                ->where('internal_reference', $context['tala_reference'])
                ->value('id');
            $payment = $attemptId !== null
                ? Payment::query()->where('payment_attempt_id', $attemptId)->lockForUpdate()->first()
                : null;
        }

        if (! $payment instanceof Payment) {
            $this->markReviewRequired($webhookCallId, $operationalEvent, 'unknown_refund_payment');

            return ['status' => 'review_required', 'reason' => 'unknown_refund_payment'];
        }

        $this->markReviewRequired($webhookCallId, $operationalEvent, 'refund_or_reversal', Payment::class, $payment->id);

        return ['status' => 'review_required', 'reason' => 'refund_or_reversal', 'payment_id' => $payment->id];
    }

    /**
     * @param  array{event_id:string,event_type:string,checkout_session_id:?string,payment_id:?string,payment_intent_id:?string,provider_reference:string,amount_centavos:?int,currency:?string,tala_reference:?string,status:?string,is_disputed:bool,has_refunds:bool}  $context
     * @return array{status:string, reason?:string, payment_id?:int, ledger_entry_id?:int, finance_cleared?:bool}
     */
    private function postSuccessfulPayment(
        PaymentAttempt $attempt,
        Assessment $assessment,
        array $context,
        int $webhookCallId,
        OperationalEvent $operationalEvent,
    ): array {
        $timestamp = CarbonImmutable::now(config('app.timezone'));
        $existingPayment = Payment::query()
            ->with('ledgerEntry')
            ->where('payment_attempt_id', $attempt->id)
            ->where('evidence_status', 'verified')
            ->lockForUpdate()
            ->first();

        if ($existingPayment instanceof Payment && $existingPayment->ledgerEntry instanceof LedgerEntry) {
            $this->markAttemptPaid($attempt, $context, $webhookCallId, $timestamp);
            $this->markProcessed($webhookCallId, $operationalEvent, Payment::class, $existingPayment->id);

            return [
                'status' => 'duplicate',
                'payment_id' => $existingPayment->id,
                'ledger_entry_id' => $existingPayment->ledgerEntry->id,
                'finance_cleared' => false,
            ];
        }

        $reviewReason = $this->reviewReason($attempt, $context);

        if ($reviewReason !== null) {
            $payment = $this->recordReviewPaymentEvidence($attempt, $context, $webhookCallId, $timestamp, $reviewReason);
            $this->markReviewRequired($webhookCallId, $operationalEvent, $reviewReason, Payment::class, $payment->id);

            return ['status' => 'review_required', 'reason' => $reviewReason, 'payment_id' => $payment->id];
        }

        $enrollment = $assessment->enrollment;
        $studentProfile = $assessment->enrollment?->studentProfile;

        if (! $enrollment instanceof Enrollment || ! $studentProfile instanceof StudentProfile) {
            $payment = $this->recordReviewPaymentEvidence($attempt, $context, $webhookCallId, $timestamp, 'missing_enrollment_source');
            $this->markReviewRequired($webhookCallId, $operationalEvent, 'missing_enrollment_source', Payment::class, $payment->id);

            return ['status' => 'review_required', 'reason' => 'missing_enrollment_source', 'payment_id' => $payment->id];
        }

        $posting = $this->paymentPostingService->post(
            attempt: $attempt,
            amount: $this->money->normalize((string) $attempt->amount),
            providerReference: $this->providerReferenceFor($context),
            actor: null,
            timestamp: $timestamp,
            description: 'PayMongo webhook-confirmed payment',
        );
        $payment = $posting['payment'];
        $ledgerEntry = $posting['ledger_entry'];
        $this->markAttemptPaid($attempt, $context, $webhookCallId, $timestamp);
        $this->markProcessed($webhookCallId, $operationalEvent, Payment::class, $payment->id);

        return [
            'status' => 'posted',
            'payment_id' => $payment->id,
            'ledger_entry_id' => $ledgerEntry->id,
            'finance_cleared' => $posting['finance_cleared'],
        ];
    }

    /**
     * @param  array{event_id:string,event_type:string,checkout_session_id:?string,payment_id:?string,payment_intent_id:?string,provider_reference:string,tala_reference:?string,status:?string}  $context
     * @return array{status:string, reason?:string}
     */
    private function markAttemptFailed(PaymentAttempt $attempt, array $context, int $webhookCallId, OperationalEvent $operationalEvent): array
    {
        if ($attempt->status === 'paid') {
            $this->markReviewRequired($webhookCallId, $operationalEvent, 'failure_after_paid', PaymentAttempt::class, $attempt->id);

            return ['status' => 'review_required', 'reason' => 'failure_after_paid'];
        }

        if ($attempt->status !== 'pending') {
            $this->markReviewRequired(
                $webhookCallId,
                $operationalEvent,
                'failure_attempt_not_pending',
                PaymentAttempt::class,
                $attempt->id,
            );

            return ['status' => 'review_required', 'reason' => 'failure_attempt_not_pending'];
        }

        $identifierReason = $this->identifierReviewReason($attempt, $context);

        if ($identifierReason !== null || $context['status'] !== 'failed') {
            $reason = $identifierReason ?? 'failure_status_mismatch';
            $this->markReviewRequired($webhookCallId, $operationalEvent, $reason, PaymentAttempt::class, $attempt->id);

            return ['status' => 'review_required', 'reason' => $reason];
        }

        $attempt->forceFill([
            'status' => 'pending',
            'provider_checkout_id' => $context['checkout_session_id'] ?? $attempt->provider_checkout_id,
            'provider_intent_id' => $context['payment_intent_id'] ?? $attempt->provider_intent_id,
            'metadata' => $this->mergeAttemptMetadata($attempt, $context, $webhookCallId, 'retryable'),
        ])->save();

        $this->markProcessed($webhookCallId, $operationalEvent, PaymentAttempt::class, $attempt->id);

        return ['status' => 'retryable'];
    }

    /** @param array<string, mixed> $context */
    private function recordReviewPaymentEvidence(
        PaymentAttempt $attempt,
        array $context,
        int $webhookCallId,
        CarbonImmutable $timestamp,
        string $reason,
    ): Payment {
        $assessment = $this->assessmentFor($attempt);
        $enrollment = $assessment->enrollment;
        $reviewAmount = $context['amount_centavos'] !== null
            ? $this->money->fromCents($context['amount_centavos'])
            : $this->money->normalize((string) $attempt->amount);

        $attempt->forceFill([
            'status' => $attempt->status === 'paid' ? 'paid' : 'under_review',
            'provider_checkout_id' => $context['checkout_session_id'] ?? $attempt->provider_checkout_id,
            'provider_intent_id' => $context['payment_intent_id'] ?? $attempt->provider_intent_id,
            'metadata' => $this->mergeAttemptMetadata($attempt, $context, $webhookCallId, $reason),
        ])->save();

        return Payment::query()->updateOrCreate(
            ['payment_attempt_id' => $attempt->id],
            [
                'student_profile_id' => $attempt->student_profile_id,
                'term_id' => $enrollment?->term_id ?? $assessment->enrollment?->term_id,
                'method' => 'paymongo',
                'channel' => $attempt->channel,
                'amount' => $reviewAmount,
                'currency' => $context['currency'] ?? (string) $attempt->currency,
                'evidence_status' => 'under_review',
                'paid_at' => $timestamp,
                'verified_at' => null,
                'verified_by' => null,
                'provider_reference' => $this->providerReferenceFor($context),
            ],
        );
    }

    /** @param array<string, mixed> $context */
    private function markAttemptPaid(PaymentAttempt $attempt, array $context, int $webhookCallId, CarbonImmutable $timestamp): void
    {
        $attempt->forceFill([
            'status' => 'paid',
            'provider_checkout_id' => $context['checkout_session_id'] ?? $attempt->provider_checkout_id,
            'provider_intent_id' => $context['payment_intent_id'] ?? $attempt->provider_intent_id,
            'paid_at' => $timestamp,
            'metadata' => $this->mergeAttemptMetadata($attempt, $context, $webhookCallId, 'posted'),
        ])->save();
    }

    private function assessmentFor(PaymentAttempt $attempt): Assessment
    {
        $assessment = Assessment::query()
            ->lockForUpdate()
            ->findOrFail($attempt->assessment_id);
        $enrollment = Enrollment::query()
            ->with('studentProfile')
            ->lockForUpdate()
            ->find($assessment->enrollment_id);

        return $assessment->setRelation('enrollment', $enrollment);
    }

    private function sourceReviewReason(PaymentAttempt $attempt, Assessment $assessment): ?string
    {
        if ($attempt->provider !== 'paymongo') {
            return 'payment_attempt_provider_mismatch';
        }

        if ($assessment->state !== Assessment::StateActive) {
            return 'assessment_not_active';
        }

        $enrollment = $assessment->enrollment;

        if (! $enrollment instanceof Enrollment || $enrollment->student_profile_id !== $attempt->student_profile_id) {
            return 'payment_attempt_ownership_mismatch';
        }

        return null;
    }

    /** @param array<string, mixed> $context */
    private function reviewReason(PaymentAttempt $attempt, array $context): ?string
    {
        if ($context['status'] === null) {
            return 'missing_payment_status';
        }

        if ($context['status'] !== 'paid') {
            return 'payment_status_mismatch';
        }

        if ($context['currency'] === null) {
            return 'missing_currency';
        }

        if ($context['currency'] !== 'PHP') {
            return 'currency_mismatch';
        }

        if ($context['amount_centavos'] === null) {
            return 'missing_amount';
        }

        if ($this->money->toCents($this->money->fromCents($context['amount_centavos'])) !== $this->money->toCents((string) $attempt->amount)) {
            return 'amount_mismatch';
        }

        if ($context['tala_reference'] === null) {
            return 'missing_tala_reference';
        }

        $identifierReason = $this->identifierReviewReason($attempt, $context);

        if ($identifierReason !== null) {
            return $identifierReason;
        }

        if ($context['is_disputed']) {
            return 'payment_disputed';
        }

        if ($context['has_refunds']) {
            return 'refund_present';
        }

        return null;
    }

    /** @param array<string, mixed> $context */
    private function identifierReviewReason(PaymentAttempt $attempt, array $context): ?string
    {
        if ($context['tala_reference'] !== null && $context['tala_reference'] !== $attempt->internal_reference) {
            return 'reference_mismatch';
        }

        if (
            $context['checkout_session_id'] !== null && $attempt->provider_checkout_id !== null
            && $context['checkout_session_id'] !== $attempt->provider_checkout_id
        ) {
            return 'checkout_session_mismatch';
        }

        if (
            $context['payment_intent_id'] !== null && $attempt->provider_intent_id !== null
            && $context['payment_intent_id'] !== $attempt->provider_intent_id
        ) {
            return 'payment_intent_mismatch';
        }

        return null;
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function mergeAttemptMetadata(PaymentAttempt $attempt, array $context, int $webhookCallId, string $status): array
    {
        $storedMetadata = $attempt->getAttribute('metadata');
        $metadata = is_array($storedMetadata) ? $storedMetadata : [];
        $metadata['last_webhook'] = [
            'webhook_call_id' => $webhookCallId,
            'event_id' => $context['event_id'],
            'event_type' => $context['event_type'],
            'provider_reference' => $context['provider_reference'],
            'payment_id' => $context['payment_id'],
            'status' => $status,
            'provider_status' => $context['status'],
        ];

        return $metadata;
    }

    /** @param array{provider_reference:string} $context */
    private function providerReferenceFor(array $context): string
    {
        return 'paymongo:'.$context['provider_reference'];
    }

    private function operationalEventFor(
        PayMongoWebhookEvent $event,
        int $webhookCallId,
        ?int $operationalEventId,
    ): OperationalEvent {
        $operationalEvent = $operationalEventId !== null
            ? OperationalEvent::query()->lockForUpdate()->find($operationalEventId)
            : OperationalEvent::query()
                ->where('event_domain', OperationalEvent::DomainIntegration)
                ->where('external_id', $event->eventId)
                ->lockForUpdate()
                ->first();

        if ($operationalEvent instanceof OperationalEvent) {
            if ($operationalEvent->external_id !== $event->eventId) {
                throw new RuntimeException('PayMongo operational event identity does not match the webhook.');
            }

            return $operationalEvent;
        }

        return OperationalEvent::query()->create([
            'event_domain' => OperationalEvent::DomainIntegration,
            'integration' => OperationalEvent::IntegrationPayMongo,
            'channel' => OperationalEvent::ChannelWebhook,
            'direction' => OperationalEvent::DirectionInbound,
            'event_type' => $event->eventType,
            'event_version' => $event->envelopeVersion,
            'external_id' => $event->eventId,
            'status' => OperationalEvent::StatusPending,
            'occurred_at' => CarbonImmutable::now(config('app.timezone')),
            'diagnostics' => [
                'payload_sha256' => $event->payloadSha256,
                'semantic_fingerprint' => $event->semanticFingerprint(),
                'webhook_call_id' => $webhookCallId,
                'delivery_count' => 1,
            ],
            'payload' => $event->summary(),
        ]);
    }

    private function markProcessed(
        int $webhookCallId,
        OperationalEvent $operationalEvent,
        ?string $relatedRecordType = null,
        ?int $relatedRecordId = null,
    ): void {
        $now = CarbonImmutable::now(config('app.timezone'));
        $this->markCallProcessed($webhookCallId, $now);
        $operationalEvent->forceFill([
            'status' => OperationalEvent::StatusProcessed,
            'processed_at' => $now,
            'failed_at' => null,
            'related_record_type' => $relatedRecordType,
            'related_record_id' => $relatedRecordId,
            'diagnostics' => [
                ...($operationalEvent->diagnostics ?? []),
                'outcome' => 'processed',
                'latest_webhook_call_id' => $webhookCallId,
            ],
        ])->save();
    }

    private function markReviewRequired(
        int $webhookCallId,
        OperationalEvent $operationalEvent,
        string $reason,
        ?string $relatedRecordType = null,
        ?int $relatedRecordId = null,
    ): void {
        $now = CarbonImmutable::now(config('app.timezone'));
        $this->markCallReviewRequired($webhookCallId, $reason, $now);
        $operationalEvent->forceFill([
            'status' => OperationalEvent::StatusReviewRequired,
            'processed_at' => $now,
            'failed_at' => null,
            'related_record_type' => $relatedRecordType,
            'related_record_id' => $relatedRecordId,
            'diagnostics' => [
                ...($operationalEvent->diagnostics ?? []),
                'reason' => $reason,
                'latest_webhook_call_id' => $webhookCallId,
            ],
        ])->save();
    }

    private function markIgnored(int $webhookCallId, OperationalEvent $operationalEvent): void
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        $this->markCallProcessed($webhookCallId, $now);
        $operationalEvent->forceFill([
            'status' => OperationalEvent::StatusIgnored,
            'processed_at' => $now,
            'diagnostics' => [
                ...($operationalEvent->diagnostics ?? []),
                'outcome' => 'ignored',
                'latest_webhook_call_id' => $webhookCallId,
            ],
        ])->save();
    }

    private function markCallProcessed(int $webhookCallId, ?CarbonImmutable $now = null): void
    {
        $timestamp = ($now ?? CarbonImmutable::now(config('app.timezone')))->toDateTimeString();

        DB::table('webhook_calls')->where('id', $webhookCallId)->update([
            'exception' => null,
            'processed_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    private function markCallReviewRequired(int $webhookCallId, string $reason, ?CarbonImmutable $now = null): void
    {
        $timestamp = ($now ?? CarbonImmutable::now(config('app.timezone')))->toDateTimeString();

        DB::table('webhook_calls')->where('id', $webhookCallId)->update([
            'exception' => 'review_required:'.$reason,
            'processed_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }
}
