<?php

namespace App\Actions\Integrations\Payments;

use Illuminate\Http\Request;

class PayMongoWebhookSignatureVerifier
{
    public function isValid(Request $request): bool
    {
        $secret = $this->webhookSecret();

        if ($secret === null || $secret === '') {
            return config('tala_integrations.payments.driver', 'mock') === 'mock';
        }

        $headerPayload = $this->headerPayload($request);
        $timestamp = $headerPayload['t'] ?? null;
        $providedSignature = $this->providedSignature($headerPayload);

        if ($timestamp === null || $providedSignature === null) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        return hash_equals($expectedSignature, $providedSignature);
    }

    /**
     * @return array<string, string>
     */
    private function headerPayload(Request $request): array
    {
        $headerName = (string) config('paymongo.signature_header_name', 'paymongo-signature');
        $header = $request->header($headerName);

        if ($header === null || trim($header) === '') {
            return [];
        }

        $payload = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, null);

            if ($key !== null && $value !== null && trim($key) !== '') {
                $payload[trim($key)] = trim($value);
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, string>  $headerPayload
     */
    private function providedSignature(array $headerPayload): ?string
    {
        $livemodeKey = config('paymongo.livemode') ? 'li' : 'te';

        return $headerPayload[$livemodeKey]
            ?? $headerPayload['v1']
            ?? $headerPayload['te']
            ?? $headerPayload['li']
            ?? null;
    }

    private function webhookSecret(): ?string
    {
        $configuredSecret = config('paymongo.webhook_signature')
            ?? config('tala_integrations.payments.paymongo.webhook_signature');

        if ($configuredSecret === null) {
            return null;
        }

        $secret = trim((string) $configuredSecret);

        return $secret !== '' ? $secret : null;
    }
}
