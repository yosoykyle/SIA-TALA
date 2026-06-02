<?php

namespace App\Actions\Integrations\Ocr;

final readonly class GoogleVisionDocumentTextResult
{
    /**
     * @param  list<array{text:string, confidence:float|null}>  $words
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public ?string $text,
        public array $words = [],
        public array $metadata = [],
    ) {}
}
