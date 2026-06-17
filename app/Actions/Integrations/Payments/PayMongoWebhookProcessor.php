<?php

namespace App\Actions\Integrations\Payments;

use App\Actions\Finance\EnrollmentFinanceClearanceService;
use App\Actions\Finance\PromissoryNoteLifecycleService;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            $this->markProcessed($webhookCallId);

            return ['status' => 'ignored', 'reason' => 'missing_provider_reference'];
        }

        return DB::transaction(function () use ($webhookCallId, $context): array {
            $attempt = $this->findPaymentAttempt($context);

            if (! $attempt instanceof stdClass) {
                $this->markProcessed($webhookCallId);

                return ['status' => 'ignored', 'reason' => 'unmatched_payment_attempt'];
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
     * @return array{event_id:?string,event_type:?string,checkout_session_id:?string,payment_id:?string,payment_intent_id:?string,provider_reference:?string,amount_centavos:?int,raw:array<string,mixed>}
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
            Arr::get($payload, 'data.attributes.payment_intent_id'),
        ]);

        $amountCentavos = $this->integerOrNull(
            Arr::get($resourceAttributes, 'amount_paid')
                ?? Arr::get($resourceAttributes, 'amount')
                ?? Arr::get($resourceAttributes, 'total_amount')
                ?? Arr::get($payload, 'data.attributes.amount')
        );

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
            'provider_reference' => $checkoutSessionId ?? $paymentId,
            'amount_centavos' => $amountCentavos,
            'raw' => $payload,
        ];
    }

    /**
     * @param  array{checkout_session_id:?string,payment_id:?string,payment_intent_id:?string}  $context
     */
    private function findPaymentAttempt(array $context): ?stdClass
    {
        $query = DB::table('payment_attempts')->lockForUpdate();

        $query->where(function ($query) use ($context): void {
            if ($context['checkout_session_id'] !== null) {
                $query->orWhere('provider_checkout_session_id', $context['checkout_session_id']);
            }

            if ($context['payment_id'] !== null) {
                $query->orWhere('provider_payment_id', $context['payment_id']);
            }

            if ($context['payment_intent_id'] !== null) {
                $query->orWhere('provider_payment_intent_id', $context['payment_intent_id']);
            }
        });

        return $query->first();
    }

    /**
     * @param  array{event_id:string,event_type:string,checkout_session_id:?string,payment_id:?string,payment_intent_id:?string,provider_reference:string,amount_centavos:?int,raw:array<string,mixed>}  $context
     * @return array{status:string, reason?:string, payment_id?:int, ledger_entry_id?:int, finance_cleared?:bool}
     */
    private function postSuccessfulPayment(stdClass $attempt, array $context, int $webhookCallId): array
    {
        $idempotencyKey = $context['event_id'].':'.$context['provider_reference'];
        $paymentReference = 'paymongo:'.$idempotencyKey;
        $timestamp = CarbonImmutable::now(config('app.timezone'));

        if ($attempt->status === 'paid' || DB::table('payments')->where('payment_reference', $paymentReference)->exists()) {
            $this->markProcessed($webhookCallId);

            return ['status' => 'ignored', 'reason' => 'duplicate_webhook'];
        }

        $attemptAmount = $this->money->normalize((string) $attempt->amount);
        $webhookAmount = $context['amount_centavos'] !== null
            ? $this->money->fromCents($context['amount_centavos'])
            : $attemptAmount;

        if ($this->money->toCents($webhookAmount) !== $this->money->toCents($attemptAmount)) {
            throw new RuntimeException('PayMongo webhook amount does not match the payment attempt amount.');
        }

        $studentProfile = DB::table('student_profiles')
            ->where('id', $attempt->student_profile_id)
            ->lockForUpdate()
            ->first();

        if (! $studentProfile instanceof stdClass) {
            throw new RuntimeException('Student profile for payment attempt was not found.');
        }

        $currentBalance = $this->money->normalize((string) $studentProfile->current_balance);
        $ledgerAmount = $this->money->subtract('0.00', $attemptAmount);
        $newBalance = $this->money->add($currentBalance, $ledgerAmount);

        DB::table('payment_attempts')->where('id', $attempt->id)->update([
            'status' => 'paid',
            'provider_event_id' => $context['event_id'],
            'provider_checkout_session_id' => $context['checkout_session_id'] ?? $attempt->provider_checkout_session_id,
            'provider_payment_id' => $context['payment_id'] ?? $attempt->provider_payment_id,
            'provider_payment_intent_id' => $context['payment_intent_id'] ?? $attempt->provider_payment_intent_id,
            'paid_at' => $timestamp->toDateTimeString(),
            'meta' => json_encode($this->mergeAttemptMeta($attempt, $context, $webhookCallId, $idempotencyKey), JSON_UNESCAPED_SLASHES),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);

        $paymentId = (int) DB::table('payments')->insertGetId([
            'student_profile_id' => $attempt->student_profile_id,
            'term_id' => $attempt->term_id,
            'enrollment_id' => $attempt->enrollment_id,
            'payment_attempt_id' => $attempt->id,
            'payment_reference' => $paymentReference,
            'channel' => 'paymongo',
            'amount' => $attemptAmount,
            'status' => 'confirmed',
            'confirmed_at' => $timestamp->toDateTimeString(),
            'confirmed_by' => null,
            'meta' => json_encode([
                'source' => 'paymongo_webhook',
                'webhook_call_id' => $webhookCallId,
                'event_id' => $context['event_id'],
                'event_type' => $context['event_type'],
                'idempotency_key' => $idempotencyKey,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);

        $ledgerEntryId = (int) DB::table('ledger_entries')->insertGetId([
            'student_profile_id' => $attempt->student_profile_id,
            'term_id' => $attempt->term_id,
            'enrollment_id' => $attempt->enrollment_id,
            'entry_type' => 'payment',
            'reference_type' => 'payment',
            'reference_id' => $paymentId,
            'description' => 'PayMongo webhook-confirmed payment',
            'amount' => $ledgerAmount,
            'running_balance' => $newBalance,
            'posted_at' => $timestamp->toDateTimeString(),
            'posted_by' => null,
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);

        DB::table('payments')->where('id', $paymentId)->update([
            'ledger_entry_id' => $ledgerEntryId,
            'updated_at' => $timestamp->toDateTimeString(),
        ]);

        DB::table('payment_attempts')->where('id', $attempt->id)->update([
            'ledger_entry_id' => $ledgerEntryId,
            'updated_at' => $timestamp->toDateTimeString(),
        ]);

        DB::table('student_profiles')->where('id', $attempt->student_profile_id)->update([
            'current_balance' => $newBalance,
            'updated_at' => $timestamp->toDateTimeString(),
        ]);

        $clearance = $this->clearEnrollmentIfEligible($attempt, $newBalance, $timestamp);

        $this->markProcessed($webhookCallId);

        return [
            'status' => 'posted',
            'payment_id' => $paymentId,
            'ledger_entry_id' => $ledgerEntryId,
            'finance_cleared' => $clearance['finance_cleared'],
        ];
    }

    /**
     * @return array{finance_cleared:bool}
     */
    private function clearEnrollmentIfEligible(stdClass $attempt, string $newBalance, CarbonImmutable $timestamp): array
    {
        if ($attempt->enrollment_id === null || ! Schema::hasTable((new Enrollment)->getTable())) {
            return ['finance_cleared' => false];
        }

        $enrollment = Enrollment::query()
            ->with(['studentProfile.user'])
            ->lockForUpdate()
            ->find((int) $attempt->enrollment_id);

        if (! $enrollment instanceof Enrollment) {
            return ['finance_cleared' => false];
        }

        $studentProfile = StudentProfile::query()
            ->lockForUpdate()
            ->find((int) $attempt->student_profile_id);

        if (! $studentProfile instanceof StudentProfile) {
            throw new RuntimeException('Student profile for payment clearance was not found.');
        }

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

    /**
     * @param  array{event_id:string,event_type:string,checkout_session_id:?string,payment_id:?string,payment_intent_id:?string,provider_reference:string,raw:array<string,mixed>}  $context
     * @return array{status:string}
     */
    private function markAttemptFailed(stdClass $attempt, array $context, int $webhookCallId): array
    {
        $timestamp = CarbonImmutable::now(config('app.timezone'))->toDateTimeString();

        if ($attempt->status !== 'paid') {
            DB::table('payment_attempts')->where('id', $attempt->id)->update([
                'status' => 'failed',
                'provider_event_id' => $context['event_id'],
                'provider_checkout_session_id' => $context['checkout_session_id'] ?? $attempt->provider_checkout_session_id,
                'provider_payment_id' => $context['payment_id'] ?? $attempt->provider_payment_id,
                'provider_payment_intent_id' => $context['payment_intent_id'] ?? $attempt->provider_payment_intent_id,
                'meta' => json_encode($this->mergeAttemptMeta($attempt, $context, $webhookCallId, $context['event_id'].':'.$context['provider_reference']), JSON_UNESCAPED_SLASHES),
                'updated_at' => $timestamp,
            ]);
        }

        $this->markProcessed($webhookCallId);

        return ['status' => 'failed'];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function mergeAttemptMeta(stdClass $attempt, array $context, int $webhookCallId, string $idempotencyKey): array
    {
        $meta = $this->decodePayload($attempt->meta ?? null);
        $meta['last_webhook'] = [
            'webhook_call_id' => $webhookCallId,
            'event_id' => $context['event_id'],
            'event_type' => $context['event_type'],
            'idempotency_key' => $idempotencyKey,
        ];

        return $meta;
    }

    private function markProcessed(int $webhookCallId): void
    {
        DB::table('webhook_calls')->where('id', $webhookCallId)->update([
            'processed_at' => CarbonImmutable::now(config('app.timezone'))->toDateTimeString(),
            'updated_at' => CarbonImmutable::now(config('app.timezone'))->toDateTimeString(),
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
