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
        return in_array($this->eventType, self::supportedEventTypes(), true);
    }

    /** @return list<string> */
    public static function supportedEventTypes(): array
    {
        return [
            'checkout_session.payment.paid',
            'payment.paid',
            'payment.failed',
            'payment.refunded',
            'payment.refund.updated',
        ];
    }

    /**
     * @return array{event_id:string,event_type:string,livemode:bool,checkout_session_id:?string,payment_id:?string,payment_intent_id:?string,provider_reference:?string,amount_centavos:?int,currency:?string,tala_reference:?string,status:?string,is_disputed:bool,has_refunds:bool}
     */
    public function paymentContext(): array
    {
        $metadata = $this->resourceAttributes['metadata'] ?? null;
        $metadata = is_array($metadata) ? $metadata : [];
        $checkoutSessionId = $this->resourceType === 'checkout_session'
            ? $this->resourceId
            : $this->stringValue($this->resourceAttributes['checkout_session_id'] ?? null);
        $paymentId = $this->resourceType === 'payment'
            ? $this->resourceId
            : $this->stringValue($this->resourceAttributes['payment_id'] ?? null);
        $paymentIntentId = $this->stringValue($this->resourceAttributes['payment_intent_id'] ?? null);
        $amount = $this->resourceType === 'checkout_session'
            ? ($this->resourceAttributes['amount_paid'] ?? null)
            : ($this->resourceAttributes['amount'] ?? null);
        $currency = $this->stringValue($this->resourceAttributes['currency'] ?? null);
        $talaReference = $this->stringValue($metadata['tala_reference'] ?? null);
        $providerReference = in_array($this->eventType, ['payment.refunded', 'payment.refund.updated'], true)
            ? $paymentId
            : ($checkoutSessionId ?? $paymentId ?? $paymentIntentId);

        return [
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'livemode' => $this->livemode,
            'checkout_session_id' => $checkoutSessionId,
            'payment_id' => $paymentId,
            'payment_intent_id' => $paymentIntentId,
            'provider_reference' => $providerReference,
            'amount_centavos' => is_int($amount) && $amount >= 0 ? $amount : null,
            'currency' => $currency !== null ? strtoupper($currency) : null,
            'tala_reference' => $talaReference,
            'status' => $this->stringValue($this->resourceAttributes['status'] ?? null),
            'is_disputed' => ($this->resourceAttributes['disputed'] ?? false) === true,
            'has_refunds' => is_array($this->resourceAttributes['refunds'] ?? null)
                && $this->resourceAttributes['refunds'] !== [],
        ];
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

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
