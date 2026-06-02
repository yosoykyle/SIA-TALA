<?php

namespace Tests\Feature;

use App\Actions\Integrations\Ocr\GoogleVisionDocumentTextClient;
use App\Actions\Integrations\Ocr\GoogleVisionDocumentTextResult;
use App\Actions\Integrations\Ocr\GoogleVisionOcrTextExtractor;
use App\Actions\Integrations\Ocr\OcrTextExtractor;
use App\Actions\Integrations\Ocr\ProcessDocumentOcr;
use App\Jobs\ProcessDocumentOcrJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests the mock implementation of the OCR integration, verifying
 * simulated document reading behavior for local testing environments.
 *
 * Steps / Test Cases:
 * 1. test_mock_ocr_writes_extracted_result_without_external_requests
 * 2. test_low_confidence_mock_ocr_routes_to_manual_review_via_job
 * 3. test_google_vision_driver_resolves_when_configured
 */
class MockOcrIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareSchema();
    }

    public function test_mock_ocr_writes_extracted_result_without_external_requests(): void
    {
        Http::preventStrayRequests();
        $this->configureMockOcr(confidence: '91.25', text: 'ALZONA, MELROSE A. BUSINESS MANAGEMENT');

        $documentUploadId = $this->documentUploadId();

        $summary = app(ProcessDocumentOcr::class)->handle($documentUploadId, 'mock-v1');

        $this->assertSame($documentUploadId, $summary['document_upload_id']);
        $this->assertSame('ocr_extracted', $summary['status']);
        $this->assertSame('mock_vision', $summary['ocr_engine']);
        $this->assertSame('91.25', $summary['ocr_confidence']);

        $this->assertDatabaseHas('document_uploads', [
            'id' => $documentUploadId,
            'ocr_review_status' => 'ocr_extracted',
            'ocr_confidence' => '91.25',
            'ocr_text' => 'ALZONA, MELROSE A. BUSINESS MANAGEMENT',
            'parser_version' => 'mock-v1',
        ]);

        $this->assertDatabaseHas('document_ocr_results', [
            'document_upload_id' => $documentUploadId,
            'ocr_engine' => 'mock_vision',
            'ocr_confidence' => '91.25',
            'status' => 'ocr_extracted',
        ]);
    }

    public function test_low_confidence_mock_ocr_routes_to_manual_review_via_job(): void
    {
        Http::preventStrayRequests();
        $this->configureMockOcr(confidence: '79.99', text: 'Blurred upload text');

        $documentUploadId = $this->documentUploadId();

        (new ProcessDocumentOcrJob($documentUploadId, 'mock-v1'))->handle(app(ProcessDocumentOcr::class));

        $this->assertDatabaseHas('document_uploads', [
            'id' => $documentUploadId,
            'ocr_review_status' => 'needs_manual_review',
            'ocr_confidence' => '79.99',
        ]);

        $this->assertDatabaseHas('document_ocr_results', [
            'document_upload_id' => $documentUploadId,
            'status' => 'needs_manual_review',
        ]);
    }

    public function test_google_vision_driver_resolves_when_configured(): void
    {
        config(['tala_integrations.ocr.driver' => 'google_vision']);
        $this->app->instance(GoogleVisionDocumentTextClient::class, new class implements GoogleVisionDocumentTextClient
        {
            public function detect(string $contents): GoogleVisionDocumentTextResult
            {
                return new GoogleVisionDocumentTextResult(text: 'Configured Google Vision pipeline.');
            }
        });

        $this->app->forgetInstance(OcrTextExtractor::class);

        $this->assertInstanceOf(GoogleVisionOcrTextExtractor::class, app(OcrTextExtractor::class));
    }

    private function configureMockOcr(string $confidence, string $text): void
    {
        config([
            'tala_integrations.ocr.driver' => 'mock',
            'tala_integrations.ocr.confidence_threshold' => '80.00',
            'tala_integrations.ocr.mock.engine' => 'mock_vision',
            'tala_integrations.ocr.mock.confidence' => $confidence,
            'tala_integrations.ocr.mock.text' => $text,
        ]);

        $this->app->forgetInstance(OcrTextExtractor::class);
    }

    private function documentUploadId(): int
    {
        return (int) DB::table('document_uploads')->insertGetId([
            'student_profile_id' => null,
            'user_id' => null,
            'term_id' => null,
            'document_type' => 'report_card',
            'file_disk' => 'local',
            'file_path' => 'private/documents/report-card.jpg',
            'file_name' => 'report-card.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'upload_status' => 'uploaded',
            'ocr_review_status' => 'uploaded',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function prepareSchema(): void
    {
        Schema::dropIfExists('document_ocr_results');
        Schema::dropIfExists('document_uploads');

        Schema::create('document_uploads', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_profile_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('term_id')->nullable();
            $table->string('document_type')->index();
            $table->string('file_disk')->default('local');
            $table->string('file_path', 500);
            $table->string('file_name');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('checksum', 128)->nullable()->index();
            $table->string('upload_status')->default('uploaded')->index();
            $table->string('ocr_review_status')->default('uploaded')->index();
            $table->decimal('ocr_confidence', 5, 2)->nullable();
            $table->text('ocr_text')->nullable();
            $table->timestamp('ocr_processed_at')->nullable();
            $table->string('parser_version')->nullable();
            $table->unsignedBigInteger('registrar_reviewed_by')->nullable();
            $table->timestamp('registrar_reviewed_at')->nullable();
            $table->json('student_confirmed_payload')->nullable();
            $table->timestamp('student_confirmed_at')->nullable();
            $table->json('registrar_approved_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('document_ocr_results', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_upload_id');
            $table->string('ocr_engine')->default('google_vision')->index();
            $table->string('parser_version')->nullable();
            $table->longText('ocr_text')->nullable();
            $table->decimal('ocr_confidence', 5, 2)->nullable();
            $table->string('status')->default('ocr_extracted')->index();
            $table->text('processing_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }
}
