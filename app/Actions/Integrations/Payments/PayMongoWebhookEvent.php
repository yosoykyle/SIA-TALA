<?php

namespace App\Actions\Integrations\Payments;

use InvalidArgumentException;
use JsonException;

final readonly class PayMongoWebhookEvent
{
    /** @param array<string, mixed> $resourceAttributes */
    private function __construct(
        public string $eventId,
        public string $eventType,
        public bool $livemode,
        public string $resourceId,
        public string $resourceType,
        public array $resourceAttributes,
        public string $payloadSha256,
    ) {}

    public static function fromRawBody(string $rawBody): self
    {
        try {
            $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('invalid_json');
        }

        if (! is_array($payload)) {
            throw new InvalidArgumentException('invalid_root');
        }

        $data = $payload['data'] ?? null;

        if (! is_array($data) || ($data['type'] ?? null) !== 'event') {
            throw new InvalidArgumentException('invalid_event_data');
        }

        $eventId = $data['id'] ?? null;
        $eventAttributes = $data['attributes'] ?? null;

        if (! is_string($eventId) || trim($eventId) === '' || ! is_array($eventAttributes)) {
            throw new InvalidArgumentException('invalid_event_identity');
        }

        $eventType = $eventAttributes['type'] ?? null;
        $livemode = $eventAttributes['livemode'] ?? null;
        $resource = $eventAttributes['data'] ?? null;

        if (! is_string($eventType) || trim($eventType) === '' || ! is_bool($livemode) || ! is_array($resource)) {
            throw new InvalidArgumentException('invalid_event_attributes');
        }

        $resourceId = $resource['id'] ?? null;
        $resourceType = $resource['type'] ?? null;
        $resourceAttributes = $resource['attributes'] ?? null;

        if (
            ! is_string($resourceId) || trim($resourceId) === ''
            || ! is_string($resourceType) || trim($resourceType) === ''
            || ! is_array($resourceAttributes)
        ) {
            throw new InvalidArgumentException('invalid_event_resource');
        }

        return new self(
            eventId: trim($eventId),
            eventType: trim($eventType),
            livemode: $livemode,
            resourceId: trim($resourceId),
            resourceType: trim($resourceType),
            resourceAttributes: $resourceAttributes,
            payloadSha256: hash('sha256', $rawBody),
        );
    }

    public function isSupported(): bool
    {
        return in_array($this->eventType, [
            'checkout_session.payment.paid',
            'payment.paid',
            'payment.failed',
            'payment.refunded',
            'payment.refund.updated',
        ], true);
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'livemode' => $this->livemode,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
        ];
    }
}
