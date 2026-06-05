<?php

namespace Tests\Feature;

use App\Actions\Registrar\DocumentUploadReviewService;
use App\Models\DocumentUpload;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DocumentUploadReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_records_registrar_review_and_approved_payload(): void
    {
        $registrar = $this->registrar();
        $upload = $this->documentUpload([
            'student_confirmed_payload' => [
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
            ],
        ]);

        app(DocumentUploadReviewService::class)->approve($upload, $registrar);

        $upload->refresh();
        $properties = $this->activityProperties($upload, 'document_upload_approved');

        $this->assertSame(DocumentUpload::ReviewStatusRegistrarApproved, $upload->ocr_review_status);
        $this->assertSame($registrar->id, $upload->registrar_reviewed_by);
        $this->assertNotNull($upload->registrar_reviewed_at);
        $this->assertSame([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
        ], $upload->registrar_approved_payload);
        $this->assertSame(DocumentUpload::ReviewStatusRegistrarApproved, $properties['status_after']);
        $this->assertNull($properties['reason']);
    }

    public function test_needs_correction_requires_and_records_reason(): void
    {
        $registrar = $this->registrar();
        $upload = $this->documentUpload();

        app(DocumentUploadReviewService::class)->needsCorrection(
            $upload,
            $registrar,
            'LRN is not readable on the uploaded card.',
        );

        $upload->refresh();
        $properties = $this->activityProperties($upload, 'document_upload_needs_correction');

        $this->assertSame(DocumentUpload::ReviewStatusNeedsCorrection, $upload->ocr_review_status);
        $this->assertSame($registrar->id, $upload->registrar_reviewed_by);
        $this->assertSame('LRN is not readable on the uploaded card.', $properties['reason']);
    }

    public function test_reject_records_terminal_review_decision(): void
    {
        $registrar = $this->registrar();
        $upload = $this->documentUpload();

        app(DocumentUploadReviewService::class)->reject(
            $upload,
            $registrar,
            'Uploaded file is not an approved enrollment document.',
        );

        $upload->refresh();
        $properties = $this->activityProperties($upload, 'document_upload_rejected');

        $this->assertSame(DocumentUpload::ReviewStatusRejected, $upload->ocr_review_status);
        $this->assertSame('Uploaded file is not an approved enrollment document.', $properties['reason']);
    }

    public function test_document_review_requires_approve_documents_permission(): void
    {
        $actor = User::factory()->create();
        $upload = $this->documentUpload();

        try {
            app(DocumentUploadReviewService::class)->approve($upload, $actor);
            $this->fail('Expected document review to require approve-documents permission.');
        } catch (AuthorizationException) {
            $this->assertSame(DocumentUpload::ReviewStatusPendingRegistrarReview, $upload->refresh()->ocr_review_status);
        }
    }

    public function test_final_document_review_state_cannot_transition_again(): void
    {
        $registrar = $this->registrar();
        $upload = $this->documentUpload([
            'ocr_review_status' => DocumentUpload::ReviewStatusRegistrarApproved,
            'registrar_reviewed_by' => $registrar->id,
            'registrar_reviewed_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        app(DocumentUploadReviewService::class)->reject(
            $upload,
            $registrar,
            'Second review decision.',
        );
    }

    public function test_document_upload_review_options_match_filament_contract(): void
    {
        $this->assertSame([
            DocumentUpload::ReviewStatusUploaded => 'Uploaded',
            DocumentUpload::ReviewStatusOcrExtracted => 'OCR Extracted',
            DocumentUpload::ReviewStatusStudentConfirmed => 'Student Confirmed',
            DocumentUpload::ReviewStatusPendingRegistrarReview => 'Pending Registrar Review',
            DocumentUpload::ReviewStatusRegistrarApproved => 'Registrar Approved',
            DocumentUpload::ReviewStatusNeedsCorrection => 'Needs Correction',
            DocumentUpload::ReviewStatusRejected => 'Rejected',
            DocumentUpload::ReviewStatusNeedsManualReview => 'Needs Manual Review',
            DocumentUpload::ReviewStatusManualEntry => 'Manual Entry',
        ], DocumentUpload::reviewStatusOptions());

        $this->assertSame([
            'gray' => DocumentUpload::ReviewStatusUploaded,
            'info' => DocumentUpload::ReviewStatusOcrExtracted,
            'warning' => DocumentUpload::ReviewStatusPendingRegistrarReview,
            'success' => DocumentUpload::ReviewStatusRegistrarApproved,
            'danger' => DocumentUpload::ReviewStatusRejected,
        ], DocumentUpload::reviewStatusColors());
    }

    private function registrar(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $registrar = User::factory()->create();
        $registrar->givePermissionTo(Permission::findOrCreate('approve-documents'));

        return $registrar;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function documentUpload(array $attributes = []): DocumentUpload
    {
        $studentProfile = StudentProfile::factory()->create();

        return DocumentUpload::query()->create([
            'student_profile_id' => $studentProfile->id,
            'user_id' => $studentProfile->user_id,
            'term_id' => Term::factory()->create()->id,
            'document_type' => 'grade_11_card',
            'file_disk' => 'local',
            'file_path' => 'documents/grade-11-card.jpg',
            'file_name' => 'grade-11-card.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'upload_status' => 'uploaded',
            'ocr_review_status' => DocumentUpload::ReviewStatusPendingRegistrarReview,
            'student_confirmed_payload' => [],
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function activityProperties(DocumentUpload $upload, string $event): array
    {
        $activity = DB::table('activity_log')
            ->where('subject_type', DocumentUpload::class)
            ->where('subject_id', $upload->id)
            ->where('event', $event)
            ->first();

        $this->assertNotNull($activity);

        return json_decode((string) $activity->properties, true, 512, JSON_THROW_ON_ERROR);
    }
}
