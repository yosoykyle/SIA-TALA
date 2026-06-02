<?php

namespace App\Actions\Integrations\Payments;

use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CreatePaymentCheckoutSession
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly DecimalMoney $money,
    ) {}

    /**
     * @return array{payment_attempt_id:int, provider:string, provider_checkout_session_id:string, checkout_url:string, status:string, amount:string}
     */
    public function create(PaymentCheckoutRequest $request): array
    {
        $amount = $this->money->normalize($request->amount);

        if (! $this->money->greaterThanZero($amount)) {
            throw new RuntimeException('Checkout amount must be greater than zero.');
        }

        $normalizedRequest = new PaymentCheckoutRequest(
            studentProfileId: $request->studentProfileId,
            amount: $amount,
            description: $request->description,
            channel: $request->channel,
            termId: $request->termId,
            enrollmentId: $request->enrollmentId,
            ledgerEntryId: $request->ledgerEntryId,
            successUrl: $request->successUrl,
            cancelUrl: $request->cancelUrl,
            metadata: $request->metadata,
        );

        $session = $this->gateway->createCheckoutSession($normalizedRequest);
        $createdAt = CarbonImmutable::now(config('app.timezone'));

        $paymentAttemptId = DB::table('payment_attempts')->insertGetId([
            'student_profile_id' => $normalizedRequest->studentProfileId,
            'term_id' => $normalizedRequest->termId,
            'enrollment_id' => $normalizedRequest->enrollmentId,
            'ledger_entry_id' => $normalizedRequest->ledgerEntryId,
            'channel' => $normalizedRequest->channel,
            'status' => $session->status,
            'provider' => $session->provider,
            'provider_checkout_session_id' => $session->checkoutSessionId,
            'provider_payment_intent_id' => $session->metadata['payment_intent_id'] ?? null,
            'amount' => $amount,
            'meta' => json_encode([
                'checkout_url' => $session->checkoutUrl,
                'success_url' => $normalizedRequest->successUrl,
                'cancel_url' => $normalizedRequest->cancelUrl,
                'request' => $normalizedRequest->metadata,
                'gateway' => $session->metadata,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $createdAt->toDateTimeString(),
            'updated_at' => $createdAt->toDateTimeString(),
        ]);

        return [
            'payment_attempt_id' => $paymentAttemptId,
            'provider' => $session->provider,
            'provider_checkout_session_id' => $session->checkoutSessionId,
            'checkout_url' => $session->checkoutUrl,
            'status' => $session->status,
            'amount' => $amount,
        ];
    }
}
