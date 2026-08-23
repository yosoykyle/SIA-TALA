<?php

namespace App\Actions\Integrations\Payments;

final readonly class PaymentCheckoutRequest
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $studentProfileId,
        public string $amount,
        public string $description,
        public ?int $assessmentId = null,
        public ?int $termAccountId = null,
        public ?int $assessmentVersion = null,
        public ?string $snapshotChecksum = null,
        public string $channel = 'paymongo',
        public ?int $termId = null,
        public ?int $enrollmentId = null,
        public ?int $ledgerEntryId = null,
        public ?string $successUrl = null,
        public ?string $cancelUrl = null,
        public array $metadata = [],
    ) {}
}
