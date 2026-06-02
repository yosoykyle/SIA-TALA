<?php

namespace App\Actions\Integrations\Ocr;

interface GoogleVisionDocumentTextClient
{
    public function detect(string $contents): GoogleVisionDocumentTextResult;
}
