<?php

namespace App\Actions\Integrations\Payments;

use App\Support\DecimalMoney;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PayMongoPaymentGateway implements PaymentGateway
{
    /**
     * @param  list<string>  $paymentMethodTypes
     */
    public function __construct(
        private readonly DecimalMoney $money,
        private readonly string $baseUrl,
        private readonly ?string $secretKey,
        private readonly array $paymentMethodTypes,
    ) {}

    public function createCheckoutSession(PaymentCheckoutRequest $request): PaymentCheckoutSession
    {
        $secretKey = $this->secretKey();
        $payload = $this->payload($request);

        try {
            $response = Http::withBasicAuth($secretKey, '')
                ->acceptJson()
                ->asJson()
                ->timeout(15)
                ->retry(3, 250)
                ->post($this->checkoutSessionUrl(), $payload)
                ->throw();
        } catch (Throwable $exception) {
            throw new RuntimeException('PayMongo checkout session could not be created.', 0, $exception);
        }

        $responsePayload = $response->json();
        $checkoutSessionId = $this->requiredString($responsePayload, 'data.id', 'PayMongo did not return a checkout session ID.');
        $checkoutUrl = $this->requiredString(
            $responsePayload,
            'data.attributes.checkout_url',
            'PayMongo did not return a checkout URL.',
            'data.attributes.url',
        );

        return new PaymentCheckoutSession(
            provider: 'paymongo',
            checkoutSessionId: $checkoutSessionId,
            checkoutUrl: $checkoutUrl,
            status: 'pending',
            metadata: [
                'driver' => 'paymongo',
                'provider_status' => data_get($responsePayload, 'data.attributes.status'),
                'livemode' => data_get($responsePayload, 'data.attributes.livemode'),
                'payment_intent_id' => data_get($responsePayload, 'data.attributes.payment_intent.id')
                    ?? data_get($responsePayload, 'data.attributes.payment_intent_id'),
                'amount_centavos' => $this->money->toCents($request->amount),
            ],
        );
    }

    /**
     * @return array{data:array{attributes:array<string, mixed>}}
     */
    private function payload(PaymentCheckoutRequest $request): array
    {
        $attributes = [
            'send_email_receipt' => false,
            'show_description' => true,
            'show_line_items' => true,
            'description' => $request->description,
            'line_items' => [
                [
                    'currency' => 'PHP',
                    'amount' => $this->money->toCents($request->amount),
                    'name' => $this->lineItemName($request->description),
                    'quantity' => 1,
                ],
            ],
            'payment_method_types' => $this->configuredPaymentMethods(),
        ];

        if ($request->successUrl !== null && trim($request->successUrl) !== '') {
            $attributes['success_url'] = $request->successUrl;
        }

        if ($request->cancelUrl !== null && trim($request->cancelUrl) !== '') {
            $attributes['cancel_url'] = $request->cancelUrl;
        }

        return ['data' => ['attributes' => $attributes]];
    }

    private function secretKey(): string
    {
        $secretKey = trim((string) $this->secretKey);

        if ($secretKey === '') {
            throw new RuntimeException('PayMongo secret key is not configured.');
        }

        return $secretKey;
    }

    private function checkoutSessionUrl(): string
    {
        return rtrim($this->baseUrl, '/').'/checkout_sessions';
    }

    /**
     * @return list<string>
     */
    private function configuredPaymentMethods(): array
    {
        $methods = array_values(array_filter(
            array_map(
                static fn (mixed $method): string => strtolower(trim((string) $method)),
                $this->paymentMethodTypes,
            ),
            static fn (string $method): bool => $method !== '',
        ));

        return $methods !== [] ? $methods : ['gcash', 'card'];
    }

    private function lineItemName(string $description): string
    {
        $name = trim($description);

        return $name !== ''
            ? Str::limit($name, 120, '')
            : 'TALA Payment';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredString(array $payload, string $primaryKey, string $message, ?string $fallbackKey = null): string
    {
        $value = data_get($payload, $primaryKey);

        if (($value === null || trim((string) $value) === '') && $fallbackKey !== null) {
            $value = data_get($payload, $fallbackKey);
        }

        if ($value === null || trim((string) $value) === '') {
            throw new RuntimeException($message);
        }

        return (string) $value;
    }
}
