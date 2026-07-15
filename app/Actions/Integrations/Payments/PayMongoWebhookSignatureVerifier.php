<?php

namespace App\Actions\Integrations\Payments;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class PayMongoWebhookSignatureVerifier
{
    public function isValid(Request $request): bool
    {
        $secret = $this->webhookSecret();

        if ($secret === null || $secret === '') {
            return false;
        }

        $headerPayload = $this->headerPayload($request);
        $timestamp = $headerPayload['t'] ?? null;
        $providedSignature = $this->providedSignature($headerPayload);

        if (
            $timestamp === null || ! ctype_digit($timestamp) || (int) $timestamp <= 0
            || $providedSignature === null || preg_match('/^[a-f0-9]{64}$/i', $providedSignature) !== 1
        ) {
            return false;
        }

        $maxAgeSeconds = (int) config('tala_integrations.payments.paymongo.signature_max_age_seconds', 300);
        $ageSeconds = CarbonImmutable::now(config('app.timezone'))->getTimestamp() - (int) $timestamp;

        if ($maxAgeSeconds <= 0 || $ageSeconds < 0 || $ageSeconds > $maxAgeSeconds) {
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
        $headerName = (string) config('tala_integrations.payments.paymongo.signature_header_name', 'paymongo-signature');
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
        $livemodeKey = config('tala_integrations.payments.paymongo.livemode') ? 'li' : 'te';

        return $headerPayload[$livemodeKey] ?? null;
    }

    private function webhookSecret(): ?string
    {
        $configuredSecret = config('tala_integrations.payments.paymongo.webhook_signature');

        if ($configuredSecret === null) {
            return null;
        }

        $secret = trim((string) $configuredSecret);

        return $secret !== '' ? $secret : null;
    }
}
