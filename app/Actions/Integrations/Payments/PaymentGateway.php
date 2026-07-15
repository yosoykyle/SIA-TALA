<?php

namespace App\Actions\Integrations\Payments;

interface PaymentGateway
{
    public function provider(): string;

    public function createCheckoutSession(PaymentCheckoutRequest $request, string $idempotencyKey): PaymentCheckoutSession;

    public function retrieveCheckoutSession(string $checkoutSessionId): PaymentCheckoutSession;

    public function expireCheckoutSession(string $checkoutSessionId): PaymentCheckoutSession;
}
