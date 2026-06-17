<?php

namespace App\Console\Commands;

use App\Actions\Integrations\Ocr\ProcessDocumentOcr;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

class VerifyGoogleVisionOcrSmoke extends Command
{
    protected $signature = 'integrations:google-vision-ocr-smoke
        {--file= : Local source file to copy into private storage for the smoke run}
        {--document-upload-id= : Existing document_uploads.id to process and verify}
        {--document-type=transcript_of_records : Document type stored on created smoke upload}
        {--parser-version=google-vision-smoke/v1 : Parser version recorded on OCR evidence}
        {--expect=ocr_extracted : Expected result status: ocr_extracted, needs_manual_review, or any}';

    protected $description = 'Verify Google Cloud Vision OCR writes document evidence and routing state.';

    public function handle(ProcessDocumentOcr $processDocumentOcr): int
    {
        if (config('tala_integrations.ocr.driver') !== 'google_vision') {
            $this->error('Refusing OCR smoke: TALA_OCR_DRIVER must be google_vision.');

            return self::FAILURE;
        }

        $expectedStatus = trim((string) $this->option('expect'));

        if (! in_array($expectedStatus, ['ocr_extracted', 'needs_manual_review', 'any'], true)) {
            $this->error('Invalid --expect value. Use ocr_extracted, needs_manual_review, or any.');

            return self::FAILURE;
        }

        try {
            $documentUploadId = $this->documentUploadId();

            if ($documentUploadId === null) {
                $this->error('Provide either --file or --document-upload-id.');

                return self::FAILURE;
            }

            $summary = $processDocumentOcr->handle($documentUploadId, (string) $this->option('parser-version'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $upload = DB::table('document_uploads')->where('id', $documentUploadId)->first();
        $ocrResult = DB::table('document_ocr_results')->where('id', $summary['document_ocr_result_id'])->first();
        $expectedMatches = $expectedStatus === 'any' || $summary['status'] === $expectedStatus;

        $checks = [
            'driver_google_vision' => config('tala_integrations.ocr.driver') === 'google_vision',
            'document_upload_found' => $upload instanceof stdClass,
            'ocr_result_persisted' => $ocrResult instanceof stdClass,
            'google_vision_engine' => $summary['ocr_engine'] === 'google_vision_document_text_detection',
            'upload_state_updated' => $upload instanceof stdClass
                && $upload->ocr_review_status === $summary['status']
                && (int) $summary['document_ocr_result_id'] === (int) $ocrResult?->id,
            'expected_status' => $expectedMatches,
            'private_source_available' => $upload instanceof stdClass
                && Storage::disk((string) $upload->file_disk)->exists((string) $upload->file_path),
        ];

        if ($summary['status'] === 'ocr_extracted') {
            $checks['extracted_text_present'] = $ocrResult instanceof stdClass
                && filled($ocrResult->ocr_text)
                && blank($ocrResult->processing_error);
            $checks['confidence_recorded'] = $ocrResult instanceof stdClass && filled($ocrResult->ocr_confidence);
        }

        if ($summary['status'] === 'needs_manual_review') {
            $checks['manual_review_error_recorded'] = $ocrResult instanceof stdClass && filled($ocrResult->processing_error);
        }

        foreach ($checks as $check => $passed) {
            $this->line(sprintf('%s=%s', $check, $passed ? 'PASS' : 'FAIL'));
        }

        $this->line('document_upload_id='.$documentUploadId);
        $this->line('document_ocr_result_id='.$summary['document_ocr_result_id']);
        $this->line('status='.$summary['status']);
        $this->line('ocr_engine='.$summary['ocr_engine']);
        $this->line('ocr_confidence='.($summary['ocr_confidence'] ?? 'null'));

        if ($ocrResult instanceof stdClass && filled($ocrResult->processing_error)) {
            $this->line('processing_error='.$ocrResult->processing_error);
        }

        if (in_array(false, $checks, true)) {
            $this->error('Google Cloud Vision OCR smoke evidence is incomplete.');

            return self::FAILURE;
        }

        $this->info('Google Cloud Vision OCR smoke evidence verified.');

        return self::SUCCESS;
    }

    private function documentUploadId(): ?int
    {
        $documentUploadId = $this->option('document-upload-id');

        if (filled($documentUploadId)) {
            return (int) $documentUploadId;
        }

        $file = $this->option('file');

        if (! filled($file)) {
            return null;
        }

        return $this->createSmokeUpload((string) $file);
    }

    private function createSmokeUpload(string $sourcePath): int
    {
        $resolvedPath = realpath($sourcePath);

        if ($resolvedPath === false || ! is_file($resolvedPath) || ! is_readable($resolvedPath)) {
            throw new RuntimeException('OCR smoke source file is not readable.');
        }

        $now = CarbonImmutable::now(config('app.timezone'));
        $extension = pathinfo($resolvedPath, PATHINFO_EXTENSION);
        $fileName = basename($resolvedPath);
        $storageName = (string) Str::uuid().($extension !== '' ? ".{$extension}" : '');
        $storagePath = 'ocr-smoke/'.$now->format('Y/m/d').'/'.$storageName;
        $contents = file_get_contents($resolvedPath);

        if ($contents === false) {
            throw new RuntimeException('OCR smoke source file could not be read.');
        }

        Storage::disk('local')->put($storagePath, $contents);

        $mimeType = function_exists('mime_content_type') ? mime_content_type($resolvedPath) : null;

        return (int) DB::table('document_uploads')->insertGetId([
            'student_profile_id' => null,
            'user_id' => null,
            'term_id' => null,
            'document_type' => (string) $this->option('document-type'),
            'file_disk' => 'local',
            'file_path' => $storagePath,
            'file_name' => $fileName,
            'mime_type' => is_string($mimeType) ? $mimeType : null,
            'file_size' => filesize($resolvedPath) ?: null,
            'checksum' => hash_file('sha256', $resolvedPath) ?: null,
            'upload_status' => 'uploaded',
            'ocr_review_status' => 'uploaded',
            'created_at' => $now->toDateTimeString(),
            'updated_at' => $now->toDateTimeString(),
        ]);
    }
}
