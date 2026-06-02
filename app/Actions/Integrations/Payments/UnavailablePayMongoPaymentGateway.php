<?php

namespace App\Actions\Integrations\Payments;

use RuntimeException;

class UnavailablePayMongoPaymentGateway implements PaymentGateway
{
    public function createCheckoutSession(PaymentCheckoutRequest $request): PaymentCheckoutSession
    {
        throw new RuntimeException('PayMongo checkout is intentionally disabled in this phase. Use TALA_PAYMENT_GATEWAY_DRIVER=mock until live checkout payloads, webhook signatures, idempotency, and retry tests are implemented.');
    }
}
