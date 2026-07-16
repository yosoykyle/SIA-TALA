<?php

namespace App\Actions\Integrations\Payments;

use InvalidArgumentException;
use JsonException;

final readonly class PayMongoWebhookEvent
{
    public const NormalizationVersion = 2;

    /** @param array<string, mixed> $resourceAttributes */
    private function __construct(
        public string $eventId,
        public string $eventType,
        public bool $livemode,
        public string $resourceId,
        public string $resourceType,
        public array $resourceAttributes,
        public string $payloadSha256,
        public string $envelopeVersion,
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

        return ($payload['event_type'] ?? null) === 'send.webhook'
            ? self::fromV2Payload($payload, $rawBody)
            : self::fromLegacyPayload($payload, $rawBody);
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
     * @return array{event_id:string,event_type:string,livemode:bool,checkout_session_id:?string,payment_id:?string,payment_intent_id:?string,provider_reference:?string,amount_centavos:?int,currency:?string,tala_reference:?string,status:?string,is_disputed:bool,has_refunds:bool,evidence_reason:?string}
     */
    public function paymentContext(): array
    {
        if ($this->resourceType === 'payment') {
            return $this->paymentResourceContext();
        }

        if ($this->resourceType === 'checkout_session') {
            if ($this->envelopeVersion === 'v1' && ! array_key_exists('payments', $this->resourceAttributes)) {
                return $this->legacyCheckoutSessionContext();
            }

            return $this->checkoutSessionContext();
        }

        return $this->context(
            checkoutSessionId: null,
            paymentId: null,
            paymentIntentId: null,
            providerReference: null,
            amount: null,
            currency: null,
            talaReference: self::metadataReference($this->resourceAttributes),
            status: null,
            paymentAttributes: [],
            evidenceReason: 'incompatible_payment_resource',
        );
    }

    public function semanticFingerprint(): string
    {
        $context = $this->paymentContext();

        return hash('sha256', json_encode([
            'event_type' => $this->eventType,
            'livemode' => $this->livemode,
            'checkout_session_id' => $context['checkout_session_id'],
            'payment_id' => $context['payment_id'],
            'payment_intent_id' => $context['payment_intent_id'],
            'provider_reference' => $context['provider_reference'],
            'amount_centavos' => $context['amount_centavos'],
            'currency' => $context['currency'],
            'tala_reference' => $context['tala_reference'],
            'status' => $context['status'],
            'is_disputed' => $context['is_disputed'],
            'has_refunds' => $context['has_refunds'],
            'evidence_reason' => $context['evidence_reason'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'event_version' => $this->envelopeVersion,
            'livemode' => $this->livemode,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function fromLegacyPayload(array $payload, string $rawBody): self
    {
        $data = $payload['data'] ?? null;

        if (! is_array($data) || ($data['type'] ?? null) !== 'event') {
            throw new InvalidArgumentException('invalid_event_data');
        }

        $eventId = self::requiredString($data['id'] ?? null, 'invalid_event_identity');
        $eventAttributes = $data['attributes'] ?? null;

        if (! is_array($eventAttributes)) {
            throw new InvalidArgumentException('invalid_event_identity');
        }

        $eventType = self::requiredString($eventAttributes['type'] ?? null, 'invalid_event_attributes');
        $livemode = $eventAttributes['livemode'] ?? null;
        $resource = $eventAttributes['data'] ?? null;

        if (! is_bool($livemode) || ! is_array($resource)) {
            throw new InvalidArgumentException('invalid_event_attributes');
        }

        return self::fromResource(
            eventId: $eventId,
            eventType: $eventType,
            livemode: $livemode,
            resource: $resource,
            rawBody: $rawBody,
            envelopeVersion: 'v1',
        );
    }

    /** @param array<string, mixed> $payload */
    private static function fromV2Payload(array $payload, string $rawBody): self
    {
        $data = $payload['data'] ?? null;

        if (! is_array($data)) {
            throw new InvalidArgumentException('invalid_event_data');
        }

        $eventType = self::requiredString($data['type'] ?? null, 'invalid_event_attributes');
        $livemode = $data['livemode'] ?? null;
        $resource = $data['data'] ?? null;

        if (! is_bool($livemode) || ! is_array($resource)) {
            throw new InvalidArgumentException('invalid_event_attributes');
        }

        $resourceId = self::requiredString($resource['id'] ?? null, 'invalid_event_resource');
        $providerEventId = self::optionalString($data['id'] ?? null);
        $eventId = $providerEventId ?? self::v2SemanticIdentity($eventType, $resourceId, $resource);

        return self::fromResource(
            eventId: $eventId,
            eventType: $eventType,
            livemode: $livemode,
            resource: $resource,
            rawBody: $rawBody,
            envelopeVersion: 'v2',
        );
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private static function fromResource(
        string $eventId,
        string $eventType,
        bool $livemode,
        array $resource,
        string $rawBody,
        string $envelopeVersion,
    ): self {
        $resourceId = self::requiredString($resource['id'] ?? null, 'invalid_event_resource');
        $resourceType = self::requiredString($resource['type'] ?? null, 'invalid_event_resource');
        $resourceAttributes = $resource['attributes'] ?? null;

        if (! is_array($resourceAttributes)) {
            throw new InvalidArgumentException('invalid_event_resource');
        }

        return new self(
            eventId: $eventId,
            eventType: $eventType,
            livemode: $livemode,
            resourceId: $resourceId,
            resourceType: $resourceType,
            resourceAttributes: $resourceAttributes,
            payloadSha256: hash('sha256', $rawBody),
            envelopeVersion: $envelopeVersion,
        );
    }

    /**
     * @return array{event_id:string,event_type:string,livemode:bool,checkout_session_id:?string,payment_id:?string,payment_intent_id:?string,provider_reference:?string,amount_centavos:?int,currency:?string,tala_reference:?string,status:?string,is_disputed:bool,has_refunds:bool,evidence_reason:?string}
     */
    private function legacyCheckoutSessionContext(): array
    {
        $metadata = $this->resourceAttributes['metadata'] ?? null;
        $metadata = is_array($metadata) ? $metadata : [];
        $checkoutSessionId = $this->resourceId;
        $paymentId = self::optionalString($this->resourceAttributes['payment_id'] ?? null);
        $paymentIntentId = self::optionalString($this->resourceAttributes['payment_intent_id'] ?? null);
        $currency = self::optionalString($this->resourceAttributes['currency'] ?? null);
        $talaReference = self::optionalString($metadata['tala_reference'] ?? null);

        return $this->context(
            checkoutSessionId: $checkoutSessionId,
            paymentId: $paymentId,
            paymentIntentId: $paymentIntentId,
            providerReference: $checkoutSessionId,
            amount: $this->resourceAttributes['amount_paid'] ?? null,
            currency: $currency,
            talaReference: $talaReference,
            status: self::optionalString($this->resourceAttributes['status'] ?? null),
            paymentAttributes: $this->resourceAttributes,
            evidenceReason: null,
        );
    }

    /**
     * @return array{event_id:string,event_type:string,livemode:bool,checkout_session_id:?string,payment_id:?string,payment_intent_id:?string,provider_reference:?string,amount_centavos:?int,currency:?string,tala_reference:?string,status:?string,is_disputed:bool,has_refunds:bool,evidence_reason:?string}
     */
    private function paymentResourceContext(): array
    {
        $paymentId = $this->resourceId;

        return $this->context(
            checkoutSessionId: self::optionalString($this->resourceAttributes['checkout_session_id'] ?? null),
            paymentId: $paymentId,
            paymentIntentId: self::optionalString($this->resourceAttributes['payment_intent_id'] ?? null),
            providerReference: $paymentId,
            amount: $this->resourceAttributes['amount'] ?? null,
            currency: self::optionalString($this->resourceAttributes['currency'] ?? null),
            talaReference: self::metadataReference($this->resourceAttributes),
            status: self::optionalString($this->resourceAttributes['status'] ?? null),
            paymentAttributes: $this->resourceAttributes,
            evidenceReason: null,
        );
    }

    /**
     * @return array{event_id:string,event_type:string,livemode:bool,checkout_session_id:?string,payment_id:?string,payment_intent_id:?string,provider_reference:?string,amount_centavos:?int,currency:?string,tala_reference:?string,status:?string,is_disputed:bool,has_refunds:bool,evidence_reason:?string}
     */
    private function checkoutSessionContext(): array
    {
        $metadataReference = self::metadataReference($this->resourceAttributes);
        $referenceNumber = self::optionalString($this->resourceAttributes['reference_number'] ?? null);
        $evidenceReason = $metadataReference !== null && $referenceNumber !== null && $metadataReference !== $referenceNumber
            ? 'reference_metadata_conflict'
            : null;
        $hasPayments = array_key_exists('payments', $this->resourceAttributes);
        $payments = $this->resourceAttributes['payments'] ?? null;
        $paidPayments = [];
        $hasIncompatiblePayment = false;
        $hasMalformedPayment = false;

        if (is_array($payments)) {
            foreach ($payments as $payment) {
                if (! is_array($payment)) {
                    $hasMalformedPayment = true;

                    continue;
                }

                $attributes = $payment['attributes'] ?? null;
                $paymentType = self::optionalString($payment['type'] ?? null);

                if ($paymentType === null || ! is_array($attributes)) {
                    $hasMalformedPayment = true;

                    continue;
                }

                if ($paymentType !== 'payment') {
                    $hasIncompatiblePayment = true;

                    continue;
                }

                if (strtolower((string) ($attributes['status'] ?? '')) === 'paid') {
                    $paidPayments[] = $payment;
                }
            }
        }

        if (! $hasPayments) {
            $evidenceReason ??= 'missing_paid_payment';
        } elseif (! is_array($payments)) {
            $evidenceReason ??= 'malformed_payments';
        } elseif ($hasMalformedPayment) {
            $evidenceReason ??= 'malformed_payment_resource';
        } elseif ($hasIncompatiblePayment) {
            $evidenceReason ??= 'incompatible_payment_resource';
        } elseif ($paidPayments === []) {
            $evidenceReason ??= 'missing_paid_payment';
        } elseif (count($paidPayments) > 1) {
            $evidenceReason ??= 'ambiguous_paid_payments';
        }

        $payment = count($paidPayments) === 1 ? $paidPayments[0] : null;
        $paymentAttributes = is_array($payment['attributes'] ?? null) ? $payment['attributes'] : [];
        $paymentId = self::optionalString($payment['id'] ?? null);

        if ($payment !== null && $paymentId === null) {
            $evidenceReason ??= 'missing_payment_id';
        }

        $paymentIntentIds = array_values(array_unique(array_filter([
            self::optionalString(data_get($this->resourceAttributes, 'payment_intent.id')),
            self::optionalString($paymentAttributes['payment_intent_id'] ?? null),
            self::optionalString($this->resourceAttributes['payment_intent_id'] ?? null),
        ], static fn (?string $paymentIntentId): bool => $paymentIntentId !== null)));
        $paymentIntentId = count($paymentIntentIds) === 1 ? $paymentIntentIds[0] : null;

        if (count($paymentIntentIds) > 1) {
            $evidenceReason ??= 'payment_intent_conflict';
        }

        return $this->context(
            checkoutSessionId: $this->resourceType === 'checkout_session' ? $this->resourceId : null,
            paymentId: $paymentId,
            paymentIntentId: $paymentIntentId,
            providerReference: $paymentId,
            amount: $paymentAttributes['amount'] ?? null,
            currency: self::optionalString($paymentAttributes['currency'] ?? null),
            talaReference: $metadataReference ?? $referenceNumber,
            status: self::optionalString($paymentAttributes['status'] ?? null),
            paymentAttributes: $paymentAttributes,
            evidenceReason: $evidenceReason,
        );
    }

    /**
     * @param  array<string, mixed>  $paymentAttributes
     * @return array{event_id:string,event_type:string,livemode:bool,checkout_session_id:?string,payment_id:?string,payment_intent_id:?string,provider_reference:?string,amount_centavos:?int,currency:?string,tala_reference:?string,status:?string,is_disputed:bool,has_refunds:bool,evidence_reason:?string}
     */
    private function context(
        ?string $checkoutSessionId,
        ?string $paymentId,
        ?string $paymentIntentId,
        ?string $providerReference,
        mixed $amount,
        ?string $currency,
        ?string $talaReference,
        ?string $status,
        array $paymentAttributes,
        ?string $evidenceReason,
    ): array {
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
            'status' => $status !== null ? strtolower($status) : null,
            'is_disputed' => ($paymentAttributes['disputed'] ?? false) === true,
            'has_refunds' => is_array($paymentAttributes['refunds'] ?? null)
                && $paymentAttributes['refunds'] !== [],
            'evidence_reason' => $evidenceReason,
        ];
    }

    /** @param array<string, mixed> $resource */
    private static function v2SemanticIdentity(string $eventType, string $resourceId, array $resource): string
    {
        $paymentIds = [];
        $payments = data_get($resource, 'attributes.payments');

        if (is_array($payments)) {
            foreach ($payments as $payment) {
                if (is_array($payment)) {
                    $paymentId = self::optionalString($payment['id'] ?? null);

                    if ($paymentId !== null) {
                        $paymentIds[] = $paymentId;
                    }
                }
            }
        }

        if (($resource['type'] ?? null) === 'payment') {
            $paymentIds[] = $resourceId;
        }

        $paymentIds = array_values(array_unique($paymentIds));
        sort($paymentIds);

        return 'paymongo:v2:'.hash('sha256', json_encode([
            'event_type' => $eventType,
            'checkout_or_resource_id' => $resourceId,
            'payment_ids' => $paymentIds,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $attributes */
    private static function metadataReference(array $attributes): ?string
    {
        $metadata = $attributes['metadata'] ?? null;

        return is_array($metadata) ? self::optionalString($metadata['tala_reference'] ?? null) : null;
    }

    private static function requiredString(mixed $value, string $reason): string
    {
        $value = self::optionalString($value);

        if ($value === null) {
            throw new InvalidArgumentException($reason);
        }

        return $value;
    }

    private static function optionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
