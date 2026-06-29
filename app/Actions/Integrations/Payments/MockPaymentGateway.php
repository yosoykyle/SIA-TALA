<?php

namespace App\Actions\Integrations\Payments;

use Illuminate\Support\Str;

class MockPaymentGateway implements PaymentGateway
{
    public function __construct(
        private readonly string $provider,
        private readonly string $checkoutBaseUrl,
    ) {}

    public function createCheckoutSession(PaymentCheckoutRequest $request): PaymentCheckoutSession
    {
        $sessionId = 'mock_checkout_'.Str::uuid()->toString();

        return new PaymentCheckoutSession(
            provider: $this->provider,
            checkoutSessionId: $sessionId,
            checkoutUrl: rtrim($this->checkoutBaseUrl, '/').'/'.$sessionId,
            metadata: [
                'driver' => 'mock',
                'student_profile_id' => $request->studentProfileId,
                'enrollment_id' => $request->enrollmentId,
                'assessment_id' => $request->assessmentId,
                'tala_reference' => $request->metadata['tala_reference'] ?? null,
                'amount' => $request->amount,
                'description' => $request->description,
            ],
        );
    }
}
