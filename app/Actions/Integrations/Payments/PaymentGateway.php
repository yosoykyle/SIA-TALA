<?php

namespace App\Actions\Integrations\Payments;

interface PaymentGateway
{
    public function createCheckoutSession(PaymentCheckoutRequest $request): PaymentCheckoutSession;
}
