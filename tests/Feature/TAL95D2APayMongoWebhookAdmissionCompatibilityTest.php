<?php

namespace Tests\Feature;

use App\Actions\Integrations\Payments\PayMongoWebhookSignatureVerification;
use App\Actions\Integrations\Payments\PayMongoWebhookSignatureVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TAL95D2APayMongoWebhookAdmissionCompatibilityTest extends TestCase
{
    use DatabaseTransactions;

    private const WebhookSecret = 'whsk_tal95d2a_test_secret';

    private int $baselineWebhookCallCount;

    private int $baselinePayMongoEventCount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());

        $this->baselineWebhookCallCount = DB::table('webhook_calls')->count();
        $this->baselinePayMongoEventCount = DB::table('operational_events')
            ->where('integration', 'PAYMONGO')
            ->count();

        config()->set('tala_integrations.payments.driver', 'paymongo');
        config()->set('tala_integrations.payments.paymongo.webhook_signature', self::WebhookSecret);
        config()->set('tala_integrations.payments.paymongo.livemode', false);
        config()->set('tala_integrations.payments.paymongo.signature_max_age_seconds', 300);
        CarbonImmutable::setTestNow('2026-07-16 12:45:00');

        Queue::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_invalid_hmac_is_logged_with_only_a_safe_bounded_verdict(): void
    {
        Log::spy();

        $body = '{"data":{"id":"evt_sensitive_tal95d2a","type":"event"}}';
        $timestamp = (string) CarbonImmutable::now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'different-secret');
        $header = 't='.$timestamp.',te='.$signature;

        $this->call('POST', '/api/webhooks/paymongo', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => $header,
        ], $body)
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Invalid PayMongo webhook signature.']);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($body, $header): bool {
                $encodedLog = json_encode([$message, $context], JSON_THROW_ON_ERROR);

                return $message === 'PayMongo webhook signature rejected.'
                    && $context === [
                        'reason' => 'signature_mismatch',
                        'expected_mode' => 'test',
                        'timestamp_age_seconds' => 0,
                    ]
                    && ! str_contains($encodedLog, self::WebhookSecret)
                    && ! str_contains($encodedLog, $header)
                    && ! str_contains($encodedLog, $body)
                    && ! str_contains($encodedLog, 'evt_sensitive_tal95d2a');
            });

        $this->assertSame($this->baselineWebhookCallCount, DB::table('webhook_calls')->count());
        $this->assertSame(
            $this->baselinePayMongoEventCount,
            DB::table('operational_events')->where('integration', 'PAYMONGO')->count(),
        );
        Queue::assertNothingPushed();
    }

    public function test_verifier_distinguishes_every_fail_closed_admission_branch(): void
    {
        $verifier = app(PayMongoWebhookSignatureVerifier::class);
        $body = '{"data":{"id":"evt_tal95d2a_verdicts","type":"event"}}';
        $now = CarbonImmutable::now()->getTimestamp();

        config()->set('tala_integrations.payments.paymongo.webhook_signature');
        $missingSecret = $verifier->verify($this->signedRequest($body, $now));
        $this->assertSame(PayMongoWebhookSignatureVerification::MissingSecret, $missingSecret->reason);
        $this->assertFalse($missingSecret->isValid());

        config()->set('tala_integrations.payments.paymongo.webhook_signature', self::WebhookSecret);
        $missingHeader = $verifier->verify(Request::create('/api/webhooks/paymongo', 'POST', content: $body));
        $this->assertSame(PayMongoWebhookSignatureVerification::MissingHeader, $missingHeader->reason);

        $malformedTimestamp = $verifier->verify($this->requestWithHeader($body, 't=not-a-timestamp,te='.str_repeat('0', 64)));
        $this->assertSame(PayMongoWebhookSignatureVerification::MalformedTimestamp, $malformedTimestamp->reason);

        $missingModeSignature = $verifier->verify($this->signedRequest($body, $now, 'li'));
        $this->assertSame(PayMongoWebhookSignatureVerification::MissingModeSignature, $missingModeSignature->reason);
        $this->assertSame('test', $missingModeSignature->expectedMode);

        $malformedSignature = $verifier->verify($this->requestWithHeader($body, 't='.$now.',te=not-a-signature'));
        $this->assertSame(PayMongoWebhookSignatureVerification::MalformedSignature, $malformedSignature->reason);

        $mismatchedSignature = $verifier->verify($this->signedRequest($body, $now, secret: 'different-secret'));
        $this->assertSame(PayMongoWebhookSignatureVerification::SignatureMismatch, $mismatchedSignature->reason);
        $this->assertSame(0, $mismatchedSignature->timestampAgeSeconds);

        $futureTimestamp = $verifier->verify($this->signedRequest($body, $now + 301));
        $this->assertSame(PayMongoWebhookSignatureVerification::FutureTimestamp, $futureTimestamp->reason);
        $this->assertSame(-301, $futureTimestamp->timestampAgeSeconds);

        $staleTimestamp = $verifier->verify($this->signedRequest($body, $now - 301));
        $this->assertSame(PayMongoWebhookSignatureVerification::StaleTimestamp, $staleTimestamp->reason);
        $this->assertSame(301, $staleTimestamp->timestampAgeSeconds);

        config()->set('tala_integrations.payments.paymongo.signature_max_age_seconds', 0);
        $invalidFreshnessPolicy = $verifier->verify($this->signedRequest($body, $now));
        $this->assertSame(PayMongoWebhookSignatureVerification::InvalidFreshnessPolicy, $invalidFreshnessPolicy->reason);

        config()->set('tala_integrations.payments.paymongo.signature_max_age_seconds', 300);
        $valid = $verifier->verify($this->signedRequest($body, $now));
        $this->assertSame(PayMongoWebhookSignatureVerification::Valid, $valid->reason);
        $this->assertSame('test', $valid->expectedMode);
        $this->assertSame(0, $valid->timestampAgeSeconds);
        $this->assertTrue($valid->isValid());
    }

    public function test_correctly_signed_delivery_with_small_future_clock_skew_is_valid(): void
    {
        $body = '{"data":{"id":"evt_tal95d2a_clock_skew","type":"event"}}';
        $providerTimestamp = CarbonImmutable::now()->getTimestamp() + 4;

        $verification = app(PayMongoWebhookSignatureVerifier::class)
            ->verify($this->signedRequest($body, $providerTimestamp));

        $this->assertSame(PayMongoWebhookSignatureVerification::Valid, $verification->reason);
        $this->assertSame(-4, $verification->timestampAgeSeconds);
        $this->assertTrue($verification->isValid());
    }

    public function test_correctly_signed_old_delivery_is_classified_as_stale_without_admission(): void
    {
        Log::spy();

        $body = '{"data":{"id":"evt_tal95d2a_stale","type":"event"}}';
        $timestamp = CarbonImmutable::now()->getTimestamp() - 301;
        $header = $this->signatureHeader($body, $timestamp);

        $this->call('POST', '/api/webhooks/paymongo', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => $header,
        ], $body)->assertUnauthorized();

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('PayMongo webhook signature rejected.', [
                'reason' => 'stale_timestamp',
                'expected_mode' => 'test',
                'timestamp_age_seconds' => 301,
            ]);

        $this->assertSame($this->baselineWebhookCallCount, DB::table('webhook_calls')->count());
        $this->assertSame(
            $this->baselinePayMongoEventCount,
            DB::table('operational_events')->where('integration', 'PAYMONGO')->count(),
        );
        Queue::assertNothingPushed();
    }

    private function signedRequest(
        string $body,
        int $timestamp,
        string $mode = 'te',
        string $secret = self::WebhookSecret,
    ): Request {
        return $this->requestWithHeader($body, $this->signatureHeader($body, $timestamp, $mode, $secret));
    }

    private function signatureHeader(
        string $body,
        int $timestamp,
        string $mode = 'te',
        string $secret = self::WebhookSecret,
    ): string {
        return 't='.$timestamp.','.$mode.'='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    private function requestWithHeader(string $body, string $header): Request
    {
        return Request::create('/api/webhooks/paymongo', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => $header,
        ], content: $body);
    }
}
