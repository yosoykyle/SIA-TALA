<?php

namespace Tests\Feature;

use App\Actions\Integrations\Payments\PayMongoWebhookEvent;
use App\Actions\Integrations\Payments\PayMongoWebhookProcessor;
use App\Jobs\ProcessPayMongoWebhookCall;
use App\Mail\PaymentPostedMail;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\LedgerEntry;
use App\Models\OperationalEvent;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class TAL95D2B1PayMongoNormalizationRecoveryTest extends TestCase
{
    use DatabaseTransactions;

    private const WebhookSecret = 'whsk_tal95d2b1_not_real';

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
        CarbonImmutable::setTestNow('2026-07-16 14:00:00');
        Mail::fake();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_exact_signed_redelivery_recovers_once_and_posts_existing_review_evidence_idempotently(): void
    {
        $fixture = $this->paymentFixture();
        $payload = $this->paidCheckoutPayload($fixture['attempt'], 'evt_tal95d2b1_recovery');
        $rawBody = $this->encode($payload);
        $operationalEvent = $this->storeParserReview($fixture['payment'], $rawBody);
        $queue = new TAL95D2B1LockInspectingQueueFake(
            $this->app,
            'paymongo-webhook:event:'.hash('sha256', 'evt_tal95d2b1_recovery'),
        );
        Queue::swap($queue);

        $this->postSignedBody($rawBody)
            ->assertAccepted()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('operational_event_id', $operationalEvent->id);

        $recovered = $operationalEvent->fresh();
        $this->assertSame(OperationalEvent::StatusPending, $recovered->status);
        $this->assertSame(PayMongoWebhookEvent::NormalizationVersion, data_get($recovered->diagnostics, 'normalization_version'));
        $this->assertSame(0, data_get($recovered->diagnostics, 'normalization_recovery.from_version'));
        $this->assertSame(PayMongoWebhookEvent::NormalizationVersion, data_get($recovered->diagnostics, 'normalization_recovery.to_version'));
        $this->assertSame('payment_status_mismatch', data_get($recovered->diagnostics, 'normalization_recovery.reason'));
        $this->assertSame(hash('sha256', $rawBody), data_get($recovered->diagnostics, 'payload_sha256'));
        $this->assertNull(data_get($recovered->diagnostics, 'reason'));
        $this->assertTrue($queue->eventLockWasHeldDuringPush);
        Queue::assertPushed(ProcessPayMongoWebhookCall::class, 1);

        $this->postSignedBody($rawBody)
            ->assertAccepted()
            ->assertJsonPath('status', 'duplicate');

        $pendingDuplicate = $operationalEvent->fresh();
        $this->assertSame(OperationalEvent::StatusPending, $pendingDuplicate->status);
        $this->assertSame(3, data_get($pendingDuplicate->diagnostics, 'delivery_count'));
        Queue::assertPushed(ProcessPayMongoWebhookCall::class, 1);
        $this->assertSame(0, $this->paymentLedgerCount($fixture['payment']));
        $this->assertSame(0, $this->paymentNotificationCount($fixture['payment']));
        Mail::assertNothingQueued();

        /** @var ProcessPayMongoWebhookCall $job */
        $job = $queue->pushed(ProcessPayMongoWebhookCall::class)->sole();
        $result = app(PayMongoWebhookProcessor::class)->process($job->webhookCallId, $job->operationalEventId);

        $this->assertSame('posted', $result['status']);
        $this->assertSame('paid', $fixture['attempt']->fresh()->status);
        $this->assertSame('verified', $fixture['payment']->fresh()->evidence_status);
        $this->assertSame('paymongo:pay_tal95d2b1', $fixture['payment']->fresh()->provider_reference);
        $this->assertSame(1, $this->paymentLedgerCount($fixture['payment']));
        $this->assertSame(1, $this->paymentNotificationCount($fixture['payment']));
        Mail::assertQueued(PaymentPostedMail::class, 1);

        $this->postSignedBody($rawBody)
            ->assertAccepted()
            ->assertJsonPath('status', 'duplicate');

        Queue::assertPushed(ProcessPayMongoWebhookCall::class, 1);
        $this->assertSame(4, data_get($operationalEvent->fresh()->diagnostics, 'delivery_count'));
        $this->assertSame(1, $this->paymentLedgerCount($fixture['payment']));
        $this->assertSame(1, $this->paymentNotificationCount($fixture['payment']));
        Mail::assertQueued(PaymentPostedMail::class, 1);
    }

    public function test_byte_different_redelivery_cannot_enter_normalization_recovery(): void
    {
        $fixture = $this->paymentFixture();
        $payload = $this->paidCheckoutPayload($fixture['attempt'], 'evt_tal95d2b1_altered');
        $originalBody = $this->encode($payload);
        $operationalEvent = $this->storeParserReview($fixture['payment'], $originalBody);
        data_set($payload, 'data.attributes.data.attributes.description', 'Byte-different resend');

        $this->postSignedBody($this->encode($payload))
            ->assertAccepted()
            ->assertJsonPath('status', 'review_required');

        $event = $operationalEvent->fresh();
        $this->assertSame(OperationalEvent::StatusReviewRequired, $event->status);
        $this->assertSame('event_id_payload_conflict', data_get($event->diagnostics, 'reason'));
        $this->assertNull(data_get($event->diagnostics, 'normalization_recovery'));
        Queue::assertNothingPushed();
    }

    public function test_wrong_reason_current_version_prior_marker_and_live_evidence_reject_recovery(): void
    {
        $cases = [
            'wrong reason' => ['reason' => 'amount_mismatch'],
            'current version' => ['normalization_version' => PayMongoWebhookEvent::NormalizationVersion],
            'prior marker' => ['normalization_recovery' => ['to_version' => PayMongoWebhookEvent::NormalizationVersion]],
            'live evidence' => ['livemode' => true],
        ];

        foreach ($cases as $label => $overrides) {
            $fixture = $this->paymentFixture();
            $payload = $this->paidCheckoutPayload($fixture['attempt'], 'evt_tal95d2b1_'.Str::slug($label, '_'));

            if (($overrides['livemode'] ?? false) === true) {
                data_set($payload, 'data.attributes.livemode', true);
                unset($overrides['livemode']);
            }

            $rawBody = $this->encode($payload);
            $operationalEvent = $this->storeParserReview($fixture['payment'], $rawBody, $overrides);

            $this->postSignedBody($rawBody)
                ->assertAccepted()
                ->assertJsonPath('status', 'review_required');

            $this->assertSame(
                'event_id_payload_conflict',
                data_get($operationalEvent->fresh()->diagnostics, 'reason'),
                $label,
            );
        }

        Queue::assertNothingPushed();
    }

    public function test_configured_live_mode_rejects_test_mode_normalization_recovery(): void
    {
        $fixture = $this->paymentFixture();
        $payload = $this->paidCheckoutPayload($fixture['attempt'], 'evt_tal95d2b1_configured_live');
        $rawBody = $this->encode($payload);
        $operationalEvent = $this->storeParserReview($fixture['payment'], $rawBody);
        config()->set('tala_integrations.payments.paymongo.livemode', true);

        $this->postSignedBody($rawBody, 'li')
            ->assertAccepted()
            ->assertJsonPath('status', 'review_required');

        $this->assertSame(
            'event_id_payload_conflict',
            data_get($operationalEvent->fresh()->diagnostics, 'reason'),
        );
        $this->assertNull(data_get($operationalEvent->fresh()->diagnostics, 'normalization_recovery'));
        Queue::assertNothingPushed();
    }

    public function test_newly_admitted_event_records_the_current_normalization_version(): void
    {
        $fixture = $this->paymentFixture();
        $payload = $this->paidCheckoutPayload($fixture['attempt'], 'evt_tal95d2b1_new');

        $response = $this->postSignedBody($this->encode($payload))
            ->assertAccepted()
            ->assertJsonPath('status', 'accepted');

        $event = OperationalEvent::query()->findOrFail($response->json('operational_event_id'));
        $this->assertSame(PayMongoWebhookEvent::NormalizationVersion, data_get($event->diagnostics, 'normalization_version'));
        Queue::assertPushed(ProcessPayMongoWebhookCall::class, 1);
    }

    /** @return array{attempt:PaymentAttempt,payment:Payment} */
    private function paymentFixture(): array
    {
        $user = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $program = Program::factory()->create();
        $profile = StudentProfile::factory()->for($user)->for($program)->create();
        $academicYear = AcademicYear::factory()->create([
            'label' => 'TAL-95D2B1-'.Str::upper((string) Str::uuid()),
        ]);
        $term = Term::factory()->for($academicYear)->create();
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
        LedgerEntry::query()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'direction' => LedgerEntry::DirectionCharge,
            'category' => 'tuition',
            'amount' => '9000.00',
            'source_type' => Assessment::class,
            'source_id' => $assessment->id,
            'description' => 'TAL-95D2B1 recovery fixture',
            'posted_at' => now(),
            'state' => 'posted',
        ]);
        $attempt = PaymentAttempt::query()->create([
            'assessment_id' => $assessment->id,
            'student_profile_id' => $profile->id,
            'channel' => 'paymongo',
            'provider' => 'paymongo',
            'internal_reference' => 'TALA-PAY-'.Str::upper((string) Str::uuid()),
            'provider_checkout_id' => 'cs_tal95d2b1_'.Str::lower((string) Str::random(8)),
            'amount' => '2000.00',
            'currency' => 'PHP',
            'status' => 'under_review',
            'metadata' => [],
        ]);
        $payment = Payment::query()->create([
            'payment_attempt_id' => $attempt->id,
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'method' => 'paymongo',
            'channel' => 'paymongo',
            'amount' => '2000.00',
            'currency' => 'PHP',
            'evidence_status' => 'under_review',
            'paid_at' => now(),
            'verified_at' => null,
            'verified_by' => null,
            'provider_reference' => 'paymongo:'.$attempt->provider_checkout_id,
        ]);

        return compact('attempt', 'payment');
    }

    /** @return array<string, mixed> */
    private function paidCheckoutPayload(PaymentAttempt $attempt, string $eventId): array
    {
        return [
            'data' => [
                'id' => $eventId,
                'type' => 'event',
                'attributes' => [
                    'type' => 'checkout_session.payment.paid',
                    'livemode' => false,
                    'data' => [
                        'id' => $attempt->provider_checkout_id,
                        'type' => 'checkout_session',
                        'attributes' => [
                            'status' => 'active',
                            'reference_number' => $attempt->internal_reference,
                            'metadata' => ['tala_reference' => $attempt->internal_reference],
                            'payment_intent' => ['id' => 'pi_tal95d2b1'],
                            'payments' => [[
                                'id' => 'pay_tal95d2b1',
                                'type' => 'payment',
                                'attributes' => [
                                    'amount' => 200000,
                                    'currency' => 'PHP',
                                    'status' => 'paid',
                                    'payment_intent_id' => 'pi_tal95d2b1',
                                    'disputed' => false,
                                    'refunds' => [],
                                ],
                            ]],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $diagnosticOverrides */
    private function storeParserReview(Payment $payment, string $rawBody, array $diagnosticOverrides = []): OperationalEvent
    {
        $event = PayMongoWebhookEvent::fromRawBody($rawBody);
        $webhookCallId = DB::table('webhook_calls')->insertGetId([
            'name' => 'paymongo',
            'url' => 'https://tala.test/api/webhooks/paymongo',
            'headers' => $this->encode([]),
            'payload' => $rawBody,
            'exception' => 'review_required:payment_status_mismatch',
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return OperationalEvent::query()->create([
            'event_domain' => OperationalEvent::DomainIntegration,
            'integration' => OperationalEvent::IntegrationPayMongo,
            'channel' => OperationalEvent::ChannelWebhook,
            'direction' => OperationalEvent::DirectionInbound,
            'event_type' => $event->eventType,
            'event_version' => $event->envelopeVersion,
            'external_id' => $event->eventId,
            'status' => OperationalEvent::StatusReviewRequired,
            'occurred_at' => now(),
            'processed_at' => now(),
            'related_record_type' => Payment::class,
            'related_record_id' => $payment->id,
            'diagnostics' => [
                'payload_sha256' => hash('sha256', $rawBody),
                'semantic_fingerprint' => str_repeat('a', 64),
                'webhook_call_id' => $webhookCallId,
                'latest_webhook_call_id' => $webhookCallId,
                'delivery_count' => 1,
                'reason' => 'payment_status_mismatch',
                ...$diagnosticOverrides,
            ],
            'payload' => $event->summary(),
        ]);
    }

    private function paymentLedgerCount(Payment $payment): int
    {
        return LedgerEntry::query()
            ->where('source_type', Payment::class)
            ->where('source_id', $payment->id)
            ->where('direction', LedgerEntry::DirectionPayment)
            ->count();
    }

    private function paymentNotificationCount(Payment $payment): int
    {
        return OperationalEvent::query()
            ->where('event_domain', OperationalEvent::DomainNotifications)
            ->where('event_type', OperationalEvent::TypePaymentPostedEmail)
            ->where('related_record_type', Payment::class)
            ->where('related_record_id', $payment->id)
            ->count();
    }

    private function postSignedBody(string $rawBody, string $mode = 'te'): TestResponse
    {
        $timestamp = CarbonImmutable::now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$rawBody, self::WebhookSecret);

        return $this->call('POST', '/api/webhooks/paymongo', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => 't='.$timestamp.','.$mode.'='.$signature,
        ], $rawBody);
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}

final class TAL95D2B1LockInspectingQueueFake extends QueueFake
{
    public bool $eventLockWasHeldDuringPush = false;

    public function __construct(
        Application $application,
        private readonly string $eventLockKey,
    ) {
        parent::__construct($application);
    }

    public function push($job, $data = '', $queue = null)
    {
        if ($job instanceof ProcessPayMongoWebhookCall) {
            $eventLock = Cache::lock($this->eventLockKey, 30);
            $acquiredEventLock = $eventLock->get();

            if ($acquiredEventLock) {
                $eventLock->release();
            }

            $this->eventLockWasHeldDuringPush = ! $acquiredEventLock;
        }

        return parent::push($job, $data, $queue);
    }
}
