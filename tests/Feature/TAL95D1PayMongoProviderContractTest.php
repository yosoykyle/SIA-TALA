<?php

namespace Tests\Feature;

use App\Actions\Integrations\Payments\PaymentCheckoutRequest;
use App\Actions\Integrations\Payments\PaymentCheckoutSession;
use App\Actions\Integrations\Payments\PaymentGateway;
use App\Actions\Integrations\Payments\PaymentGatewayException;
use App\Actions\Integrations\Payments\PayMongoPaymentGateway;
use App\Actions\Integrations\Payments\PayMongoSandboxEnvironmentGuard;
use App\Actions\Integrations\Payments\PayMongoWebhookEvent;
use App\Actions\Integrations\Payments\PayMongoWebhookProcessor;
use App\Actions\Integrations\Payments\PayMongoWebhookSignatureVerifier;
use App\Jobs\ProcessPayMongoWebhookCall;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\LedgerEntry;
use App\Models\OperationalEvent;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentScheduleRow;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL95D1PayMongoProviderContractTest extends TestCase
{
    use DatabaseTransactions;

    private const WebhookSecret = 'whsec_tal95d1_not_real';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());

        config()->set('tala_integrations.payments.driver', 'paymongo');
        config()->set('tala_integrations.payments.paymongo.webhook_signature', self::WebhookSecret);
        config()->set('tala_integrations.payments.paymongo.livemode', false);
        config()->set('tala_integrations.payments.paymongo.signature_max_age_seconds', 300);
        CarbonImmutable::setTestNow('2026-07-15 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_current_v2_and_legacy_envelopes_normalize_to_the_same_financial_context(): void
    {
        $v2 = PayMongoWebhookEvent::fromRawBody($this->encode($this->v2PaidPayload()));
        $legacy = PayMongoWebhookEvent::fromRawBody($this->encode($this->legacyPaidPayload()));

        $this->assertSame('v2', $v2->envelopeVersion);
        $this->assertSame('v1', $legacy->envelopeVersion);
        $this->assertSame('checkout_session.payment.paid', $v2->eventType);
        $this->assertSame('checkout_session.payment.paid', $legacy->eventType);
        $this->assertSame([
            'checkout_session_id' => 'cs_tal95d1',
            'payment_id' => 'pay_tal95d1',
            'payment_intent_id' => 'pi_tal95d1',
            'provider_reference' => 'pay_tal95d1',
            'amount_centavos' => 200000,
            'currency' => 'PHP',
            'tala_reference' => 'TALA-PAY-TAL95D1',
            'status' => 'paid',
        ], $this->financialContext($v2));
        $this->assertSame([
            ...$this->financialContext($v2),
            'provider_reference' => 'cs_tal95d1',
        ], $this->financialContext($legacy));
    }

    public function test_v2_without_a_provider_event_id_has_stable_semantic_identity_and_fingerprint(): void
    {
        $firstPayload = $this->v2PaidPayload();
        $reorderedPayload = [
            'data' => [
                'data' => data_get($firstPayload, 'data.data'),
                'livemode' => false,
                'resource' => 'checkout_session',
                'type' => 'checkout_session.payment.paid',
            ],
            'event_type' => 'send.webhook',
        ];

        $first = PayMongoWebhookEvent::fromRawBody($this->encode($firstPayload));
        $reordered = PayMongoWebhookEvent::fromRawBody($this->encode($reorderedPayload));

        $this->assertStringStartsWith('paymongo:v2:', $first->eventId);
        $this->assertSame($first->eventId, $reordered->eventId);
        $this->assertSame($first->semanticFingerprint(), $reordered->semanticFingerprint());
        $this->assertNotSame($first->payloadSha256, $reordered->payloadSha256);
    }

    public function test_signature_timestamp_must_be_within_the_configured_replay_window(): void
    {
        $body = $this->encode($this->v2PaidPayload());
        $now = CarbonImmutable::now()->getTimestamp();
        $verifier = app(PayMongoWebhookSignatureVerifier::class);

        $this->assertTrue($verifier->isValid($this->signedRequest($body, $now)));
        $this->assertFalse($verifier->isValid($this->signedRequest($body, $now - 301)));
        $this->assertFalse($verifier->isValid($this->signedRequest($body, $now + 1)));
        $this->assertFalse($verifier->isValid($this->signedRequest($body, $now, 'li')));
        $this->assertFalse($verifier->isValid(Request::create('/api/webhooks/paymongo', 'POST', content: $body)));
        $this->assertFalse($verifier->isValid(Request::create('/api/webhooks/paymongo', 'POST', server: [
            'HTTP_PAYMONGO_SIGNATURE' => 't='.$now.',te='.str_repeat('0', 64),
        ], content: $body)));
    }

    public function test_gateway_retries_a_transient_response(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push(['errors' => [['code' => 'rate_limited']]], 429)
            ->push($this->checkoutSessionResponse(), 200);

        $session = $this->payMongoGateway()->createCheckoutSession(
            $this->checkoutRequest(),
            'TALA-PAY-TAL95D1-HTTP',
        );

        $this->assertSame('cs_tal95d1_http', $session->checkoutSessionId);
        Http::assertSentCount(2);
    }

    public function test_gateway_does_not_retry_a_business_rejection(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.paymongo.com/v2/checkout_sessions' => Http::response([
                'errors' => [['code' => 'parameter_invalid']],
            ], 422),
        ]);

        try {
            $this->payMongoGateway()->createCheckoutSession($this->checkoutRequest(), 'TALA-PAY-TAL95D1-REJECTED');
            $this->fail('The non-retryable provider rejection should have escaped.');
        } catch (PaymentGatewayException $exception) {
            $this->assertSame('parameter_invalid', $exception->errorCode);
            $this->assertFalse($exception->retryable);
            $this->assertFalse($exception->indeterminate);
            $this->assertSame(422, $exception->httpStatus);
        }

        Http::assertSentCount(1);
    }

    public function test_reordered_v2_redelivery_is_a_duplicate_but_a_financial_conflict_routes_to_review(): void
    {
        Queue::fake();
        $first = $this->v2PaidPayload();
        $reordered = [
            'data' => [
                'data' => data_get($first, 'data.data'),
                'livemode' => false,
                'resource' => 'checkout_session',
                'type' => 'checkout_session.payment.paid',
            ],
            'event_type' => 'send.webhook',
        ];
        $conflict = $this->v2PaidPayload(amountCentavos: 200001);

        $this->postSignedPayload($first)->assertAccepted()->assertJsonPath('status', 'accepted');
        $this->postSignedPayload($reordered)->assertAccepted()->assertJsonPath('status', 'duplicate');
        $this->postSignedPayload($conflict)->assertAccepted()->assertJsonPath('status', 'review_required');

        $event = OperationalEvent::query()->sole();
        $this->assertSame('v2', $event->event_version);
        $this->assertSame('event_id_payload_conflict', data_get($event->diagnostics, 'reason'));
        $this->assertSame(3, data_get($event->diagnostics, 'delivery_count'));
        $this->assertSame(64, strlen((string) data_get($event->diagnostics, 'semantic_fingerprint')));
        $this->assertSame(64, strlen((string) data_get($event->diagnostics, 'payload_sha256')));
        Queue::assertPushed(ProcessPayMongoWebhookCall::class, 1);
    }

    public function test_ambiguous_v2_paid_evidence_routes_to_review_without_financial_mutation(): void
    {
        $queue = Queue::fake();
        $payload = $this->v2PaidPayload();
        data_set($payload, 'data.data.attributes.payments.1', [
            'id' => 'pay_tal95d1_second',
            'attributes' => [
                'amount' => 200000,
                'currency' => 'PHP',
                'status' => 'paid',
            ],
        ]);

        $this->postSignedPayload($payload)->assertAccepted()->assertJsonPath('status', 'accepted');

        /** @var ProcessPayMongoWebhookCall $job */
        $job = $queue->pushed(ProcessPayMongoWebhookCall::class)->sole();
        $result = app(PayMongoWebhookProcessor::class)->process($job->webhookCallId, $job->operationalEventId);

        $this->assertSame('review_required', $result['status']);
        $this->assertSame('ambiguous_paid_payments', $result['reason']);
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, LedgerEntry::query()->where('direction', LedgerEntry::DirectionPayment)->count());
        $this->assertSame(OperationalEvent::StatusReviewRequired, OperationalEvent::query()->sole()->status);
    }

    public function test_retryable_decline_remains_pending_and_a_later_v2_payment_posts_once(): void
    {
        $attempt = $this->paymentAttemptFixture();
        $processor = app(PayMongoWebhookProcessor::class);
        $decline = $processor->process($this->storeWebhookCall($this->legacyFailurePayload($attempt)));

        $this->assertSame('retryable', $decline['status']);
        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertSame('failed', data_get($attempt->fresh()->metadata, 'last_webhook.provider_status'));
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, LedgerEntry::query()->where('direction', LedgerEntry::DirectionPayment)->count());

        $paidPayload = $this->v2PaidPayload(
            checkoutSessionId: (string) $attempt->provider_checkout_id,
            paymentId: 'pay_tal95d1_retry',
            amountCentavos: 100000,
            reference: (string) $attempt->internal_reference,
        );
        $paid = $processor->process($this->storeWebhookCall($paidPayload));
        $duplicate = $processor->process($this->storeWebhookCall($paidPayload));

        $this->assertSame('posted', $paid['status']);
        $this->assertSame('duplicate', $duplicate['status']);
        $this->assertSame('paid', $attempt->fresh()->status);
        $this->assertSame('paymongo:pay_tal95d1_retry', Payment::query()->sole()->provider_reference);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, LedgerEntry::query()->where('direction', LedgerEntry::DirectionPayment)->count());
    }

    public function test_repaired_smoke_command_proves_current_payment_ledger_and_delivery_evidence(): void
    {
        $attempt = $this->paymentAttemptFixture();
        $paidPayload = $this->v2PaidPayload(
            checkoutSessionId: (string) $attempt->provider_checkout_id,
            paymentId: 'pay_tal95d1_smoke',
            amountCentavos: 100000,
            reference: (string) $attempt->internal_reference,
        );
        app(PayMongoWebhookProcessor::class)->process($this->storeWebhookCall($paidPayload));
        $this->configureSafeSandbox();

        $first = Artisan::call('integrations:paymongo-sandbox-webhook-smoke', [
            '--attempt-id' => $attempt->id,
        ]);
        $firstOutput = Artisan::output();
        $second = Artisan::call('integrations:paymongo-sandbox-webhook-smoke', [
            '--attempt-id' => $attempt->id,
        ]);

        $this->assertSame(Command::SUCCESS, $first);
        $this->assertSame(Command::SUCCESS, $second);
        $this->assertStringContainsString('single_verified_payment=PASS', $firstOutput);
        $this->assertStringContainsString('ledger_entry_linked=PASS', $firstOutput);
        $this->assertStringContainsString('processed_provider_event=PASS', $firstOutput);
        $this->assertStringContainsString('finance_gate_effect=PASS', $firstOutput);
        $this->assertStringContainsString('notification_evidence=PASS', $firstOutput);
    }

    public function test_shared_sandbox_guard_fails_closed_on_unsafe_configuration(): void
    {
        $this->configureSafeSandbox();
        $guard = app(PayMongoSandboxEnvironmentGuard::class);

        $guard->assertSafe(['secret_key', 'webhook_signature']);

        config()->set('tala_integrations.payments.paymongo.livemode', true);
        $this->assertSandboxGuardRejects($guard, 'PayMongo live mode is not allowed');

        config()->set('tala_integrations.payments.paymongo.livemode', false);
        config()->set('tala_integrations.payments.driver', 'mock');
        $this->assertSandboxGuardRejects($guard, 'PayMongo payment driver is required');

        config()->set('tala_integrations.payments.driver', 'paymongo');
        config()->set('tala_integrations.payments.paymongo.secret_key');
        $this->assertSandboxGuardRejects($guard, 'required PayMongo sandbox configuration is missing');
    }

    public function test_guarded_sandbox_commands_create_reuse_and_expire_one_attempt(): void
    {
        $this->configureSafeSandbox();
        $fixture = $this->sandboxCheckoutFixture();
        $gateway = new TAL95D1RecordingPaymentGateway;
        $this->app->instance(PaymentGateway::class, $gateway);

        $firstCreate = Artisan::call('integrations:paymongo-sandbox-checkout', [
            '--assessment-id' => $fixture['assessment']->id,
        ]);
        $secondCreate = Artisan::call('integrations:paymongo-sandbox-checkout', [
            '--assessment-id' => $fixture['assessment']->id,
        ]);
        $attempt = PaymentAttempt::query()->sole();

        $this->assertSame(Command::SUCCESS, $firstCreate);
        $this->assertSame(Command::SUCCESS, $secondCreate);
        $this->assertSame(1, $gateway->createCalls);
        $this->assertSame('pending', $attempt->status);
        $this->assertSame('cs_tal95d1_command', $attempt->provider_checkout_id);

        $firstExpire = Artisan::call('integrations:paymongo-sandbox-expire', [
            '--attempt-id' => $attempt->id,
        ]);
        $secondExpire = Artisan::call('integrations:paymongo-sandbox-expire', [
            '--attempt-id' => $attempt->id,
        ]);

        $this->assertSame(Command::SUCCESS, $firstExpire);
        $this->assertSame(Command::SUCCESS, $secondExpire);
        $this->assertSame(1, $gateway->expireCalls);
        $this->assertSame('expired', $attempt->fresh()->status);
        $this->assertSame(1, PaymentAttempt::query()->count());
    }

    public function test_sandbox_expiry_keeps_the_attempt_pending_when_the_provider_does_not_confirm_expiry(): void
    {
        $this->configureSafeSandbox();
        $fixture = $this->sandboxCheckoutFixture();
        $gateway = new TAL95D1RecordingPaymentGateway;
        $gateway->expireStatus = 'active';
        $this->app->instance(PaymentGateway::class, $gateway);
        Artisan::call('integrations:paymongo-sandbox-checkout', [
            '--assessment-id' => $fixture['assessment']->id,
        ]);
        $attempt = PaymentAttempt::query()->sole();

        $exitCode = Artisan::call('integrations:paymongo-sandbox-expire', [
            '--attempt-id' => $attempt->id,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertSame(1, $gateway->expireCalls);
        $this->assertStringNotContainsString('sk_test_', Artisan::output());
    }

    /** @return array<string, mixed> */
    private function v2PaidPayload(
        string $checkoutSessionId = 'cs_tal95d1',
        string $paymentId = 'pay_tal95d1',
        int $amountCentavos = 200000,
        string $reference = 'TALA-PAY-TAL95D1',
    ): array {
        return [
            'event_type' => 'send.webhook',
            'data' => [
                'type' => 'checkout_session.payment.paid',
                'resource' => 'checkout_session',
                'livemode' => false,
                'data' => [
                    'id' => $checkoutSessionId,
                    'type' => 'checkout_session',
                    'attributes' => [
                        'reference_number' => $reference,
                        'metadata' => ['tala_reference' => $reference],
                        'payment_intent' => ['id' => 'pi_tal95d1'],
                        'payments' => [
                            [
                                'id' => $paymentId,
                                'attributes' => [
                                    'amount' => $amountCentavos,
                                    'currency' => 'PHP',
                                    'status' => 'paid',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function legacyPaidPayload(): array
    {
        return [
            'data' => [
                'id' => 'evt_tal95d1_legacy',
                'type' => 'event',
                'attributes' => [
                    'type' => 'checkout_session.payment.paid',
                    'livemode' => false,
                    'data' => [
                        'id' => 'cs_tal95d1',
                        'type' => 'checkout_session',
                        'attributes' => [
                            'status' => 'paid',
                            'amount_paid' => 200000,
                            'currency' => 'PHP',
                            'payment_id' => 'pay_tal95d1',
                            'payment_intent_id' => 'pi_tal95d1',
                            'metadata' => ['tala_reference' => 'TALA-PAY-TAL95D1'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array{checkout_session_id:?string,payment_id:?string,payment_intent_id:?string,provider_reference:?string,amount_centavos:?int,currency:?string,tala_reference:?string,status:?string}
     */
    private function financialContext(PayMongoWebhookEvent $event): array
    {
        return array_intersect_key($event->paymentContext(), array_flip([
            'checkout_session_id',
            'payment_id',
            'payment_intent_id',
            'provider_reference',
            'amount_centavos',
            'currency',
            'tala_reference',
            'status',
        ]));
    }

    private function signedRequest(string $body, int $timestamp, string $mode = 'te'): Request
    {
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, self::WebhookSecret);

        return Request::create('/api/webhooks/paymongo', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => 't='.$timestamp.','.$mode.'='.$signature,
        ], content: $body);
    }

    /** @param array<string, mixed> $payload */
    private function postSignedPayload(array $payload): TestResponse
    {
        $body = $this->encode($payload);
        $timestamp = CarbonImmutable::now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, self::WebhookSecret);

        return $this->call('POST', '/api/webhooks/paymongo', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => 't='.$timestamp.',te='.$signature,
        ], $body);
    }

    private function paymentAttemptFixture(): PaymentAttempt
    {
        $student = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $program = Program::factory()->create();
        $profile = StudentProfile::factory()->for($student)->for($program)->create();
        $term = Term::factory()->create();
        $enrollment = Enrollment::factory()->for($profile)->for($term)->create([
            'status' => 'pending_payment',
            'registered_at' => now()->subDay(),
        ]);
        $assessment = Assessment::query()->create([
            'enrollment_id' => $enrollment->id,
            'version' => 1,
            'state' => Assessment::StateActive,
            'currency' => 'PHP',
            'subtotal' => '9000.00',
            'discount_total' => '0.00',
            'total' => '9000.00',
            'required_downpayment' => '1000.00',
            'activated_at' => now(),
        ]);
        LedgerEntry::query()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'direction' => LedgerEntry::DirectionCharge,
            'category' => 'tuition',
            'amount' => '9000.00',
            'source_type' => Assessment::class,
            'source_id' => $assessment->id,
            'description' => 'TAL-95D1 provider contract fixture',
            'posted_at' => now(),
            'state' => 'posted',
        ]);

        return PaymentAttempt::query()->create([
            'assessment_id' => $assessment->id,
            'student_profile_id' => $profile->id,
            'channel' => 'paymongo',
            'provider' => 'paymongo',
            'internal_reference' => 'TALA-PAY-'.Str::upper((string) Str::uuid()),
            'provider_checkout_id' => 'cs_tal95d1_retry',
            'amount' => '1000.00',
            'currency' => 'PHP',
            'status' => 'pending',
            'metadata' => [],
        ]);
    }

    /** @return array{student:User,assessment:Assessment} */
    private function sandboxCheckoutFixture(): array
    {
        Role::query()->firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $student = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $student->assignRole('student');
        $program = Program::factory()->create();
        $profile = StudentProfile::factory()->for($student)->for($program)->create();
        $term = Term::factory()->create();
        $enrollment = Enrollment::factory()->for($profile)->for($term)->create([
            'status' => 'pending_payment',
            'registered_at' => now()->subDay(),
        ]);
        $assessment = Assessment::query()->create([
            'enrollment_id' => $enrollment->id,
            'version' => 1,
            'state' => Assessment::StateActive,
            'currency' => 'PHP',
            'subtotal' => '9000.00',
            'discount_total' => '0.00',
            'total' => '9000.00',
            'required_downpayment' => '2000.00',
            'activated_at' => now(),
        ]);
        PaymentScheduleRow::query()->create([
            'assessment_id' => $assessment->id,
            'sequence' => 1,
            'category' => PaymentScheduleRow::CategoryDownpayment,
            'due_date' => now()->addWeek()->toDateString(),
            'amount' => '2000.00',
            'state' => PaymentScheduleRow::StateDue,
        ]);
        LedgerEntry::query()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'direction' => LedgerEntry::DirectionCharge,
            'category' => 'tuition',
            'amount' => '9000.00',
            'source_type' => Assessment::class,
            'source_id' => $assessment->id,
            'description' => 'TAL-95D1 sandbox command fixture',
            'posted_at' => now(),
            'state' => 'posted',
        ]);

        return compact('student', 'assessment');
    }

    private function configureSafeSandbox(): void
    {
        config()->set('tala_integrations.payments.driver', 'paymongo');
        config()->set('tala_integrations.payments.paymongo.base_url', 'https://api.paymongo.com');
        config()->set('tala_integrations.payments.paymongo.public_key', 'pk_test_tal95d1_not_real');
        config()->set('tala_integrations.payments.paymongo.secret_key', 'sk_test_tal95d1_not_real');
        config()->set('tala_integrations.payments.paymongo.webhook_signature', 'whsk_tal95d1_not_real');
        config()->set('tala_integrations.payments.paymongo.livemode', false);
    }

    private function payMongoGateway(): PayMongoPaymentGateway
    {
        return new PayMongoPaymentGateway(
            money: app(DecimalMoney::class),
            baseUrl: 'https://api.paymongo.com',
            secretKey: 'sk_test_tal95d1_not_real',
            paymentMethodTypes: ['gcash', 'card'],
        );
    }

    private function checkoutRequest(): PaymentCheckoutRequest
    {
        return new PaymentCheckoutRequest(
            studentProfileId: 10,
            amount: '2000.00',
            description: 'TAL-95D1 provider retry contract',
            assessmentId: 20,
            metadata: ['tala_reference' => 'TALA-PAY-TAL95D1-HTTP'],
        );
    }

    /** @return array<string, mixed> */
    private function checkoutSessionResponse(): array
    {
        return [
            'data' => [
                'id' => 'cs_tal95d1_http',
                'type' => 'checkout_session',
                'attributes' => [
                    'checkout_url' => 'https://checkout.paymongo.com/cs_tal95d1_http',
                    'status' => 'active',
                    'livemode' => false,
                ],
            ],
        ];
    }

    private function assertSandboxGuardRejects(PayMongoSandboxEnvironmentGuard $guard, string $expectedMessage): void
    {
        try {
            $guard->assertSafe(['secret_key', 'webhook_signature']);
            $this->fail('The unsafe sandbox configuration should have been rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($expectedMessage, $exception->getMessage());
            $this->assertStringNotContainsString('sk_test_', $exception->getMessage());
            $this->assertStringNotContainsString('whsk_', $exception->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function legacyFailurePayload(PaymentAttempt $attempt): array
    {
        return [
            'data' => [
                'id' => 'evt_tal95d1_decline',
                'type' => 'event',
                'attributes' => [
                    'type' => 'payment.failed',
                    'livemode' => false,
                    'data' => [
                        'id' => (string) $attempt->provider_checkout_id,
                        'type' => 'checkout_session',
                        'attributes' => [
                            'status' => 'failed',
                            'amount_paid' => 100000,
                            'currency' => 'PHP',
                            'metadata' => ['tala_reference' => (string) $attempt->internal_reference],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function storeWebhookCall(array $payload): int
    {
        return (int) DB::table('webhook_calls')->insertGetId([
            'name' => 'paymongo',
            'url' => 'https://tala.test/api/webhooks/paymongo',
            'headers' => $this->encode([]),
            'payload' => $this->encode($payload),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}

final class TAL95D1RecordingPaymentGateway implements PaymentGateway
{
    public int $createCalls = 0;

    public int $expireCalls = 0;

    public string $expireStatus = 'expired';

    private ?PaymentCheckoutSession $session = null;

    public function provider(): string
    {
        return 'paymongo';
    }

    public function createCheckoutSession(PaymentCheckoutRequest $request, string $idempotencyKey): PaymentCheckoutSession
    {
        $this->createCalls++;
        $this->session = new PaymentCheckoutSession(
            provider: 'paymongo',
            checkoutSessionId: 'cs_tal95d1_command',
            checkoutUrl: 'https://checkout.paymongo.com/cs_tal95d1_command',
            status: 'active',
            metadata: ['livemode' => false],
        );

        return $this->session;
    }

    public function retrieveCheckoutSession(string $checkoutSessionId): PaymentCheckoutSession
    {
        return $this->session ?? throw new RuntimeException('No recorded session.');
    }

    public function expireCheckoutSession(string $checkoutSessionId): PaymentCheckoutSession
    {
        $this->expireCalls++;
        $session = $this->retrieveCheckoutSession($checkoutSessionId);
        $this->session = new PaymentCheckoutSession(
            provider: $session->provider,
            checkoutSessionId: $session->checkoutSessionId,
            checkoutUrl: $session->checkoutUrl,
            status: $this->expireStatus,
            metadata: $session->metadata,
        );

        return $this->session;
    }
}
