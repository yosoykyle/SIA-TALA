<?php

namespace Tests\Feature;

use App\Actions\Integrations\Payments\PayMongoWebhookProcessor;
use App\Actions\SystemAdministration\GovernanceEvidenceProjection;
use App\Jobs\ProcessPayMongoWebhookCall;
use App\Models\OperationalEvent;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

final class TAL95BPayMongoWebhookPipelineTest extends TestCase
{
    use DatabaseTransactions;

    private const WebhookSecret = 'whsec_tal95b_not_real';

    private int $baselineWebhookCallId;

    private int $baselineOperationalEventId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());

        $this->baselineWebhookCallId = (int) (DB::table('webhook_calls')->max('id') ?? 0);
        $this->baselineOperationalEventId = (int) (OperationalEvent::query()->max('id') ?? 0);

        config()->set('tala_integrations.payments.driver', 'paymongo');
        config()->set('tala_integrations.payments.paymongo.webhook_signature', self::WebhookSecret);
        config()->set('tala_integrations.payments.paymongo.livemode', false);
        config()->set('tala_integrations.payments.paymongo.max_payload_bytes', 1_048_576);

        Queue::fake();
    }

    public function test_every_driver_rejects_webhooks_when_the_signing_secret_is_missing(): void
    {
        config()->set('tala_integrations.payments.driver', 'mock');
        config()->set('tala_integrations.payments.paymongo.webhook_signature');

        $this->postWebhook($this->eventPayload())->assertUnauthorized();

        $this->assertSame(0, $this->newWebhookCalls()->count());
        $this->assertSame(0, $this->newOperationalEvents()->count());
        Queue::assertNothingPushed();
    }

    public function test_signature_must_use_the_key_for_the_configured_mode(): void
    {
        $payload = $this->eventPayload();
        $body = $this->encode($payload);

        $this->postRaw($body, $this->signature($body, 'li'))->assertUnauthorized();

        $this->assertSame(0, $this->newWebhookCalls()->count());
        Queue::assertNothingPushed();
    }

    public function test_valid_unsupported_event_is_durably_ignored_with_redacted_headers(): void
    {
        $payload = $this->eventPayload(eventType: 'source.chargeable', eventId: 'evt_tal95b_ignored');
        $body = $this->encode($payload);

        $this->postRaw($body, $this->signature($body), [
            'HTTP_AUTHORIZATION' => 'Bearer must-not-be-stored',
            'HTTP_COOKIE' => 'session=must-not-be-stored',
        ])->assertAccepted()->assertJsonPath('status', 'ignored');

        $call = $this->newWebhookCalls()->sole();
        $headers = json_decode((string) $call->headers, true, flags: JSON_THROW_ON_ERROR);
        $event = $this->newOperationalEvents()->sole();

        $this->assertSame(['[REDACTED]'], $headers['paymongo-signature']);
        $this->assertSame(['[REDACTED]'], $headers['authorization']);
        $this->assertSame(['[REDACTED]'], $headers['cookie']);
        $this->assertSame('IGNORED', $event->status);
        $this->assertSame('evt_tal95b_ignored', $event->external_id);
        $this->assertSame('source.chargeable', $event->event_type);
        $this->assertArrayNotHasKey('raw_payload', $event->payload ?? []);
        Queue::assertNothingPushed();
    }

    public function test_supported_event_creates_one_pending_canonical_record_and_after_commit_job(): void
    {
        $this->postWebhook($this->eventPayload(eventId: 'evt_tal95b_pending'))
            ->assertAccepted()
            ->assertJsonPath('status', 'accepted');

        $event = $this->newOperationalEvents()->sole();

        $this->assertSame('PENDING', $event->status);
        $this->assertSame('PAYMONGO', $event->integration);
        $this->assertSame('webhook', $event->channel);
        $this->assertSame('INBOUND', $event->direction);
        $this->assertSame(64, strlen((string) data_get($event->diagnostics, 'payload_sha256')));
        Queue::assertPushed(ProcessPayMongoWebhookCall::class, function (ProcessPayMongoWebhookCall $job) use ($event): bool {
            return $job->webhookCallId === $this->newWebhookCalls()->sole()->id
                && $job->operationalEventId === $event->id
                && $job->afterCommit === true;
        });
    }

    public function test_oversized_payload_is_rejected_before_persistence(): void
    {
        config()->set('tala_integrations.payments.paymongo.max_payload_bytes', 128);
        $payload = $this->eventPayload();
        data_set($payload, 'data.attributes.data.attributes.description', str_repeat('x', 512));

        $this->postWebhook($payload)->assertStatus(413);

        $this->assertSame(0, $this->newWebhookCalls()->count());
        Queue::assertNothingPushed();
    }

    public function test_signed_malformed_event_is_durably_rejected_without_a_job(): void
    {
        $payload = ['data' => ['id' => 'evt_tal95b_malformed']];

        $this->postWebhook($payload)
            ->assertAccepted()
            ->assertJsonPath('status', 'rejected');

        $this->assertSame(1, $this->newWebhookCalls()->count());
        $this->assertStringContainsString(
            'invalid_event_envelope',
            (string) $this->newWebhookCalls()->value('exception'),
        );
        $this->assertSame(0, $this->newOperationalEvents()->count());
        Queue::assertNothingPushed();
    }

    public function test_exact_duplicate_delivery_reuses_one_event_and_does_not_queue_again(): void
    {
        $payload = $this->eventPayload(eventId: 'evt_tal95b_duplicate');

        $this->postWebhook($payload)->assertAccepted()->assertJsonPath('status', 'accepted');
        $this->postWebhook($payload)->assertAccepted()->assertJsonPath('status', 'duplicate');

        $this->assertSame(2, $this->newWebhookCalls()->count());
        $this->assertSame(1, $this->newOperationalEvents()->count());
        Queue::assertPushed(ProcessPayMongoWebhookCall::class, 1);
    }

    public function test_reused_event_id_with_a_different_fingerprint_is_routed_to_review(): void
    {
        $first = $this->eventPayload(eventId: 'evt_tal95b_conflict');
        $conflict = $this->eventPayload(eventId: 'evt_tal95b_conflict');
        data_set($conflict, 'data.attributes.data.id', 'cs_tal95b_conflict');

        $this->postWebhook($first)->assertAccepted();
        $this->postWebhook($conflict)->assertAccepted()->assertJsonPath('status', 'review_required');

        $event = $this->newOperationalEvents()->sole();
        $this->assertSame('REVIEW_REQUIRED', $event->status);
        $this->assertSame('event_id_payload_conflict', data_get($event->diagnostics, 'reason'));
        Queue::assertPushed(ProcessPayMongoWebhookCall::class, 1);
    }

    public function test_terminal_job_failure_updates_both_private_and_operational_records_without_raw_message(): void
    {
        $payload = $this->eventPayload(eventId: 'evt_tal95b_failed');
        $webhookCallId = $this->webhookCall($payload);
        $event = $this->operationalEvent($payload, $webhookCallId);
        $exception = new RuntimeException('Sensitive provider response must not be persisted.');

        (new ProcessPayMongoWebhookCall($webhookCallId, $event->id))->failed($exception);

        $privateFailure = (string) DB::table('webhook_calls')->where('id', $webhookCallId)->value('exception');
        $event->refresh();

        $this->assertStringContainsString(RuntimeException::class, $privateFailure);
        $this->assertStringNotContainsString('Sensitive provider response', $privateFailure);
        $this->assertSame('FAILED', $event->status);
        $this->assertSame('processing_failed', data_get($event->diagnostics, 'reason'));
        $this->assertNotNull($event->failed_at);
        $this->assertStringNotContainsString('Sensitive provider response', $this->encode($event->diagnostics ?? []));
    }

    public function test_governance_projection_maps_paymongo_events_to_the_safe_status_vocabulary(): void
    {
        $events = collect([
            'PENDING' => 'Pending',
            'PROCESSED' => 'Recorded',
            'FAILED' => 'Attention',
            'REVIEW_REQUIRED' => 'Attention',
            'IGNORED' => 'Pending',
        ])->mapWithKeys(function (string $projectedStatus, string $status): array {
            $event = OperationalEvent::factory()->create([
                'event_type' => 'paymongo_'.strtolower($status),
                'status' => $status,
            ]);

            return [$event->id => $projectedStatus];
        });

        $rows = app(GovernanceEvidenceProjection::class)
            ->paginate(GovernanceEvidenceProjection::SystemEvents, 1, 25, 'paymongo_', [])
            ->getCollection();

        foreach ($events as $eventId => $projectedStatus) {
            $row = $rows->firstWhere('reference_id', 'operational:'.$eventId);

            $this->assertNotNull($row);
            $this->assertSame($projectedStatus, $row['status']);
            $this->assertSame('Operational event', $row['source']);
        }
    }

    public function test_queued_processor_uses_the_canonical_event_and_routes_unknown_source_to_review(): void
    {
        $queue = Queue::fake();

        $this->postWebhook($this->eventPayload(eventId: 'evt_tal95b_queued_review'))->assertAccepted();

        /** @var ProcessPayMongoWebhookCall $job */
        $job = $queue->pushed(ProcessPayMongoWebhookCall::class)->sole();
        $job->handle(app(PayMongoWebhookProcessor::class));

        $event = $this->newOperationalEvents()->sole();
        $this->assertSame('REVIEW_REQUIRED', $event->status);
        $this->assertSame('unknown_reference', data_get($event->diagnostics, 'reason'));
        $this->assertNotNull($this->newWebhookCalls()->sole()->processed_at);
    }

    public function test_valid_delivery_and_canonical_event_roll_back_together_when_acceptance_persistence_fails(): void
    {
        $originalDispatcher = OperationalEvent::getEventDispatcher();
        $this->assertNotNull($originalDispatcher);
        OperationalEvent::setEventDispatcher(clone $originalDispatcher);
        OperationalEvent::creating(function (): never {
            throw new RuntimeException('Simulated canonical event persistence failure.');
        });

        try {
            $this->postWebhook($this->eventPayload(eventId: 'evt_tal95b_atomic_failure'))
                ->assertServerError();
        } finally {
            OperationalEvent::setEventDispatcher($originalDispatcher);
        }

        $this->assertSame(0, $this->newWebhookCalls()->count());
        $this->assertSame(0, $this->newOperationalEvents()->count());
        Queue::assertNothingPushed();
    }

    /** @return array<string, mixed> */
    private function eventPayload(
        string $eventType = 'checkout_session.payment.paid',
        string $eventId = 'evt_tal95b',
    ): array {
        return [
            'data' => [
                'id' => $eventId,
                'type' => 'event',
                'attributes' => [
                    'type' => $eventType,
                    'livemode' => false,
                    'data' => [
                        'id' => 'cs_tal95b',
                        'type' => 'checkout_session',
                        'attributes' => [
                            'status' => 'paid',
                            'amount_paid' => 100000,
                            'currency' => 'PHP',
                            'metadata' => ['tala_reference' => 'TALA-PAY-TAL95B'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function postWebhook(array $payload): TestResponse
    {
        $body = $this->encode($payload);

        return $this->postRaw($body, $this->signature($body));
    }

    /** @param array<string, string> $server */
    private function postRaw(string $body, string $signature, array $server = []): TestResponse
    {
        return $this->call('POST', '/api/webhooks/paymongo', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => $signature,
            ...$server,
        ], $body);
    }

    private function signature(string $body, string $modeKey = 'te'): string
    {
        $timestamp = (string) now()->getTimestamp();

        return 't='.$timestamp.','.$modeKey.'='.hash_hmac('sha256', $timestamp.'.'.$body, self::WebhookSecret);
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function newWebhookCalls(): QueryBuilder
    {
        return DB::table('webhook_calls')->where('id', '>', $this->baselineWebhookCallId);
    }

    /** @return EloquentBuilder<OperationalEvent> */
    private function newOperationalEvents(): EloquentBuilder
    {
        return OperationalEvent::query()->where('id', '>', $this->baselineOperationalEventId);
    }

    /** @param array<string, mixed> $payload */
    private function webhookCall(array $payload): int
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

    /** @param array<string, mixed> $payload */
    private function operationalEvent(array $payload, int $webhookCallId): OperationalEvent
    {
        return OperationalEvent::query()->create([
            'event_domain' => 'INTEGRATION',
            'integration' => 'PAYMONGO',
            'channel' => 'webhook',
            'direction' => 'INBOUND',
            'event_type' => (string) data_get($payload, 'data.attributes.type'),
            'event_version' => 'v1',
            'external_id' => (string) data_get($payload, 'data.id'),
            'status' => 'PENDING',
            'occurred_at' => now(),
            'diagnostics' => [
                'payload_sha256' => hash('sha256', $this->encode($payload)),
                'webhook_call_id' => $webhookCallId,
            ],
            'payload' => ['livemode' => false],
        ]);
    }
}
