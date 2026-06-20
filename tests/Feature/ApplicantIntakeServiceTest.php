<?php

namespace Tests\Feature;

use App\Actions\Applicants\ApplicantIntakeService;
use App\Actions\Applicants\RetentionDocumentUndertakingService;
use App\Actions\Registrar\DocumentUploadReviewService;
use App\Jobs\ProcessDocumentOcrJob;
use App\Models\AdmissionOffering;
use App\Models\AdmissionRequirementPolicy;
use App\Models\ApplicantDocumentRequirement;
use App\Models\ApplicantIntake;
use App\Models\DocumentRequirementItem;
use App\Models\DocumentUpload;
use App\Models\Program;
use App\Models\RetentionDocumentUndertaking;
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
        $this->regularCollegePolicy($term, $program);

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
            'grade_12_card',
            'good_moral',
            'diploma',
        ], $intake->required_documents);
        $this->assertSame([
            'psa_birth_certificate',
            'grade_12_card',
            'good_moral',
        ], $intake->admissionGateDocumentTypes());
        $this->assertDatabaseHas('applicant_document_requirements', [
            'applicant_intake_id' => $intake->id,
            'item_key' => 'diploma',
            'gate_type' => DocumentRequirementItem::GateTypeRetention,
            'evidence_state' => ApplicantDocumentRequirement::EvidenceStatePending,
        ]);

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
        $this->regularCollegePolicy($term, $existing->program);

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
        $requirement = ApplicantDocumentRequirement::factory()->create([
            'applicant_intake_id' => $intake->id,
            'item_key' => 'psa_birth_certificate',
            'label' => 'PSA Birth Certificate',
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
        $this->assertSame($requirement->id, $upload->applicant_document_requirement_id);
        $this->assertNull($upload->student_profile_id);
        $this->assertSame($intake->user_id, $upload->user_id);
        $this->assertSame(DocumentUpload::ReviewStatusUploaded, $upload->ocr_review_status);
        $this->assertSame(['first_name' => 'Juan'], $upload->student_confirmed_payload);
        $this->assertSame(ApplicantDocumentRequirement::EvidenceStateSubmitted, $requirement->refresh()->evidence_state);

        Queue::assertPushed(
            ProcessDocumentOcrJob::class,
            fn (ProcessDocumentOcrJob $job): bool => $job->documentUploadId === $upload->id,
        );
    }

    public function test_approval_for_payment_requires_every_required_document_to_be_registrar_approved(): void
    {
        $registrar = $this->registrar();
        $program = Program::factory()->create(['department' => 'college']);
        $term = Term::factory()->create(['is_active' => true]);
        $this->regularCollegePolicy($term, $program);
        $intake = app(ApplicantIntakeService::class)->create($this->validPayload([
            'email' => 'payment-gate@example.test',
            'program_id' => $program->id,
            'term_id' => $term->id,
            'lrn' => '111222333444',
        ]));

        $this->documentUpload($intake, 'psa_birth_certificate', DocumentUpload::ReviewStatusRegistrarApproved);
        $this->documentUpload($intake, 'grade_12_card', DocumentUpload::ReviewStatusPendingRegistrarReview);
        $this->documentUpload($intake, 'good_moral', DocumentUpload::ReviewStatusRegistrarApproved);

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

    public function test_retention_documents_do_not_block_payment_unlock(): void
    {
        $registrar = $this->registrar();
        $program = Program::factory()->create(['department' => 'college']);
        $term = Term::factory()->create(['is_active' => true]);
        $this->regularCollegePolicy($term, $program);
        $intake = app(ApplicantIntakeService::class)->create($this->validPayload([
            'email' => 'retention-open@example.test',
            'program_id' => $program->id,
            'term_id' => $term->id,
            'lrn' => '222333444555',
        ]));

        $this->documentUpload($intake, 'psa_birth_certificate', DocumentUpload::ReviewStatusRegistrarApproved);
        $this->documentUpload($intake, 'grade_12_card', DocumentUpload::ReviewStatusRegistrarApproved);
        $this->documentUpload($intake, 'good_moral', DocumentUpload::ReviewStatusRegistrarApproved);

        $approved = app(ApplicantIntakeService::class)->approveForPayment($intake->refresh(), $registrar);

        $this->assertSame(ApplicantIntake::StatusApproved, $approved->status);
        $this->assertSame([
            'diploma',
        ], $approved->applicantDocumentRequirements()
            ->where('gate_type', DocumentRequirementItem::GateTypeRetention)
            ->where('evidence_state', ApplicantDocumentRequirement::EvidenceStatePending)
            ->pluck('item_key')
            ->all());
        $this->assertDatabaseHas('retention_document_undertakings', [
            'applicant_intake_id' => $approved->id,
            'status' => RetentionDocumentUndertaking::StatusActive,
        ]);
        $this->assertSame(
            'diploma',
            RetentionDocumentUndertaking::query()
                ->where('applicant_intake_id', $approved->id)
                ->firstOrFail()
                ->applicantDocumentRequirement
                ->item_key,
        );
    }

    public function test_intake_fails_closed_when_no_published_offering_matches(): void
    {
        $program = Program::factory()->create(['department' => 'college']);
        $term = Term::factory()->create(['is_active' => true]);

        $this->expectException(ValidationException::class);

        app(ApplicantIntakeService::class)->create($this->validPayload([
            'program_id' => $program->id,
            'term_id' => $term->id,
        ]));
    }

    public function test_document_approval_satisfies_materialized_requirement(): void
    {
        $registrar = $this->registrar();
        $intake = ApplicantIntake::factory()->create([
            'required_documents' => [
                'psa_birth_certificate',
            ],
        ]);
        $requirement = ApplicantDocumentRequirement::factory()->create([
            'applicant_intake_id' => $intake->id,
            'item_key' => 'psa_birth_certificate',
            'label' => 'PSA Birth Certificate',
        ]);
        $upload = $this->documentUpload($intake, 'psa_birth_certificate', DocumentUpload::ReviewStatusPendingRegistrarReview);
        $upload->forceFill([
            'applicant_document_requirement_id' => $requirement->id,
        ])->save();

        app(DocumentUploadReviewService::class)->approve($upload, $registrar);

        $this->assertSame(ApplicantDocumentRequirement::EvidenceStateSatisfied, $requirement->refresh()->evidence_state);
        $this->assertSame($upload->id, $requirement->satisfied_by_document_upload_id);
        $this->assertSame($registrar->id, $requirement->satisfied_by);
        $this->assertNotNull($requirement->satisfied_at);
    }

    public function test_document_approval_resolves_active_retention_undertaking(): void
    {
        $registrar = $this->registrar();
        $intake = ApplicantIntake::factory()->create([
            'required_documents' => [
                'diploma',
            ],
        ]);
        $requirement = ApplicantDocumentRequirement::factory()->create([
            'applicant_intake_id' => $intake->id,
            'item_key' => 'diploma',
            'label' => 'Diploma',
            'gate_type' => DocumentRequirementItem::GateTypeRetention,
        ]);
        $undertaking = RetentionDocumentUndertaking::factory()->create([
            'applicant_intake_id' => $intake->id,
            'applicant_document_requirement_id' => $requirement->id,
            'status' => RetentionDocumentUndertaking::StatusActive,
        ]);
        $upload = $this->documentUpload($intake, 'diploma', DocumentUpload::ReviewStatusPendingRegistrarReview);
        $upload->forceFill([
            'applicant_document_requirement_id' => $requirement->id,
        ])->save();

        app(DocumentUploadReviewService::class)->approve($upload, $registrar);

        $this->assertSame(RetentionDocumentUndertaking::StatusResolved, $undertaking->refresh()->status);
        $this->assertSame($registrar->id, $undertaking->resolved_by);
        $this->assertSame($upload->id, $undertaking->resolved_by_document_upload_id);
        $this->assertNotNull($undertaking->resolved_at);
    }

    public function test_retention_deadline_processor_marks_active_undertakings_overdue_without_canceling_intake(): void
    {
        $intake = ApplicantIntake::factory()->create([
            'status' => ApplicantIntake::StatusApproved,
        ]);
        $requirement = ApplicantDocumentRequirement::factory()->create([
            'applicant_intake_id' => $intake->id,
            'item_key' => 'diploma',
            'label' => 'Diploma',
            'gate_type' => DocumentRequirementItem::GateTypeRetention,
        ]);
        $undertaking = RetentionDocumentUndertaking::factory()->create([
            'applicant_intake_id' => $intake->id,
            'applicant_document_requirement_id' => $requirement->id,
            'status' => RetentionDocumentUndertaking::StatusActive,
            'due_at' => now()->subDay(),
        ]);

        $processed = app(RetentionDocumentUndertakingService::class)->processDeadlines(now()->toImmutable());

        $this->assertSame(1, $processed);
        $this->assertSame(RetentionDocumentUndertaking::StatusOverdue, $undertaking->refresh()->status);
        $this->assertSame('retention_document_overdue', $undertaking->hold_reason);
        $this->assertNotNull($undertaking->hold_applied_at);
        $this->assertSame(ApplicantIntake::StatusApproved, $intake->refresh()->status);
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
            'applicant_document_requirement_id' => $intake->applicantDocumentRequirements()
                ->where('item_key', $documentType)
                ->value('id'),
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

    private function regularCollegePolicy(Term $term, Program $program): AdmissionRequirementPolicy
    {
        $offering = AdmissionOffering::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'name' => 'Regular College Freshman',
            'education_level' => 'college',
            'entry_route' => AdmissionOffering::EntryRouteRegular,
            'prior_credential_pathway' => AdmissionOffering::PriorCredentialRegular,
            'year_level' => '1st Year',
            'status' => AdmissionOffering::StatusPublished,
        ]);

        $policy = AdmissionRequirementPolicy::factory()->create([
            'admission_offering_id' => $offering->id,
            'status' => AdmissionRequirementPolicy::StatusActive,
        ]);

        foreach ([
            ['psa_birth_certificate', 'PSA Birth Certificate', DocumentRequirementItem::GateTypeAdmission, 10],
            ['grade_12_card', 'Grade 12 Report Card', DocumentRequirementItem::GateTypeAdmission, 20],
            ['good_moral', 'Good Moral', DocumentRequirementItem::GateTypeAdmission, 30],
            ['diploma', 'Diploma', DocumentRequirementItem::GateTypeRetention, 40],
        ] as [$key, $label, $gateType, $sortOrder]) {
            DocumentRequirementItem::factory()->create([
                'admission_requirement_policy_id' => $policy->id,
                'key' => $key,
                'label' => $label,
                'gate_type' => $gateType,
                'sort_order' => $sortOrder,
                'deadline_strategy' => $gateType === DocumentRequirementItem::GateTypeRetention ? '30_days' : null,
            ]);
        }

        return $policy;
    }
}
