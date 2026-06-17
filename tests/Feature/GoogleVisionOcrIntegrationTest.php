<?php

namespace Tests\Feature;

use App\Actions\Integrations\Ocr\GoogleVisionDocumentTextClient;
use App\Actions\Integrations\Ocr\GoogleVisionDocumentTextResult;
use App\Actions\Integrations\Ocr\OcrTextExtractor;
use App\Actions\Integrations\Ocr\ProcessDocumentOcr;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Tests the integration with the Google Vision OCR service, verifying
 * document text extraction, low confidence handling, API limit blocks,
 * and fallback routing to manual review.
 *
 * Steps / Test Cases:
 * 1. test_google_vision_driver_writes_extracted_result_from_document_text_client
 * 2. test_google_vision_low_confidence_routes_to_manual_review
 * 3. test_google_vision_missing_confidence_routes_to_manual_review
 * 4. test_google_vision_monthly_limit_blocks_before_client_call
 * 5. test_google_vision_api_failure_routes_to_manual_review
 */
class GoogleVisionOcrIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareSchema();
        Storage::fake('local');
    }

    public function test_google_vision_driver_writes_extracted_result_from_document_text_client(): void
    {
        $client = new FakeGoogleVisionDocumentTextClient(
            result: new GoogleVisionDocumentTextResult(
                text: 'TALA OCR TEST',
                words: [
                    ['text' => 'TALA', 'confidence' => 0.90],
                    ['text' => 'OCR', 'confidence' => 0.80],
                    ['text' => 'TEST', 'confidence' => 1.00],
                ],
            ),
        );

        $this->configureGoogleVision($client);

        $documentUploadId = $this->documentUploadId();

        $summary = app(ProcessDocumentOcr::class)->handle($documentUploadId, 'document-ocr/2026-05-25.1');

        $this->assertSame(1, $client->calls);
        $this->assertSame('fake image bytes', $client->receivedContents);
        $this->assertSame('ocr_extracted', $summary['status']);
        $this->assertSame('google_vision_document_text_detection', $summary['ocr_engine']);
        $this->assertSame('90.91', $summary['ocr_confidence']);

        $this->assertDatabaseHas('document_uploads', [
            'id' => $documentUploadId,
            'ocr_review_status' => 'ocr_extracted',
            'ocr_confidence' => '90.91',
            'ocr_text' => 'TALA OCR TEST',
            'parser_version' => 'document-ocr/2026-05-25.1',
        ]);

        $this->assertDatabaseHas('document_ocr_results', [
            'document_upload_id' => $documentUploadId,
            'ocr_engine' => 'google_vision_document_text_detection',
            'ocr_confidence' => '90.91',
            'status' => 'ocr_extracted',
        ]);
    }

    public function test_google_vision_low_confidence_routes_to_manual_review(): void
    {
        $client = new FakeGoogleVisionDocumentTextClient(
            result: new GoogleVisionDocumentTextResult(
                text: 'BLURRED REPORT CARD',
                words: [
                    ['text' => 'BLURRED', 'confidence' => 0.70],
                    ['text' => 'REPORT', 'confidence' => 0.75],
                    ['text' => 'CARD', 'confidence' => 0.80],
                ],
            ),
        );

        $this->configureGoogleVision($client);

        $documentUploadId = $this->documentUploadId();

        app(ProcessDocumentOcr::class)->handle($documentUploadId, 'document-ocr/2026-05-25.1');

        $this->assertSame(1, $client->calls);
        $this->assertDatabaseHas('document_uploads', [
            'id' => $documentUploadId,
            'ocr_review_status' => 'needs_manual_review',
            'ocr_confidence' => '74.12',
        ]);

        $this->assertDatabaseHas('document_ocr_results', [
            'document_upload_id' => $documentUploadId,
            'status' => 'needs_manual_review',
            'processing_error' => 'Google Cloud Vision OCR output requires manual review.',
        ]);
    }

    public function test_google_vision_missing_confidence_routes_to_manual_review(): void
    {
        $client = new FakeGoogleVisionDocumentTextClient(
            result: new GoogleVisionDocumentTextResult(
                text: 'TEXT WITHOUT WORD CONFIDENCE',
                words: [],
            ),
        );

        $this->configureGoogleVision($client);

        $documentUploadId = $this->documentUploadId();

        app(ProcessDocumentOcr::class)->handle($documentUploadId, 'document-ocr/2026-05-25.1');

        $this->assertSame(1, $client->calls);
        $this->assertDatabaseHas('document_uploads', [
            'id' => $documentUploadId,
            'ocr_review_status' => 'needs_manual_review',
            'ocr_confidence' => null,
        ]);
    }

    public function test_google_vision_monthly_limit_blocks_before_client_call(): void
    {
        $client = new FakeGoogleVisionDocumentTextClient(
            result: new GoogleVisionDocumentTextResult(text: 'SHOULD NOT RUN'),
        );

        $this->configureGoogleVision($client, monthlyLimit: 0);

        $documentUploadId = $this->documentUploadId();

        app(ProcessDocumentOcr::class)->handle($documentUploadId, 'document-ocr/2026-05-25.1');

        $this->assertSame(0, $client->calls);
        $this->assertDatabaseHas('document_ocr_results', [
            'document_upload_id' => $documentUploadId,
            'status' => 'needs_manual_review',
            'processing_error' => 'Google Cloud Vision monthly OCR call limit reached; routed to manual review without an external API call.',
        ]);
    }

    public function test_google_vision_api_failure_routes_to_manual_review(): void
    {
        $client = new FakeGoogleVisionDocumentTextClient(
            exception: new RuntimeException('simulated provider outage'),
        );

        $this->configureGoogleVision($client);

        $documentUploadId = $this->documentUploadId();

        app(ProcessDocumentOcr::class)->handle($documentUploadId, 'document-ocr/2026-05-25.1');

        $this->assertSame(1, $client->calls);
        $this->assertDatabaseHas('document_ocr_results', [
            'document_upload_id' => $documentUploadId,
            'status' => 'needs_manual_review',
            'processing_error' => 'Google Cloud Vision OCR failed; routed to manual review.',
        ]);
    }

    public function test_google_vision_smoke_command_copies_private_file_and_verifies_extracted_evidence(): void
    {
        $client = new FakeGoogleVisionDocumentTextClient(
            result: new GoogleVisionDocumentTextResult(
                text: 'LIVE OCR SMOKE TEST',
                words: [
                    ['text' => 'LIVE', 'confidence' => 0.92],
                    ['text' => 'OCR', 'confidence' => 0.94],
                    ['text' => 'SMOKE', 'confidence' => 0.96],
                    ['text' => 'TEST', 'confidence' => 0.98],
                ],
            ),
        );

        $this->configureGoogleVision($client);
        $sourcePath = $this->temporarySmokeFile('fake google vision image bytes');

        try {
            $exitCode = Artisan::call('integrations:google-vision-ocr-smoke', [
                '--file' => $sourcePath,
                '--parser-version' => 'google-vision-smoke/test',
            ]);
            $output = Artisan::output();
        } finally {
            @unlink($sourcePath);
        }

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('driver_google_vision=PASS', $output);
        $this->assertStringContainsString('ocr_result_persisted=PASS', $output);
        $this->assertStringContainsString('extracted_text_present=PASS', $output);
        $this->assertStringContainsString('Google Cloud Vision OCR smoke evidence verified.', $output);

        $upload = DB::table('document_uploads')->first();

        $this->assertNotNull($upload);
        $this->assertSame('ocr_extracted', $upload->ocr_review_status);
        $this->assertStringStartsWith('ocr-smoke/', $upload->file_path);
        Storage::disk('local')->assertExists($upload->file_path);

        $this->assertDatabaseHas('document_ocr_results', [
            'document_upload_id' => $upload->id,
            'ocr_engine' => 'google_vision_document_text_detection',
            'status' => 'ocr_extracted',
            'ocr_text' => 'LIVE OCR SMOKE TEST',
            'parser_version' => 'google-vision-smoke/test',
        ]);
    }

    public function test_google_vision_smoke_command_accepts_expected_manual_review_evidence(): void
    {
        $client = new FakeGoogleVisionDocumentTextClient(
            result: new GoogleVisionDocumentTextResult(
                text: 'BLURRED LIVE SAMPLE',
                words: [
                    ['text' => 'BLURRED', 'confidence' => 0.40],
                    ['text' => 'LIVE', 'confidence' => 0.50],
                    ['text' => 'SAMPLE', 'confidence' => 0.60],
                ],
            ),
        );

        $this->configureGoogleVision($client);
        $documentUploadId = $this->documentUploadId();

        $exitCode = Artisan::call('integrations:google-vision-ocr-smoke', [
            '--document-upload-id' => $documentUploadId,
            '--expect' => 'needs_manual_review',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('expected_status=PASS', $output);
        $this->assertStringContainsString('manual_review_error_recorded=PASS', $output);
        $this->assertStringContainsString('status=needs_manual_review', $output);
    }

    public function test_google_vision_smoke_command_fails_when_driver_is_not_google_vision(): void
    {
        config(['tala_integrations.ocr.driver' => 'mock']);

        $exitCode = Artisan::call('integrations:google-vision-ocr-smoke', [
            '--document-upload-id' => 1,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('TALA_OCR_DRIVER must be google_vision', $output);
    }

    private function configureGoogleVision(FakeGoogleVisionDocumentTextClient $client, int $monthlyLimit = 2000): void
    {
        config([
            'tala_integrations.ocr.driver' => 'google_vision',
            'tala_integrations.ocr.confidence_threshold' => '80.00',
            'tala_integrations.ocr.google_vision.monthly_call_limit' => $monthlyLimit,
        ]);

        $this->app->instance(GoogleVisionDocumentTextClient::class, $client);
        $this->app->forgetInstance(OcrTextExtractor::class);
    }

    private function documentUploadId(): int
    {
        $path = 'documents/report-card.jpg';

        Storage::disk('local')->put($path, 'fake image bytes');

        return (int) DB::table('document_uploads')->insertGetId([
            'student_profile_id' => null,
            'user_id' => null,
            'term_id' => null,
            'document_type' => 'report_card',
            'file_disk' => 'local',
            'file_path' => $path,
            'file_name' => 'report-card.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'upload_status' => 'uploaded',
            'ocr_review_status' => 'uploaded',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function temporarySmokeFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tala-ocr-smoke-');

        if ($path === false) {
            $this->fail('Unable to create temporary OCR smoke file.');
        }

        file_put_contents($path, $contents);

        return $path;
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

final class FakeGoogleVisionDocumentTextClient implements GoogleVisionDocumentTextClient
{
    public int $calls = 0;

    public ?string $receivedContents = null;

    public function __construct(
        private readonly ?GoogleVisionDocumentTextResult $result = null,
        private readonly ?Throwable $exception = null,
    ) {}

    public function detect(string $contents): GoogleVisionDocumentTextResult
    {
        $this->calls++;
        $this->receivedContents = $contents;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->result ?? new GoogleVisionDocumentTextResult(text: null);
    }
}
