<?php

namespace App\Actions\Integrations\Payments;

final readonly class PaymentCheckoutSession
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<array<string, mixed>>  $payments
     */
    public function __construct(
        public string $provider,
        public string $checkoutSessionId,
        public string $checkoutUrl,
        public string $status = 'active',
        public array $metadata = [],
        public ?string $referenceNumber = null,
        public array $payments = [],
    ) {}
}
