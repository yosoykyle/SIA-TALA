<?php

namespace App\Actions\Integrations\Payments;

use App\Actions\Finance\EnrollmentFinanceClearanceService;
use App\Actions\Finance\PromissoryNoteLifecycleService;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\StudentProfile;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

class PayMongoWebhookProcessor
{
    private const CHECKOUT_PAID = 'checkout_session.payment.paid';

    private const PAYMENT_PAID = 'payment.paid';

    private const PAYMENT_FAILED = 'payment.failed';

    public function __construct(
        private readonly DecimalMoney $money,
        private readonly EnrollmentFinanceClearanceService $financeClearanceService,
        private readonly PromissoryNoteLifecycleService $promissoryNoteLifecycleService,
    ) {}

    /**
     * @return array{status:string, reason?:string, payment_id?:int, ledger_entry_id?:int, finance_cleared?:bool}
     */
    public function process(int $webhookCallId): array
    {
        $webhookCall = DB::table('webhook_calls')->find($webhookCallId);

        if (! $webhookCall instanceof stdClass) {
            throw new RuntimeException('PayMongo webhook call was not found.');
        }

        $payload = $this->decodePayload($webhookCall->payload);
        $context = $this->contextFromPayload($payload);

        if (! in_array($context['event_type'], [self::CHECKOUT_PAID, self::PAYMENT_PAID, self::PAYMENT_FAILED], true)) {
            $this->markProcessed($webhookCallId);

            return ['status' => 'ignored', 'reason' => 'unsupported_event'];
        }

        if ($context['event_id'] === null || $context['provider_reference'] === null) {
            $this->markReviewRequired($webhookCallId, 'missing_provider_reference');

            return ['status' => 'review_required', 'reason' => 'missing_provider_reference'];
        }

        return DB::transaction(function () use ($webhookCallId, $context): array {
            $attempt = $this->findPaymentAttempt($context);

            if (! $attempt instanceof PaymentAttempt) {
                $this->markReviewRequired($webhookCallId, 'unknown_reference');

                return ['status' => 'review_required', 'reason' => 'unknown_reference'];
            }

            if ($context['event_type'] === self::PAYMENT_FAILED) {
                return $this->markAttemptFailed($attempt, $context, $webhookCallId);
            }

            return $this->postSuccessfulPayment($attempt, $context, $webhookCallId);
        }, attempts: 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (! is_string($payload) || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{event_id:?string,event_type:?string,checkout_session_id:?string,payment_id:?string,payment_intent_id:?string,provider_reference:?string,amount_centavos:?int,currency:?string,tala_reference:?string,raw:array<string,mixed>}
     */
    private function contextFromPayload(array $payload): array
    {
        $resource = Arr::get($payload, 'data.attributes.data');
        $resource = is_array($resource) ? $resource : [];
        $resourceAttributes = Arr::get($resource, 'attributes', []);
        $resourceAttributes = is_array($resourceAttributes) ? $resourceAttributes : [];
        $resourceType = Arr::get($resource, 'type');
        $resourceId = Arr::get($resource, 'id');

        $checkoutSessionId = $this->stringOrNull(
            $resourceType === 'checkout_session' ? $resourceId : null
        ) ?? $this->firstString([
            Arr::get($resourceAttributes, 'checkout_session_id'),
            Arr::get($resourceAttributes, 'checkout_session.id'),
            Arr::get($resourceAttributes, 'metadata.checkout_session_id'),
            Arr::get($payload, 'data.attributes.checkout_session_id'),
        ]);

        $paymentId = $this->stringOrNull(
            $resourceType === 'payment' ? $resourceId : null
        ) ?? $this->firstString([
            Arr::get($resourceAttributes, 'payment_id'),
            Arr::get($resourceAttributes, 'payments.0.id'),
            Arr::get($payload, 'data.attributes.payment_id'),
        ]);

        $paymentIntentId = $this->firstString([
            Arr::get($resourceAttributes, 'payment_intent_id'),
            Arr::get($resourceAttributes, 'payment_intent.id'),
            Arr::get($payload, 'data.attributes.payment_intent_id'),
        ]);

        $amountCentavos = $this->integerOrNull(
            Arr::get($resourceAttributes, 'amount_paid')
                ?? Arr::get($resourceAttributes, 'amount')
                ?? Arr::get($resourceAttributes, 'total_amount')
                ?? Arr::get($payload, 'data.attributes.amount')
        );

        $talaReference = $this->firstString([
            Arr::get($resourceAttributes, 'metadata.tala_reference'),
            Arr::get($resourceAttributes, 'metadata.internal_reference'),
            Arr::get($resourceAttributes, 'metadata.reference'),
            Arr::get($payload, 'data.attributes.metadata.tala_reference'),
            Arr::get($payload, 'data.attributes.metadata.internal_reference'),
        ]);

        return [
            'event_id' => $this->firstString([
                Arr::get($payload, 'data.id'),
                Arr::get($payload, 'id'),
            ]),
            'event_type' => $this->firstString([
                Arr::get($payload, 'data.attributes.type'),
                Arr::get($payload, 'type'),
            ]),
            'checkout_session_id' => $checkoutSessionId,
            'payment_id' => $paymentId,
            'payment_intent_id' => $paymentIntentId,
            'provider_reference' => $checkoutSessionId ?? $paymentId ?? $paymentIntentId ?? $talaReference,
            'amount_centavos' => $amountCentavos,
            'currency' => strtoupper((string) ($this->firstString([
                Arr::get($resourceAttributes, 'currency'),
                Arr::get($resourceAttributes, 'payments.0.attributes.currency'),
                Arr::get($payload, 'data.attributes.currency'),
            ]) ?? 'PHP')),
            'tala_reference' => $talaReference,
            'raw' => $payload,
        ];
    }

    /**
     * @param  array{checkout_session_id:?string,payment_intent_id:?string,tala_reference:?string}  $context
     */
    private function findPaymentAttempt(array $context): ?PaymentAttempt
    {
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

    /**
     * @param  array{event_id:string,event_type:string,checkout_session_id:?string,payment_id:?string,payment_intent_id:?string,provider_reference:string,amount_centavos:?int,currency:string,tala_reference:?string,raw:array<string,mixed>}  $context
     * @return array{status:string, reason?:string, payment_id?:int, ledger_entry_id?:int, finance_cleared?:bool}
     */
    private function postSuccessfulPayment(PaymentAttempt $attempt, array $context, int $webhookCallId): array
    {
        $timestamp = CarbonImmutable::now(config('app.timezone'));
        $existingPayment = Payment::query()
            ->with('ledgerEntry')
            ->where('payment_attempt_id', $attempt->id)
            ->where('evidence_status', 'verified')
            ->first();

        if ($existingPayment instanceof Payment && $existingPayment->ledgerEntry instanceof LedgerEntry) {
            $this->markAttemptPaid($attempt, $context, $webhookCallId, $timestamp);
            $this->markProcessed($webhookCallId);

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
            $this->markProcessed($webhookCallId);

            return [
                'status' => 'review_required',
                'reason' => $reviewReason,
                'payment_id' => $payment->id,
            ];
        }

        $assessment = $this->assessmentFor($attempt);
        $enrollment = $assessment->enrollment;
        $studentProfile = $assessment->enrollment?->studentProfile;

        if (! $enrollment instanceof Enrollment || ! $studentProfile instanceof StudentProfile) {
            $payment = $this->recordReviewPaymentEvidence($attempt, $context, $webhookCallId, $timestamp, 'missing_enrollment_source');
            $this->markProcessed($webhookCallId);

            return [
                'status' => 'review_required',
                'reason' => 'missing_enrollment_source',
                'payment_id' => $payment->id,
            ];
        }

        $payment = Payment::query()->updateOrCreate(
            ['payment_attempt_id' => $attempt->id],
            [
                'student_profile_id' => $attempt->student_profile_id,
                'term_id' => $enrollment->term_id,
                'method' => 'paymongo',
                'channel' => $attempt->channel,
                'amount' => $this->money->normalize((string) $attempt->amount),
                'currency' => 'PHP',
                'evidence_status' => 'verified',
                'paid_at' => $timestamp,
                'verified_at' => $timestamp,
                'verified_by' => null,
                'provider_reference' => $this->providerReferenceFor($context),
            ],
        );

        $ledgerEntry = LedgerEntry::query()->firstOrCreate(
            [
                'source_type' => Payment::class,
                'source_id' => $payment->id,
                'direction' => LedgerEntry::DirectionPayment,
            ],
            [
                'student_profile_id' => $attempt->student_profile_id,
                'term_id' => $enrollment->term_id,
                'enrollment_id' => $enrollment->id,
                'category' => 'payment',
                'amount' => $payment->amount,
                'payment_id' => $payment->id,
                'payment_allocation_id' => null,
                'reverses_entry_id' => null,
                'adjusts_entry_id' => null,
                'description' => 'PayMongo webhook-confirmed payment',
                'posted_by' => null,
                'posted_at' => $timestamp,
                'state' => 'posted',
            ],
        );

        $this->markAttemptPaid($attempt, $context, $webhookCallId, $timestamp);

        $newBalance = $this->ledgerBalanceFor($studentProfile);
        $clearance = $this->clearEnrollmentIfEligible($enrollment, $studentProfile, $newBalance, $timestamp);
        $this->markProcessed($webhookCallId);

        return [
            'status' => 'posted',
            'payment_id' => $payment->id,
            'ledger_entry_id' => $ledgerEntry->id,
            'finance_cleared' => $clearance['finance_cleared'],
        ];
    }

    /**
     * @param  array{event_id:string,event_type:string,checkout_session_id:?string,payment_id:?string,payment_intent_id:?string,provider_reference:string,currency:string,tala_reference:?string,raw:array<string,mixed>}  $context
     * @return array{status:string}
     */
    private function markAttemptFailed(PaymentAttempt $attempt, array $context, int $webhookCallId): array
    {
        if ($attempt->status !== 'paid') {
            $attempt->forceFill([
                'status' => 'failed',
                'provider_checkout_id' => $context['checkout_session_id'] ?? $attempt->provider_checkout_id,
                'provider_intent_id' => $context['payment_intent_id'] ?? $attempt->provider_intent_id,
                'metadata' => $this->mergeAttemptMetadata($attempt, $context, $webhookCallId, 'failed'),
            ])->save();
        }

        $this->markProcessed($webhookCallId);

        return ['status' => 'failed'];
    }

    /**
     * @param  array{event_id:string,event_type:string,checkout_session_id:?string,payment_id:?string,payment_intent_id:?string,provider_reference:string,amount_centavos:?int,currency:string,tala_reference:?string,raw:array<string,mixed>}  $context
     */
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
                'currency' => $context['currency'] ?: 'PHP',
                'evidence_status' => 'under_review',
                'paid_at' => $timestamp,
                'verified_at' => null,
                'verified_by' => null,
                'provider_reference' => $this->providerReferenceFor($context),
            ],
        );
    }

    /**
     * @param  array{event_id:string,event_type:string,checkout_session_id:?string,payment_id:?string,payment_intent_id:?string,provider_reference:string,currency:string,tala_reference:?string,raw:array<string,mixed>}  $context
     */
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
        return Assessment::query()
            ->with(['enrollment.studentProfile'])
            ->lockForUpdate()
            ->findOrFail($attempt->assessment_id);
    }

    /**
     * @param  array{amount_centavos:?int,currency:string,tala_reference:?string}  $context
     */
    private function reviewReason(PaymentAttempt $attempt, array $context): ?string
    {
        $attemptAmount = $this->money->normalize((string) $attempt->amount);
        $webhookAmount = $context['amount_centavos'] !== null
            ? $this->money->fromCents($context['amount_centavos'])
            : $attemptAmount;

        if ($context['currency'] !== 'PHP') {
            return 'currency_mismatch';
        }

        if ($this->money->toCents($webhookAmount) !== $this->money->toCents($attemptAmount)) {
            return 'amount_mismatch';
        }

        if ($context['tala_reference'] !== null && $context['tala_reference'] !== $attempt->internal_reference) {
            return 'reference_mismatch';
        }

        return null;
    }

    /**
     * @return array{finance_cleared:bool}
     */
    private function clearEnrollmentIfEligible(
        Enrollment $enrollment,
        StudentProfile $studentProfile,
        string $newBalance,
        CarbonImmutable $timestamp,
    ): array {
        $this->promissoryNoteLifecycleService->settleEligibleForEnrollment(
            enrollment: $enrollment,
            actor: null,
            settledAt: $timestamp,
        );

        $clearance = $this->financeClearanceService->clearIfEligible(
            enrollment: $enrollment,
            studentProfile: $studentProfile,
            currentBalance: $newBalance,
            actor: null,
            timestamp: $timestamp,
        );

        return ['finance_cleared' => $clearance['finance_cleared']];
    }

    private function ledgerBalanceFor(StudentProfile $studentProfile): string
    {
        $entries = LedgerEntry::query()
            ->where('student_profile_id', $studentProfile->id)
            ->where('state', 'posted')
            ->get(['direction', 'amount']);

        $balance = '0.00';

        foreach ($entries as $entry) {
            $amount = (string) $entry->amount;
            $balance = match ($entry->direction) {
                LedgerEntry::DirectionPayment,
                LedgerEntry::DirectionDiscount,
                LedgerEntry::DirectionScholarship,
                LedgerEntry::DirectionWaiver,
                LedgerEntry::DirectionReversal => $this->money->subtract($balance, $amount),
                default => $this->money->add($balance, $amount),
            };
        }

        return $balance;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function mergeAttemptMetadata(PaymentAttempt $attempt, array $context, int $webhookCallId, string $status): array
    {
        $metadata = $attempt->metadata ?? [];
        $metadata = is_array($metadata) ? $metadata : [];
        $metadata['last_webhook'] = [
            'webhook_call_id' => $webhookCallId,
            'event_id' => $context['event_id'],
            'event_type' => $context['event_type'],
            'provider_reference' => $context['provider_reference'],
            'payment_id' => $context['payment_id'] ?? null,
            'status' => $status,
        ];

        return $metadata;
    }

    /**
     * @param  array{provider_reference:string}  $context
     */
    private function providerReferenceFor(array $context): string
    {
        return 'paymongo:'.$context['provider_reference'];
    }

    private function markProcessed(int $webhookCallId): void
    {
        $now = CarbonImmutable::now(config('app.timezone'))->toDateTimeString();

        DB::table('webhook_calls')->where('id', $webhookCallId)->update([
            'processed_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function markReviewRequired(int $webhookCallId, string $reason): void
    {
        $now = CarbonImmutable::now(config('app.timezone'))->toDateTimeString();

        DB::table('webhook_calls')->where('id', $webhookCallId)->update([
            'exception' => 'PayMongo webhook requires Accounting review: '.$reason,
            'processed_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            $string = $this->stringOrNull($value);

            if ($string !== null) {
                return $string;
            }
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    private function integerOrNull(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
