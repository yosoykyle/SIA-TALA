<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests the PayMongo webhook mock contract, verifying that incoming
 * webhook payloads are parsed, verified, and handled appropriately.
 *
 * Steps / Test Cases:
 * 1. test_checkout_session_paid_webhook_posts_payment_once_and_stores_webhook
 * 2. test_duplicate_paid_webhook_is_accepted_without_double_posting
 * 3. test_payment_failed_webhook_marks_attempt_failed_without_ledger_effects
 * 4. test_unknown_webhook_event_is_stored_without_financial_effects
 * 5. test_invalid_signature_is_rejected_when_webhook_secret_is_configured
 * 6. test_valid_signature_is_accepted_when_webhook_secret_is_configured
 */
class PayMongoWebhookMockContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareSchema();
        $this->configureMockWebhook();
    }

    public function test_checkout_session_paid_webhook_posts_payment_once_and_stores_webhook(): void
    {
        $studentProfileId = $this->studentProfileId();
        $attemptId = $this->paymentAttemptId($studentProfileId, [
            'provider_checkout_session_id' => 'cs_test_123',
        ]);

        $response = $this->postJson('/api/webhooks/paymongo', $this->checkoutPaidPayload(
            eventId: 'evt_1',
            checkoutSessionId: 'cs_test_123',
            amountCentavos: 150000,
        ));

        $response->assertAccepted();

        $this->assertDatabaseHas('webhook_calls', [
            'name' => 'paymongo',
        ]);

        $this->assertDatabaseHas('payment_attempts', [
            'id' => $attemptId,
            'status' => 'paid',
            'provider_event_id' => 'evt_1',
            'provider_checkout_session_id' => 'cs_test_123',
        ]);

        $this->assertDatabaseHas('payments', [
            'student_profile_id' => $studentProfileId,
            'payment_attempt_id' => $attemptId,
            'payment_reference' => 'paymongo:evt_1:cs_test_123',
            'channel' => 'paymongo',
            'amount' => '1500.00',
            'status' => 'confirmed',
        ]);

        $paymentId = (int) DB::table('payments')->value('id');

        $this->assertDatabaseHas('ledger_entries', [
            'student_profile_id' => $studentProfileId,
            'entry_type' => 'payment',
            'reference_type' => 'payment',
            'reference_id' => $paymentId,
            'amount' => '-1500.00',
            'running_balance' => '0.00',
        ]);

        $ledgerEntryId = (int) DB::table('ledger_entries')->where('reference_id', $paymentId)->value('id');

        $this->assertDatabaseHas('payment_attempts', [
            'id' => $attemptId,
            'ledger_entry_id' => $ledgerEntryId,
        ]);

        $this->assertSame('0.00', number_format((float) DB::table('student_profiles')->where('id', $studentProfileId)->value('current_balance'), 2, '.', ''));
    }

    public function test_duplicate_paid_webhook_is_accepted_without_double_posting(): void
    {
        $studentProfileId = $this->studentProfileId();
        $this->paymentAttemptId($studentProfileId, [
            'provider_checkout_session_id' => 'cs_test_duplicate',
        ]);

        $payload = $this->checkoutPaidPayload(
            eventId: 'evt_duplicate',
            checkoutSessionId: 'cs_test_duplicate',
            amountCentavos: 150000,
        );

        $this->postJson('/api/webhooks/paymongo', $payload)->assertAccepted();
        $this->postJson('/api/webhooks/paymongo', $payload)->assertAccepted();

        $this->assertSame(2, DB::table('webhook_calls')->count());
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(1, DB::table('ledger_entries')->where('entry_type', 'payment')->count());
        $this->assertSame('0.00', number_format((float) DB::table('student_profiles')->where('id', $studentProfileId)->value('current_balance'), 2, '.', ''));
    }

    public function test_payment_failed_webhook_marks_attempt_failed_without_ledger_effects(): void
    {
        $studentProfileId = $this->studentProfileId();
        $attemptId = $this->paymentAttemptId($studentProfileId, [
            'provider_payment_id' => 'pay_failed_123',
        ]);

        $this->postJson('/api/webhooks/paymongo', $this->paymentPayload(
            eventId: 'evt_failed',
            eventType: 'payment.failed',
            paymentId: 'pay_failed_123',
            amountCentavos: 150000,
        ))->assertAccepted();

        $this->assertDatabaseHas('payment_attempts', [
            'id' => $attemptId,
            'status' => 'failed',
            'provider_event_id' => 'evt_failed',
            'provider_payment_id' => 'pay_failed_123',
        ]);

        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('ledger_entries')->count());
        $this->assertSame('1500.00', number_format((float) DB::table('student_profiles')->where('id', $studentProfileId)->value('current_balance'), 2, '.', ''));
    }

    public function test_unknown_webhook_event_is_stored_without_financial_effects(): void
    {
        $studentProfileId = $this->studentProfileId();
        $this->paymentAttemptId($studentProfileId, [
            'provider_payment_id' => 'pay_refunded_123',
        ]);

        $this->postJson('/api/webhooks/paymongo', $this->paymentPayload(
            eventId: 'evt_refunded',
            eventType: 'payment.refunded',
            paymentId: 'pay_refunded_123',
            amountCentavos: 150000,
        ))->assertAccepted();

        $this->assertSame(1, DB::table('webhook_calls')->count());
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('ledger_entries')->count());
    }

    public function test_invalid_signature_is_rejected_when_webhook_secret_is_configured(): void
    {
        config([
            'paymongo.webhook_signature' => 'test_webhook_secret',
            'tala_integrations.payments.paymongo.webhook_signature' => 'test_webhook_secret',
        ]);

        $this->withHeaders(['paymongo-signature' => 't=123,te=invalid'])
            ->postJson('/api/webhooks/paymongo', $this->checkoutPaidPayload(
                eventId: 'evt_invalid',
                checkoutSessionId: 'cs_invalid',
                amountCentavos: 150000,
            ))
            ->assertUnauthorized();

        $this->assertSame(0, DB::table('webhook_calls')->count());
    }

    public function test_valid_signature_is_accepted_when_webhook_secret_is_configured(): void
    {
        config([
            'paymongo.webhook_signature' => 'test_webhook_secret',
            'tala_integrations.payments.paymongo.webhook_signature' => 'test_webhook_secret',
        ]);

        $studentProfileId = $this->studentProfileId();
        $this->paymentAttemptId($studentProfileId, [
            'provider_checkout_session_id' => 'cs_signed',
        ]);

        $payload = $this->checkoutPaidPayload(
            eventId: 'evt_signed',
            checkoutSessionId: 'cs_signed',
            amountCentavos: 150000,
        );
        $content = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = '1710000000';
        $signature = hash_hmac('sha256', $timestamp.'.'.$content, 'test_webhook_secret');

        $this->call('POST', '/api/webhooks/paymongo', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => "t={$timestamp},te={$signature}",
        ], $content)
            ->assertAccepted();

        $this->assertSame(1, DB::table('payments')->count());
    }

    public function test_paymongo_sandbox_smoke_command_verifies_webhook_confirmed_payment_and_ledger_evidence(): void
    {
        $studentProfileId = $this->studentProfileId();
        $attemptId = $this->paymentAttemptId($studentProfileId, [
            'provider' => 'paymongo',
            'provider_checkout_session_id' => 'cs_smoke_pass',
        ]);

        $this->postJson('/api/webhooks/paymongo', $this->checkoutPaidPayload(
            eventId: 'evt_smoke_pass',
            checkoutSessionId: 'cs_smoke_pass',
            amountCentavos: 150000,
        ))->assertAccepted();

        $exitCode = Artisan::call('integrations:paymongo-sandbox-webhook-smoke', [
            '--attempt-id' => $attemptId,
        ]);
        $output = Artisan::output();

        $this->assertStringContainsString('attempt_paid=PASS', $output);
        $this->assertStringContainsString('single_confirmed_payment=PASS', $output);
        $this->assertStringContainsString('ledger_is_payment_credit=PASS', $output);
        $this->assertStringContainsString('PayMongo sandbox webhook smoke evidence verified.', $output);
        $this->assertSame(0, $exitCode, $output);
    }

    public function test_paymongo_sandbox_smoke_command_can_process_matching_stored_webhook_when_queue_worker_was_not_running(): void
    {
        $studentProfileId = $this->studentProfileId();
        $attemptId = $this->paymentAttemptId($studentProfileId, [
            'provider' => 'paymongo',
            'provider_checkout_session_id' => 'cs_smoke_pending',
        ]);

        DB::table('webhook_calls')->insert([
            'name' => 'paymongo',
            'url' => 'https://tala.test/api/webhooks/paymongo',
            'headers' => json_encode(['paymongo-signature' => ['not-used-in-stored-processing']], JSON_UNESCAPED_SLASHES),
            'payload' => json_encode($this->checkoutPaidPayload(
                eventId: 'evt_smoke_pending',
                checkoutSessionId: 'cs_smoke_pending',
                amountCentavos: 150000,
            ), JSON_UNESCAPED_SLASHES),
            'attachments' => null,
            'exception' => null,
            'processed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertStringContainsString('cs_smoke_pending', (string) DB::table('webhook_calls')->value('payload'));

        $exitCode = Artisan::call('integrations:paymongo-sandbox-webhook-smoke', [
            '--attempt-id' => $attemptId,
            '--process-pending' => true,
        ]);
        $output = Artisan::output();

        $this->assertStringContainsString('processed_webhook_call_id=', $output);
        $this->assertStringContainsString('status=posted', $output);
        $this->assertStringContainsString('PayMongo sandbox webhook smoke evidence verified.', $output);
        $this->assertSame(0, $exitCode, $output);

        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(1, DB::table('ledger_entries')->count());
    }

    public function test_paymongo_sandbox_smoke_command_fails_until_provider_webhook_posts_ledger_evidence(): void
    {
        $studentProfileId = $this->studentProfileId();
        $attemptId = $this->paymentAttemptId($studentProfileId, [
            'provider' => 'paymongo',
            'provider_checkout_session_id' => 'cs_smoke_missing',
        ]);

        $exitCode = Artisan::call('integrations:paymongo-sandbox-webhook-smoke', [
            '--attempt-id' => $attemptId,
        ]);
        $output = Artisan::output();

        $this->assertStringContainsString('attempt_paid=FAIL', $output);
        $this->assertStringContainsString('webhook_call_stored=FAIL', $output);
        $this->assertStringContainsString('PayMongo sandbox webhook smoke evidence is incomplete.', $output);
        $this->assertSame(1, $exitCode, $output);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function paymentAttemptId(int $studentProfileId, array $overrides = []): int
    {
        return (int) DB::table('payment_attempts')->insertGetId(array_merge([
            'student_profile_id' => $studentProfileId,
            'term_id' => 202601,
            'enrollment_id' => 9001,
            'ledger_entry_id' => null,
            'channel' => 'paymongo',
            'status' => 'pending',
            'provider' => 'mock',
            'provider_event_id' => null,
            'provider_checkout_session_id' => null,
            'provider_payment_id' => null,
            'provider_payment_intent_id' => null,
            'webhook_idempotency_key' => null,
            'amount' => '1500.00',
            'meta' => json_encode(['checkout_url' => 'https://mock-payments.test/checkout/cs_test_123'], JSON_UNESCAPED_SLASHES),
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function studentProfileId(): int
    {
        return (int) DB::table('student_profiles')->insertGetId([
            'user_id' => 1,
            'student_id' => 'SIA-2026-0001',
            'year_level' => '1st Year',
            'current_balance' => '1500.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutPaidPayload(string $eventId, string $checkoutSessionId, int $amountCentavos): array
    {
        return [
            'data' => [
                'id' => $eventId,
                'type' => 'event',
                'attributes' => [
                    'type' => 'checkout_session.payment.paid',
                    'data' => [
                        'id' => $checkoutSessionId,
                        'type' => 'checkout_session',
                        'attributes' => [
                            'amount_paid' => $amountCentavos,
                            'currency' => 'PHP',
                            'payment_intent_id' => 'pi_'.$checkoutSessionId,
                            'status' => 'paid',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentPayload(string $eventId, string $eventType, string $paymentId, int $amountCentavos): array
    {
        return [
            'data' => [
                'id' => $eventId,
                'type' => 'event',
                'attributes' => [
                    'type' => $eventType,
                    'data' => [
                        'id' => $paymentId,
                        'type' => 'payment',
                        'attributes' => [
                            'amount' => $amountCentavos,
                            'currency' => 'PHP',
                            'status' => str_contains($eventType, 'failed') ? 'failed' : 'paid',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function configureMockWebhook(): void
    {
        config([
            'tala_integrations.payments.driver' => 'mock',
            'tala_integrations.payments.paymongo.webhook_signature' => null,
            'paymongo.webhook_signature' => null,
            'paymongo.livemode' => false,
            'paymongo.signature_header_name' => 'paymongo-signature',
        ]);
    }

    private function prepareSchema(): void
    {
        foreach (['webhook_calls', 'payments', 'ledger_entries', 'payment_attempts', 'student_profiles'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('student_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('student_id')->unique();
            $table->string('year_level')->nullable();
            $table->decimal('current_balance', 12, 2)->default('0.00');
            $table->timestamps();
        });

        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_profile_id');
            $table->unsignedBigInteger('term_id')->nullable();
            $table->unsignedBigInteger('enrollment_id')->nullable();
            $table->unsignedBigInteger('ledger_entry_id')->nullable();
            $table->string('channel')->index();
            $table->string('status')->default('pending')->index();
            $table->string('provider')->nullable()->index();
            $table->string('provider_event_id')->nullable();
            $table->string('provider_checkout_session_id')->nullable();
            $table->string('provider_payment_id')->nullable();
            $table->string('provider_payment_intent_id')->nullable();
            $table->string('webhook_idempotency_key')->nullable();
            $table->decimal('amount', 12, 2);
            $table->json('meta')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_profile_id');
            $table->unsignedBigInteger('term_id')->nullable();
            $table->unsignedBigInteger('enrollment_id')->nullable();
            $table->string('entry_type')->index();
            $table->string('reference_type')->nullable()->index();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('description')->nullable();
            $table->decimal('amount', 12, 2);
            $table->decimal('running_balance', 12, 2)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_profile_id');
            $table->unsignedBigInteger('term_id')->nullable();
            $table->unsignedBigInteger('enrollment_id')->nullable();
            $table->unsignedBigInteger('payment_attempt_id')->nullable();
            $table->unsignedBigInteger('ledger_entry_id')->nullable();
            $table->string('payment_reference')->nullable()->unique();
            $table->string('channel')->index();
            $table->decimal('amount', 12, 2);
            $table->string('status')->default('confirmed')->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_calls', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('url', 512);
            $table->json('headers')->nullable();
            $table->json('payload')->nullable();
            $table->json('attachments')->nullable();
            $table->text('exception')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }
}
