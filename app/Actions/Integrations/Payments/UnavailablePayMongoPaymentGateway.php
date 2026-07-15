<?php

namespace App\Actions\Integrations\Payments;

class UnavailablePayMongoPaymentGateway implements PaymentGateway
{
    public function provider(): string
    {
        return 'paymongo';
    }

    public function createCheckoutSession(PaymentCheckoutRequest $request, string $idempotencyKey): PaymentCheckoutSession
    {
        throw $this->unavailable();
    }

    public function retrieveCheckoutSession(string $checkoutSessionId): PaymentCheckoutSession
    {
        throw $this->unavailable();
    }

    public function expireCheckoutSession(string $checkoutSessionId): PaymentCheckoutSession
    {
        throw $this->unavailable();
    }

    private function unavailable(): PaymentGatewayException
    {
        return new PaymentGatewayException(
            message: 'Payment checkout is temporarily unavailable.',
            errorCode: 'gateway_disabled',
            retryable: false,
            indeterminate: false,
        );
    }
}
