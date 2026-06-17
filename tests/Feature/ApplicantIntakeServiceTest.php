<?php

namespace Tests\Feature;

use App\Actions\Applicants\ApplicantIntakeService;
use App\Jobs\ProcessDocumentOcrJob;
use App\Models\ApplicantIntake;
use App\Models\DocumentUpload;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ApplicantIntakeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_pending_applicant_account_without_student_profile(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $program = Program::factory()->create(['department' => 'college']);
        $term = Term::factory()->create(['is_active' => true]);

        $intake = app(ApplicantIntakeService::class)->create($this->validPayload([
            'program_id' => $program->id,
            'term_id' => $term->id,
        ]));

        $this->assertSame(ApplicantIntake::StatusPending, $intake->status);
        $this->assertSame(User::StatusApplicantPending, $intake->user->status);
        $this->assertTrue($intake->user->hasRole('applicant'));
        $this->assertSame('juan.applicant@example.test', $intake->user->email);
        $this->assertSame('Juan Santos Dela Cruz', $intake->user->name);
        $this->assertNotNull($intake->orientation_modality_acknowledged_at);
        $this->assertNotNull($intake->orientation_policy_accepted_at);
        $this->assertSame([
            'psa_birth_certificate',
            'grade_11_card',
            'grade_12_card',
            'form_137',
            'good_moral',
            'diploma',
        ], $intake->required_documents);

        $this->assertDatabaseMissing('student_profiles', [
            'user_id' => $intake->user_id,
        ]);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => ApplicantIntake::class,
            'subject_id' => $intake->id,
            'event' => 'applicant_intake_created',
        ]);
    }

    public function test_it_blocks_duplicate_lrn_against_existing_student_profiles(): void
    {
        $existing = StudentProfile::factory()->create([
            'lrn' => '123456789012',
        ]);
        $term = Term::factory()->create(['is_active' => true]);

        $this->expectException(ValidationException::class);

        try {
            app(ApplicantIntakeService::class)->create($this->validPayload([
                'program_id' => $existing->program_id,
                'term_id' => $term->id,
                'lrn' => '123456789012',
            ]));
        } finally {
            $this->assertDatabaseMissing('users', [
                'email' => 'juan.applicant@example.test',
            ]);
        }
    }

    public function test_it_records_applicant_owned_document_upload_and_dispatches_ocr(): void
    {
        Queue::fake();

        $intake = ApplicantIntake::factory()->create([
            'required_documents' => [
                'psa_birth_certificate',
            ],
        ]);

        $upload = app(ApplicantIntakeService::class)->recordDocumentUpload($intake, [
            'document_type' => 'psa_birth_certificate',
            'file_disk' => 'local',
            'file_path' => 'applicant-documents/psa.jpg',
            'file_name' => 'psa.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 2048,
            'checksum' => 'abc123',
            'student_confirmed_payload' => [
                'first_name' => 'Juan',
            ],
        ]);

        $this->assertSame($intake->id, $upload->applicant_intake_id);
        $this->assertNull($upload->student_profile_id);
        $this->assertSame($intake->user_id, $upload->user_id);
        $this->assertSame(DocumentUpload::ReviewStatusUploaded, $upload->ocr_review_status);
        $this->assertSame(['first_name' => 'Juan'], $upload->student_confirmed_payload);

        Queue::assertPushed(
            ProcessDocumentOcrJob::class,
            fn (ProcessDocumentOcrJob $job): bool => $job->documentUploadId === $upload->id,
        );
    }

    public function test_approval_for_payment_requires_every_required_document_to_be_registrar_approved(): void
    {
        $registrar = $this->registrar();
        $intake = ApplicantIntake::factory()->create([
            'required_documents' => [
                'psa_birth_certificate',
                'grade_12_card',
            ],
        ]);

        $this->documentUpload($intake, 'psa_birth_certificate', DocumentUpload::ReviewStatusRegistrarApproved);
        $this->documentUpload($intake, 'grade_12_card', DocumentUpload::ReviewStatusPendingRegistrarReview);

        try {
            app(ApplicantIntakeService::class)->approveForPayment($intake, $registrar);
            $this->fail('Expected applicant approval to require all required documents to be approved.');
        } catch (ValidationException) {
            $this->assertSame(ApplicantIntake::StatusPending, $intake->refresh()->status);
        }

        $intake->documentUploads()
            ->where('document_type', 'grade_12_card')
            ->update(['ocr_review_status' => DocumentUpload::ReviewStatusRegistrarApproved]);

        $approved = app(ApplicantIntakeService::class)->approveForPayment($intake->refresh(), $registrar);

        $this->assertSame(ApplicantIntake::StatusApproved, $approved->status);
        $this->assertSame(User::StatusApplicantApproved, $approved->user->status);
        $this->assertSame($registrar->id, $approved->registrar_reviewed_by);
        $this->assertNotNull($approved->approved_at);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => ApplicantIntake::class,
            'subject_id' => $approved->id,
            'event' => 'applicant_intake_approved_for_payment',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        $program = Program::factory()->create(['department' => 'college']);

        return [
            'first_name' => 'Juan',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
            'suffix' => null,
            'email' => 'juan.applicant@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
            'program_id' => $program->id,
            'lrn' => '987654321012',
            'birthdate' => '2004-05-12',
            'place_of_birth' => 'Calamba, Laguna',
            'gender' => 'male',
            'civil_status' => 'single',
            'mothers_maiden_name' => 'Maria Santos',
            'contact_number' => '09171234567',
            'street' => 'Rizal Street',
            'barangay' => 'Poblacion',
            'city' => 'Calamba',
            'province' => 'Laguna',
            'region' => 'Region IV-A',
            'zip_code' => '4027',
            'father_name' => 'Pedro Dela Cruz',
            'father_occupation' => 'Driver',
            'mother_occupation' => 'Teacher',
            'education_level' => 'college',
            'year_level' => '1st Year',
            'applicant_type' => ApplicantIntake::ApplicantTypeNew,
            'preferred_modality' => 'online',
            'orientation_modality_acknowledged' => true,
            'orientation_policy_accepted' => true,
            ...$overrides,
        ];
    }

    private function registrar(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $registrar = User::factory()->create();
        $registrar->givePermissionTo(Permission::findOrCreate('approve-documents'));

        return $registrar;
    }

    private function documentUpload(ApplicantIntake $intake, string $documentType, string $reviewStatus): DocumentUpload
    {
        return DocumentUpload::query()->create([
            'applicant_intake_id' => $intake->id,
            'student_profile_id' => null,
            'user_id' => $intake->user_id,
            'term_id' => $intake->term_id,
            'document_type' => $documentType,
            'file_disk' => 'local',
            'file_path' => "applicant-documents/{$documentType}.jpg",
            'file_name' => "{$documentType}.jpg",
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'upload_status' => 'uploaded',
            'ocr_review_status' => $reviewStatus,
            'student_confirmed_payload' => [],
        ]);
    }
}
