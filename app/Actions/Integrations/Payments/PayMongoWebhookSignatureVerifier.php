<?php

namespace App\Actions\Integrations\Payments;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class PayMongoWebhookSignatureVerifier
{
    public function isValid(Request $request): bool
    {
        return $this->verify($request)->isValid();
    }

    public function verify(Request $request): PayMongoWebhookSignatureVerification
    {
        $expectedMode = $this->expectedMode();
        $secret = $this->webhookSecret();

        if ($secret === null || $secret === '') {
            return new PayMongoWebhookSignatureVerification(
                PayMongoWebhookSignatureVerification::MissingSecret,
                $expectedMode,
            );
        }

        $header = $this->signatureHeader($request);

        if ($header === null || trim($header) === '') {
            return new PayMongoWebhookSignatureVerification(
                PayMongoWebhookSignatureVerification::MissingHeader,
                $expectedMode,
            );
        }

        $headerPayload = $this->headerPayload($header);
        $timestamp = $headerPayload['t'] ?? null;

        if ($timestamp === null || ! ctype_digit($timestamp) || (int) $timestamp <= 0) {
            return new PayMongoWebhookSignatureVerification(
                PayMongoWebhookSignatureVerification::MalformedTimestamp,
                $expectedMode,
            );
        }

        $providedSignature = $this->providedSignature($headerPayload);
        $ageSeconds = CarbonImmutable::now(config('app.timezone'))->getTimestamp() - (int) $timestamp;

        if ($providedSignature === null || $providedSignature === '') {
            return new PayMongoWebhookSignatureVerification(
                PayMongoWebhookSignatureVerification::MissingModeSignature,
                $expectedMode,
                $ageSeconds,
            );
        }

        if (preg_match('/^[a-f0-9]{64}$/i', $providedSignature) !== 1) {
            return new PayMongoWebhookSignatureVerification(
                PayMongoWebhookSignatureVerification::MalformedSignature,
                $expectedMode,
                $ageSeconds,
            );
        }

        $expectedSignature = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if (! hash_equals($expectedSignature, $providedSignature)) {
            return new PayMongoWebhookSignatureVerification(
                PayMongoWebhookSignatureVerification::SignatureMismatch,
                $expectedMode,
                $ageSeconds,
            );
        }

        $maxAgeSeconds = (int) config('tala_integrations.payments.paymongo.signature_max_age_seconds', 300);

        if ($maxAgeSeconds <= 0) {
            return new PayMongoWebhookSignatureVerification(
                PayMongoWebhookSignatureVerification::InvalidFreshnessPolicy,
                $expectedMode,
                $ageSeconds,
            );
        }

        if ($ageSeconds < -$maxAgeSeconds) {
            return new PayMongoWebhookSignatureVerification(
                PayMongoWebhookSignatureVerification::FutureTimestamp,
                $expectedMode,
                $ageSeconds,
            );
        }

        if ($ageSeconds > $maxAgeSeconds) {
            return new PayMongoWebhookSignatureVerification(
                PayMongoWebhookSignatureVerification::StaleTimestamp,
                $expectedMode,
                $ageSeconds,
            );
        }

        return new PayMongoWebhookSignatureVerification(
            PayMongoWebhookSignatureVerification::Valid,
            $expectedMode,
            $ageSeconds,
        );
    }

    /**
     * @return array<string, string>
     */
    private function headerPayload(string $header): array
    {
        $payload = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, null);

            if ($key !== null && $value !== null && trim($key) !== '') {
                $payload[trim($key)] = trim($value);
            }
        }

        return $payload;
    }

    private function signatureHeader(Request $request): ?string
    {
        $headerName = (string) config('tala_integrations.payments.paymongo.signature_header_name', 'paymongo-signature');

        return $request->header($headerName);
    }

    /**
     * @param  array<string, string>  $headerPayload
     */
    private function providedSignature(array $headerPayload): ?string
    {
        return $headerPayload[$this->expectedModeKey()] ?? null;
    }

    private function expectedMode(): string
    {
        return config('tala_integrations.payments.paymongo.livemode') ? 'live' : 'test';
    }

    private function expectedModeKey(): string
    {
        return config('tala_integrations.payments.paymongo.livemode') ? 'li' : 'te';
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
