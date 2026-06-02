<?php

namespace App\Actions\Integrations\Ocr;

final readonly class OcrExtractionResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $engine,
        public ?string $text,
        public ?string $confidence,
        public string $status,
        public ?string $errorMessage = null,
        public array $metadata = [],
    ) {}

    public function requiresManualReview(): bool
    {
        return $this->status === 'needs_manual_review';
    }
}
