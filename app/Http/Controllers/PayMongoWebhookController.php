<?php

namespace App\Http\Controllers;

use App\Actions\Integrations\Payments\PayMongoWebhookEvent;
use App\Actions\Integrations\Payments\PayMongoWebhookSignatureVerifier;
use App\Jobs\ProcessPayMongoWebhookCall;
use App\Models\OperationalEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;

class PayMongoWebhookController extends Controller
{
    public function __invoke(Request $request, PayMongoWebhookSignatureVerifier $signatureVerifier): JsonResponse
    {
        $rawBody = $request->getContent();
        $maxPayloadBytes = (int) config('tala_integrations.payments.paymongo.max_payload_bytes', 1_048_576);

        if (strlen($rawBody) > $maxPayloadBytes) {
            return response()->json(['message' => 'PayMongo webhook payload is too large.'], 413);
        }

        $signatureVerification = $signatureVerifier->verify($request);

        if (! $signatureVerification->isValid()) {
            Log::warning('PayMongo webhook signature rejected.', $signatureVerification->safeLogContext());

            return response()->json(['message' => 'Invalid PayMongo webhook signature.'], 401);
        }

        $now = CarbonImmutable::now(config('app.timezone'));

        try {
            $event = PayMongoWebhookEvent::fromRawBody($rawBody);
        } catch (InvalidArgumentException $exception) {
            $webhookCallId = DB::transaction(function () use ($request, $rawBody, $now, $exception): int {
                $webhookCallId = $this->persistWebhookCall($request, $rawBody, $now);

                DB::table('webhook_calls')->where('id', $webhookCallId)->update([
                    'exception' => 'invalid_event_envelope:'.$exception->getMessage(),
                    'processed_at' => $now->toDateTimeString(),
                    'updated_at' => $now->toDateTimeString(),
                ]);

                return $webhookCallId;
            }, 3);

            return response()->json([
                'status' => 'rejected',
                'webhook_call_id' => $webhookCallId,
            ], 202);
        }

        $acceptance = Cache::lock('paymongo-webhook:event:'.hash('sha256', $event->eventId), 30)
            ->block(5, fn (): array => DB::transaction(function () use ($request, $rawBody, $event, $now): array {
                $webhookCallId = $this->persistWebhookCall($request, $rawBody, $now);
                $existing = OperationalEvent::query()
                    ->where('event_domain', OperationalEvent::DomainIntegration)
                    ->where('external_id', $event->eventId)
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    $status = $event->isSupported()
                        ? OperationalEvent::StatusPending
                        : OperationalEvent::StatusIgnored;

                    $operationalEvent = OperationalEvent::query()->create([
                        'event_domain' => OperationalEvent::DomainIntegration,
                        'integration' => OperationalEvent::IntegrationPayMongo,
                        'channel' => OperationalEvent::ChannelWebhook,
                        'direction' => OperationalEvent::DirectionInbound,
                        'event_type' => $event->eventType,
                        'event_version' => $event->envelopeVersion,
                        'external_id' => $event->eventId,
                        'status' => $status,
                        'occurred_at' => $now,
                        'processed_at' => $status === OperationalEvent::StatusIgnored ? $now : null,
                        'diagnostics' => [
                            'payload_sha256' => $event->payloadSha256,
                            'semantic_fingerprint' => $event->semanticFingerprint(),
                            'webhook_call_id' => $webhookCallId,
                            'latest_webhook_call_id' => $webhookCallId,
                            'delivery_count' => 1,
                        ],
                        'payload' => $event->summary(),
                    ]);

                    return [
                        'webhook_call_id' => $webhookCallId,
                        'event_id' => $operationalEvent->id,
                        'status' => $event->isSupported() ? 'accepted' : 'ignored',
                        'dispatch' => $event->isSupported(),
                    ];
                }

                $diagnostics = $existing->diagnostics ?? [];
                $deliveryCount = (int) ($diagnostics['delivery_count'] ?? 1) + 1;
                $storedSemanticFingerprint = $this->storedSemanticFingerprint($existing);

                if ($storedSemanticFingerprint !== null && $storedSemanticFingerprint !== $event->semanticFingerprint()) {
                    $existing->forceFill([
                        'status' => OperationalEvent::StatusReviewRequired,
                        'processed_at' => $now,
                        'failed_at' => null,
                        'diagnostics' => [
                            ...$diagnostics,
                            'reason' => 'event_id_payload_conflict',
                            'conflicting_payload_sha256' => $event->payloadSha256,
                            'conflicting_semantic_fingerprint' => $event->semanticFingerprint(),
                            'latest_webhook_call_id' => $webhookCallId,
                            'delivery_count' => $deliveryCount,
                        ],
                    ])->save();

                    DB::table('webhook_calls')->where('id', $webhookCallId)->update([
                        'exception' => 'review_required:event_id_payload_conflict',
                        'processed_at' => $now->toDateTimeString(),
                        'updated_at' => $now->toDateTimeString(),
                    ]);

                    return [
                        'webhook_call_id' => $webhookCallId,
                        'event_id' => $existing->id,
                        'status' => 'review_required',
                        'dispatch' => false,
                    ];
                }

                $shouldRetry = $existing->status === OperationalEvent::StatusFailed && $event->isSupported();
                $needsInitialDispatch = $existing->status === OperationalEvent::StatusPending
                    && ($diagnostics['dispatch_queued_at'] ?? null) === null;
                $existing->forceFill([
                    'status' => $shouldRetry ? OperationalEvent::StatusPending : $existing->status,
                    'processed_at' => $shouldRetry ? null : $existing->processed_at,
                    'failed_at' => $shouldRetry ? null : $existing->failed_at,
                    'diagnostics' => [
                        ...$diagnostics,
                        'semantic_fingerprint' => $storedSemanticFingerprint ?? $event->semanticFingerprint(),
                        'latest_payload_sha256' => $event->payloadSha256,
                        'latest_webhook_call_id' => $webhookCallId,
                        'delivery_count' => $deliveryCount,
                    ],
                ])->save();

                return [
                    'webhook_call_id' => $webhookCallId,
                    'event_id' => $existing->id,
                    'status' => $shouldRetry ? 'accepted' : 'duplicate',
                    'dispatch' => $shouldRetry || $needsInitialDispatch,
                ];
            }, 3));

        if ($acceptance['dispatch']) {
            ProcessPayMongoWebhookCall::dispatch($acceptance['webhook_call_id'], $acceptance['event_id'])->afterCommit();
            $this->markDispatchQueued($acceptance['event_id'], $acceptance['webhook_call_id'], $now);
        }

        return response()->json([
            'status' => $acceptance['status'],
            'webhook_call_id' => $acceptance['webhook_call_id'],
            'operational_event_id' => $acceptance['event_id'],
        ], 202);
    }

    private function markDispatchQueued(int $operationalEventId, int $webhookCallId, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($operationalEventId, $webhookCallId, $now): void {
            $event = OperationalEvent::query()->lockForUpdate()->findOrFail($operationalEventId);
            $event->forceFill([
                'diagnostics' => [
                    ...($event->diagnostics ?? []),
                    'dispatch_queued_at' => $now->toIso8601String(),
                    'latest_webhook_call_id' => $webhookCallId,
                ],
            ])->save();
        }, 3);
    }

    private function persistWebhookCall(Request $request, string $rawBody, CarbonImmutable $now): int
    {
        try {
            $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $payload = null;
        }

        return (int) DB::table('webhook_calls')->insertGetId([
            'name' => 'paymongo',
            'url' => $request->fullUrl(),
            'headers' => json_encode($this->sanitizedHeaders($request), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'payload' => is_array($payload)
                ? json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                : null,
            'created_at' => $now->toDateTimeString(),
            'updated_at' => $now->toDateTimeString(),
        ]);
    }

    private function storedSemanticFingerprint(OperationalEvent $event): ?string
    {
        $fingerprint = data_get($event->diagnostics, 'semantic_fingerprint');

        if (is_string($fingerprint) && preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) === 1) {
            return $fingerprint;
        }

        $webhookCallId = data_get($event->diagnostics, 'latest_webhook_call_id')
            ?? data_get($event->diagnostics, 'webhook_call_id');

        if (! is_int($webhookCallId) && ! (is_string($webhookCallId) && ctype_digit($webhookCallId))) {
            return null;
        }

        $payload = DB::table('webhook_calls')->where('id', (int) $webhookCallId)->value('payload');

        if (! is_string($payload)) {
            return null;
        }

        try {
            return PayMongoWebhookEvent::fromRawBody($payload)->semanticFingerprint();
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @return array<string, list<string>> */
    private function sanitizedHeaders(Request $request): array
    {
        $headers = $request->headers->all();

        foreach (['paymongo-signature', 'authorization', 'cookie', 'set-cookie', 'x-api-key'] as $sensitiveHeader) {
            if (array_key_exists($sensitiveHeader, $headers)) {
                $headers[$sensitiveHeader] = ['[REDACTED]'];
            }
        }

        return $headers;
    }
}
