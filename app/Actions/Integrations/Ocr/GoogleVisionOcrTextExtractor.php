<?php

namespace App\Actions\Integrations\Ocr;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Throwable;

class GoogleVisionOcrTextExtractor implements OcrTextExtractor
{
    private const ENGINE = 'google_vision_document_text_detection';

    public function __construct(
        private readonly GoogleVisionDocumentTextClient $client,
        private readonly FilesystemFactory $filesystem,
        private readonly CacheRepository $cache,
        private readonly string $confidenceThreshold,
        private readonly int $monthlyCallLimit,
    ) {}

    public function extract(string $disk, string $path, string $documentType, ?int $documentUploadId = null): OcrExtractionResult
    {
        try {
            $storage = $this->filesystem->disk($disk);

            if (! $storage->exists($path)) {
                return $this->manualReview(
                    message: 'OCR source file was not found in private storage.',
                    disk: $disk,
                    path: $path,
                    documentType: $documentType,
                    documentUploadId: $documentUploadId,
                );
            }

            $contents = $storage->get($path);
        } catch (Throwable) {
            return $this->manualReview(
                message: 'OCR source file could not be read from private storage.',
                disk: $disk,
                path: $path,
                documentType: $documentType,
                documentUploadId: $documentUploadId,
            );
        }

        if (! $this->reserveMonthlyCall()) {
            return $this->manualReview(
                message: 'Google Cloud Vision monthly OCR call limit reached; routed to manual review without an external API call.',
                disk: $disk,
                path: $path,
                documentType: $documentType,
                documentUploadId: $documentUploadId,
                metadata: ['circuit_breaker' => 'monthly_call_limit'],
            );
        }

        try {
            $documentText = $this->client->detect($contents);
        } catch (Throwable) {
            return $this->manualReview(
                message: 'Google Cloud Vision OCR failed; routed to manual review.',
                disk: $disk,
                path: $path,
                documentType: $documentType,
                documentUploadId: $documentUploadId,
            );
        }

        $text = $this->normalizeText($documentText->text);
        $confidence = $this->weightedConfidence($documentText->words);
        $status = $text !== null
            && $confidence !== null
            && (float) $confidence >= (float) $this->confidenceThreshold
                ? 'ocr_extracted'
                : 'needs_manual_review';

        return new OcrExtractionResult(
            engine: self::ENGINE,
            text: $text,
            confidence: $confidence,
            status: $status,
            errorMessage: $status === 'needs_manual_review'
                ? 'Google Cloud Vision OCR output requires manual review.'
                : null,
            metadata: array_merge($documentText->metadata, $this->baseMetadata($disk, $path, $documentType, $documentUploadId), [
                'confidence_threshold' => $this->confidenceThreshold,
                'word_count' => count($documentText->words),
            ]),
        );
    }

    private function reserveMonthlyCall(): bool
    {
        if ($this->monthlyCallLimit < 1) {
            return false;
        }

        $now = CarbonImmutable::now(config('app.timezone'));
        $key = 'tala:ocr:google_vision:'.$now->format('Y-m');

        $this->cache->add($key, 0, $now->endOfMonth()->addDay());

        $used = $this->cache->increment($key);

        return is_int($used) && $used <= $this->monthlyCallLimit;
    }

    /**
     * @param  list<array{text:string, confidence:float|null}>  $words
     */
    private function weightedConfidence(array $words): ?string
    {
        $weightedConfidence = 0.0;
        $totalCharacters = 0;

        foreach ($words as $word) {
            $text = trim($word['text']);
            $confidence = $word['confidence'];

            if ($text === '' || $confidence === null) {
                continue;
            }

            $characters = mb_strlen($text);

            if ($characters < 1) {
                continue;
            }

            $weightedConfidence += max(0.0, min(1.0, $confidence)) * $characters;
            $totalCharacters += $characters;
        }

        if ($totalCharacters === 0) {
            return null;
        }

        return number_format(($weightedConfidence / $totalCharacters) * 100, 2, '.', '');
    }

    private function normalizeText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $normalized = trim($text);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function manualReview(
        string $message,
        string $disk,
        string $path,
        string $documentType,
        ?int $documentUploadId,
        array $metadata = [],
    ): OcrExtractionResult {
        return new OcrExtractionResult(
            engine: self::ENGINE,
            text: null,
            confidence: null,
            status: 'needs_manual_review',
            errorMessage: $message,
            metadata: array_merge($this->baseMetadata($disk, $path, $documentType, $documentUploadId), $metadata),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function baseMetadata(string $disk, string $path, string $documentType, ?int $documentUploadId): array
    {
        return [
            'driver' => 'google_vision',
            'feature' => 'DOCUMENT_TEXT_DETECTION',
            'document_upload_id' => $documentUploadId,
            'document_type' => $documentType,
            'disk' => $disk,
            'path' => $path,
        ];
    }
}
