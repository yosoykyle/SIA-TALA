<?php

namespace App\Actions\Integrations\Ocr;

use RuntimeException;

class UnavailableGoogleVisionOcrTextExtractor implements OcrTextExtractor
{
    public function extract(string $disk, string $path, string $documentType, ?int $documentUploadId = null): OcrExtractionResult
    {
        throw new RuntimeException('Google Cloud Vision OCR is intentionally disabled in this phase. Use TALA_OCR_DRIVER=mock until the live OCR job, credentials, circuit breaker, and retry tests are implemented.');
    }
}
