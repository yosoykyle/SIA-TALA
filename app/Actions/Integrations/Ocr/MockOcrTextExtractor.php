<?php

namespace App\Actions\Integrations\Ocr;

class MockOcrTextExtractor implements OcrTextExtractor
{
    public function __construct(
        private readonly string $engine,
        private readonly string $text,
        private readonly ?string $confidence,
        private readonly string $confidenceThreshold,
    ) {}

    public function extract(string $disk, string $path, string $documentType, ?int $documentUploadId = null): OcrExtractionResult
    {
        $normalizedConfidence = $this->normalizeConfidence($this->confidence);
        $status = $normalizedConfidence !== null && (float) $normalizedConfidence >= (float) $this->confidenceThreshold
            ? 'ocr_extracted'
            : 'needs_manual_review';

        return new OcrExtractionResult(
            engine: $this->engine,
            text: $this->text,
            confidence: $normalizedConfidence,
            status: $status,
            metadata: [
                'driver' => 'mock',
                'document_upload_id' => $documentUploadId,
                'document_type' => $documentType,
                'disk' => $disk,
                'path' => $path,
            ],
        );
    }

    private function normalizeConfidence(?string $confidence): ?string
    {
        if ($confidence === null || trim($confidence) === '') {
            return null;
        }

        $value = max(0, min(100, (float) $confidence));

        return number_format($value, 2, '.', '');
    }
}
