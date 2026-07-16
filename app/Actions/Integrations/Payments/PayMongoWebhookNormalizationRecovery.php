<?php

namespace App\Actions\Integrations\Payments;

use App\Models\OperationalEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

final class PayMongoWebhookNormalizationRecovery
{
    public function recover(
        OperationalEvent $operationalEvent,
        PayMongoWebhookEvent $incomingEvent,
        int $webhookCallId,
        int $deliveryCount,
        CarbonImmutable $now,
    ): bool {
        $diagnostics = $operationalEvent->diagnostics ?? [];
        $normalizationVersion = $this->recoverableNormalizationVersion($diagnostics);

        if (
            $normalizationVersion === null
            || $operationalEvent->status !== OperationalEvent::StatusReviewRequired
            || ($diagnostics['reason'] ?? null) !== 'payment_status_mismatch'
            || array_key_exists('normalization_recovery', $diagnostics)
            || ! $incomingEvent->isSupported()
            || $incomingEvent->eventType !== 'checkout_session.payment.paid'
            || $incomingEvent->resourceType !== 'checkout_session'
            || $incomingEvent->livemode
            || config('tala_integrations.payments.paymongo.livemode') !== false
            || ! $this->matchesOriginalPayload($diagnostics, $incomingEvent)
        ) {
            return false;
        }

        $cleanDiagnostics = Arr::except($diagnostics, [
            'reason',
            'outcome',
            'exception_class',
            'dispatch_queued_at',
            'conflicting_payload_sha256',
            'conflicting_semantic_fingerprint',
        ]);

        $operationalEvent->forceFill([
            'status' => OperationalEvent::StatusPending,
            'processed_at' => null,
            'failed_at' => null,
            'diagnostics' => [
                ...$cleanDiagnostics,
                'normalization_version' => PayMongoWebhookEvent::NormalizationVersion,
                'normalization_recovery' => [
                    'from_version' => $normalizationVersion,
                    'to_version' => PayMongoWebhookEvent::NormalizationVersion,
                    'reason' => 'payment_status_mismatch',
                    'recovered_at' => $now->toIso8601String(),
                ],
                'semantic_fingerprint' => $incomingEvent->semanticFingerprint(),
                'latest_payload_sha256' => $incomingEvent->payloadSha256,
                'latest_webhook_call_id' => $webhookCallId,
                'delivery_count' => $deliveryCount,
            ],
        ])->save();

        return true;
    }

    /** @param array<string, mixed> $diagnostics */
    private function recoverableNormalizationVersion(array $diagnostics): ?int
    {
        if (! array_key_exists('normalization_version', $diagnostics)) {
            return 0;
        }

        $version = $diagnostics['normalization_version'];

        if (! is_int($version) || $version < 0 || $version >= PayMongoWebhookEvent::NormalizationVersion) {
            return null;
        }

        return $version;
    }

    /** @param array<string, mixed> $diagnostics */
    private function matchesOriginalPayload(array $diagnostics, PayMongoWebhookEvent $incomingEvent): bool
    {
        $originalPayloadSha256 = $diagnostics['payload_sha256'] ?? null;

        return is_string($originalPayloadSha256)
            && preg_match('/\A[a-f0-9]{64}\z/', $originalPayloadSha256) === 1
            && hash_equals($originalPayloadSha256, $incomingEvent->payloadSha256);
    }
}
