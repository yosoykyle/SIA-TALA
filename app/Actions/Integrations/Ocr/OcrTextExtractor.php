<?php

namespace App\Actions\Integrations\Ocr;

interface OcrTextExtractor
{
    public function extract(string $disk, string $path, string $documentType, ?int $documentUploadId = null): OcrExtractionResult;
}
