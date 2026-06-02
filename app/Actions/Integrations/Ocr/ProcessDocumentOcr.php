<?php

namespace App\Actions\Integrations\Ocr;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProcessDocumentOcr
{
    public function __construct(private readonly OcrTextExtractor $extractor) {}

    /**
     * @return array{document_upload_id:int, document_ocr_result_id:int, status:string, ocr_engine:string, ocr_confidence:string|null}
     */
    public function handle(int $documentUploadId, string $parserVersion): array
    {
        /** @var object{id:int, file_disk:string, file_path:string, document_type:string}|null $upload */
        $upload = DB::table('document_uploads')->where('id', $documentUploadId)->first();

        if ($upload === null) {
            throw new RuntimeException('Document upload was not found.');
        }

        $result = $this->extractor->extract(
            disk: $upload->file_disk,
            path: $upload->file_path,
            documentType: $upload->document_type,
            documentUploadId: $documentUploadId,
        );

        $processedAt = CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($documentUploadId, $parserVersion, $result, $processedAt): array {
            $ocrResultId = DB::table('document_ocr_results')->insertGetId([
                'document_upload_id' => $documentUploadId,
                'ocr_engine' => $result->engine,
                'parser_version' => $parserVersion,
                'ocr_text' => $result->text,
                'ocr_confidence' => $result->confidence,
                'status' => $result->status,
                'processing_error' => $result->errorMessage,
                'processed_at' => $processedAt->toDateTimeString(),
                'created_at' => $processedAt->toDateTimeString(),
                'updated_at' => $processedAt->toDateTimeString(),
            ]);

            DB::table('document_uploads')
                ->where('id', $documentUploadId)
                ->update([
                    'ocr_review_status' => $result->status,
                    'ocr_confidence' => $result->confidence,
                    'ocr_text' => $result->text,
                    'ocr_processed_at' => $processedAt->toDateTimeString(),
                    'parser_version' => $parserVersion,
                    'updated_at' => $processedAt->toDateTimeString(),
                ]);

            return [
                'document_upload_id' => $documentUploadId,
                'document_ocr_result_id' => $ocrResultId,
                'status' => $result->status,
                'ocr_engine' => $result->engine,
                'ocr_confidence' => $result->confidence,
            ];
        });
    }
}
