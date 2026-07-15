<?php

namespace App\Actions\Integrations\Payments;

use Illuminate\Support\Str;

class MockPaymentGateway implements PaymentGateway
{
    /** @var array<string, PaymentCheckoutSession> */
    private array $sessions = [];

    /** @var array<string, string> */
    private array $idempotentSessions = [];

    public function __construct(
        private readonly string $providerName,
        private readonly string $checkoutBaseUrl,
    ) {}

    public function provider(): string
    {
        return $this->providerName;
    }

    public function createCheckoutSession(PaymentCheckoutRequest $request, string $idempotencyKey): PaymentCheckoutSession
    {
        if (isset($this->idempotentSessions[$idempotencyKey])) {
            return $this->sessions[$this->idempotentSessions[$idempotencyKey]];
        }

        $sessionId = 'mock_checkout_'.Str::uuid()->toString();
        $session = new PaymentCheckoutSession(
            provider: $this->providerName,
            checkoutSessionId: $sessionId,
            checkoutUrl: rtrim($this->checkoutBaseUrl, '/').'/'.$sessionId,
            status: 'active',
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
        $this->sessions[$sessionId] = $session;
        $this->idempotentSessions[$idempotencyKey] = $sessionId;

        return $session;
    }

    public function retrieveCheckoutSession(string $checkoutSessionId): PaymentCheckoutSession
    {
        return $this->sessions[$checkoutSessionId] ?? new PaymentCheckoutSession(
            provider: $this->providerName,
            checkoutSessionId: $checkoutSessionId,
            checkoutUrl: rtrim($this->checkoutBaseUrl, '/').'/'.$checkoutSessionId,
            status: 'active',
            metadata: ['driver' => 'mock'],
        );
    }

    public function expireCheckoutSession(string $checkoutSessionId): PaymentCheckoutSession
    {
        $session = $this->retrieveCheckoutSession($checkoutSessionId);
        $expired = new PaymentCheckoutSession(
            provider: $session->provider,
            checkoutSessionId: $session->checkoutSessionId,
            checkoutUrl: $session->checkoutUrl,
            status: 'expired',
            metadata: $session->metadata,
        );
        $this->sessions[$checkoutSessionId] = $expired;

        return $expired;
    }
}
