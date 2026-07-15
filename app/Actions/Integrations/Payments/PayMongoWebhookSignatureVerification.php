<?php

namespace App\Actions\Integrations\Payments;

/**
 * @phpstan-type VerificationReason 'valid'|'missing_secret'|'missing_header'|'malformed_timestamp'|'missing_mode_signature'|'malformed_signature'|'signature_mismatch'|'invalid_freshness_policy'|'future_timestamp'|'stale_timestamp'
 */
final readonly class PayMongoWebhookSignatureVerification
{
    public const Valid = 'valid';

    public const MissingSecret = 'missing_secret';

    public const MissingHeader = 'missing_header';

    public const MalformedTimestamp = 'malformed_timestamp';

    public const MissingModeSignature = 'missing_mode_signature';

    public const MalformedSignature = 'malformed_signature';

    public const SignatureMismatch = 'signature_mismatch';

    public const InvalidFreshnessPolicy = 'invalid_freshness_policy';

    public const FutureTimestamp = 'future_timestamp';

    public const StaleTimestamp = 'stale_timestamp';

    /** @param VerificationReason $reason */
    public function __construct(
        public string $reason,
        public string $expectedMode,
        public ?int $timestampAgeSeconds = null,
    ) {}

    public function isValid(): bool
    {
        return $this->reason === self::Valid;
    }

    /**
     * @return array{reason: VerificationReason, expected_mode: string, timestamp_age_seconds: int|null}
     */
    public function safeLogContext(): array
    {
        return [
            'reason' => $this->reason,
            'expected_mode' => $this->expectedMode,
            'timestamp_age_seconds' => $this->timestampAgeSeconds,
        ];
    }
}
