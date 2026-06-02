<?php

namespace App\Actions\Integrations\Payments;

final readonly class PaymentCheckoutSession
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $provider,
        public string $checkoutSessionId,
        public string $checkoutUrl,
        public string $status = 'pending',
        public array $metadata = [],
    ) {}
}
