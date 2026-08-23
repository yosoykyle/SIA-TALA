<?php

namespace App\Actions\Integrations\Payments;

use App\Support\DecimalMoney;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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

    public function provider(): string
    {
        return 'paymongo';
    }

    public function createCheckoutSession(PaymentCheckoutRequest $request, string $idempotencyKey): PaymentCheckoutSession
    {
        $payload = $this->send(
            method: 'POST',
            url: $this->apiUrl('/v2/checkout_sessions'),
            payload: $this->payload($request, $idempotencyKey),
            idempotencyKey: $idempotencyKey,
        );

        return $this->checkoutSession($payload, 'create');
    }

    public function retrieveCheckoutSession(string $checkoutSessionId): PaymentCheckoutSession
    {
        $payload = $this->send(
            method: 'GET',
            url: $this->apiUrl('/v1/checkout_sessions/'.rawurlencode($checkoutSessionId)),
        );

        return $this->checkoutSession($payload, 'retrieve');
    }

    public function expireCheckoutSession(string $checkoutSessionId): PaymentCheckoutSession
    {
        $payload = $this->send(
            method: 'POST',
            url: $this->apiUrl('/v1/checkout_sessions/'.rawurlencode($checkoutSessionId).'/expire'),
        );

        return $this->checkoutSession($payload, 'expire');
    }

    /**
     * @return array{data:array{attributes:array<string, mixed>}}
     */
    private function payload(PaymentCheckoutRequest $request, string $idempotencyKey): array
    {
        $attributes = [
            'send_email_receipt' => false,
            'show_description' => true,
            'show_line_items' => true,
            'pass_on_fees' => false,
            'description' => $request->description,
            'reference_number' => (string) ($request->metadata['tala_reference'] ?? $idempotencyKey),
            'line_items' => [
                [
                    'currency' => 'PHP',
                    'amount' => $this->money->toCents($request->amount),
                    'name' => $this->lineItemName($request->description),
                    'quantity' => 1,
                ],
            ],
            'payment_method_types' => $this->configuredPaymentMethods(),
            'metadata' => array_map(
                static fn (mixed $value): string => (string) $value,
                array_filter([
                    'tala_reference' => $request->metadata['tala_reference'] ?? null,
                    'assessment_id' => $request->metadata['assessment_id'] ?? null,
                    'assessment_version' => $request->assessmentVersion,
                    'term_account_id' => $request->termAccountId,
                    'snapshot_checksum' => $request->snapshotChecksum,
                    'enrollment_id' => $request->metadata['enrollment_id'] ?? null,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            ),
        ];

        if (filled($request->successUrl)) {
            $attributes['success_url'] = $request->successUrl;
        }

        if (filled($request->cancelUrl)) {
            $attributes['cancel_url'] = $request->cancelUrl;
        }

        return ['data' => ['attributes' => $attributes]];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function checkoutSession(array $payload, string $operation): PaymentCheckoutSession
    {
        $checkoutSessionId = $this->requiredString($payload, 'data.id', 'checkout_session_id_missing', $operation);
        $checkoutUrl = $this->requiredString($payload, 'data.attributes.checkout_url', 'checkout_url_missing', $operation);
        $status = strtolower((string) data_get($payload, 'data.attributes.status', 'active'));

        return new PaymentCheckoutSession(
            provider: 'paymongo',
            checkoutSessionId: $checkoutSessionId,
            checkoutUrl: $checkoutUrl,
            status: $status,
            metadata: [
                'driver' => 'paymongo',
                'provider_status' => $status,
                'livemode' => data_get($payload, 'data.attributes.livemode'),
                'payment_intent_id' => data_get($payload, 'data.attributes.payment_intent.id')
                    ?? data_get($payload, 'data.attributes.payment_intent_id'),
                'expires_at' => data_get($payload, 'data.attributes.expires_at'),
            ],
            referenceNumber: $this->optionalString(data_get($payload, 'data.attributes.reference_number')),
            payments: $this->sanitizedPayments(data_get($payload, 'data.attributes.payments')),
        );
    }

    /**
     * @return list<array{
     *     id:string,
     *     status:string,
     *     amount_centavos:int|null,
     *     currency:string,
     *     livemode:bool|null,
     *     payment_intent_id:string|null,
     *     disputed:bool|null,
     *     has_refunds:bool|null,
     *     paid_at:int|null
     * }>
     */
    private function sanitizedPayments(mixed $payments): array
    {
        if (! is_array($payments)) {
            return [];
        }

        return collect($payments)
            ->filter(fn (mixed $payment): bool => is_array($payment) && filled(data_get($payment, 'id')))
            ->map(function (array $payment): array {
                $amount = data_get($payment, 'attributes.amount');
                $paidAt = data_get($payment, 'attributes.paid_at');
                $livemode = data_get($payment, 'attributes.livemode');
                $disputed = data_get($payment, 'attributes.disputed');
                $refunds = data_get($payment, 'attributes.refunds');

                return [
                    'id' => (string) data_get($payment, 'id'),
                    'status' => strtolower((string) data_get($payment, 'attributes.status', 'unknown')),
                    'amount_centavos' => is_numeric($amount) ? (int) $amount : null,
                    'currency' => strtoupper((string) data_get($payment, 'attributes.currency', '')),
                    'livemode' => is_bool($livemode) ? $livemode : null,
                    'payment_intent_id' => $this->optionalString(
                        data_get($payment, 'attributes.payment_intent_id')
                            ?? data_get($payment, 'attributes.payment_intent.id'),
                    ),
                    'disputed' => is_bool($disputed) ? $disputed : null,
                    'has_refunds' => is_array($refunds) ? $refunds !== [] : null,
                    'paid_at' => is_numeric($paidAt) ? (int) $paidAt : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(string $method, string $url, array $payload = [], ?string $idempotencyKey = null): array
    {
        $response = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = $this->request($idempotencyKey)->send($method, $url, $payload === [] ? [] : ['json' => $payload]);
            } catch (ConnectionException $exception) {
                if ($attempt < 3) {
                    usleep(200_000 * $attempt);

                    continue;
                }

                throw new PaymentGatewayException(
                    message: 'Payment provider connection failed.',
                    errorCode: 'connection_failed',
                    retryable: true,
                    indeterminate: true,
                    previous: $exception,
                );
            }

            if ($this->shouldRetry($response) && $attempt < 3) {
                usleep(200_000 * $attempt);

                continue;
            }

            break;
        }

        if (! $response instanceof Response) {
            throw new PaymentGatewayException('Payment provider returned no response.', 'missing_response', true, true);
        }

        if ($response->failed()) {
            throw $this->responseException($response);
        }

        $responsePayload = $response->json();

        if (! is_array($responsePayload)) {
            throw new PaymentGatewayException(
                message: 'Payment provider returned an invalid response.',
                errorCode: 'malformed_response',
                retryable: false,
                indeterminate: true,
                httpStatus: $response->status(),
            );
        }

        return $responsePayload;
    }

    private function request(?string $idempotencyKey): PendingRequest
    {
        $request = Http::withBasicAuth($this->secretKey(), '')
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(20);

        return filled($idempotencyKey)
            ? $request->withHeader('Idempotency-Key', (string) $idempotencyKey)
            : $request;
    }

    private function responseException(Response $response): PaymentGatewayException
    {
        $status = $response->status();
        $errorCode = (string) (
            data_get($response->json(), 'errors.0.code')
            ?? data_get($response->json(), 'errors.0.attributes.code')
            ?? 'http_'.$status
        );
        $retryable = $status === 408 || $status === 429 || $status >= 500;

        return new PaymentGatewayException(
            message: 'Payment provider rejected the request.',
            errorCode: Str::limit($errorCode, 100, ''),
            retryable: $retryable,
            indeterminate: $retryable,
            httpStatus: $status,
        );
    }

    private function shouldRetry(Response $response): bool
    {
        return $response->status() === 408 || $response->status() === 429 || $response->serverError();
    }

    private function secretKey(): string
    {
        $secretKey = trim((string) $this->secretKey);

        if ($secretKey === '') {
            throw new PaymentGatewayException(
                message: 'Payment provider is not configured.',
                errorCode: 'secret_key_missing',
                retryable: false,
                indeterminate: false,
            );
        }

        return $secretKey;
    }

    private function apiUrl(string $path): string
    {
        $baseUrl = preg_replace('#/(?:v1|v2)/?$#', '', rtrim($this->baseUrl, '/'));

        return rtrim((string) $baseUrl, '/').$path;
    }

    /** @return list<string> */
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

        return $name !== '' ? Str::limit($name, 120, '') : 'TALA Payment';
    }

    private function optionalString(mixed $value): ?string
    {
        return filled($value) ? (string) $value : null;
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $key, string $errorCode, string $operation): string
    {
        $value = data_get($payload, $key);

        if (! filled($value)) {
            throw new PaymentGatewayException(
                message: 'Payment provider returned an incomplete '.$operation.' response.',
                errorCode: $errorCode,
                retryable: false,
                indeterminate: true,
            );
        }

        return (string) $value;
    }
}
