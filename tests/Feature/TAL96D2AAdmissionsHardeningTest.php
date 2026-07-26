<?php

namespace Tests\Feature;

use App\Actions\Applicants\ApplicantEvidenceService;
use App\Actions\Applicants\ApplicantIntakeService;
use App\Actions\Applicants\ApplicantReviewService;
use App\Actions\Applicants\WithdrawApplicantIntake;
use App\Filament\Applicant\Pages\Application;
use App\Filament\Applicant\Pages\Dashboard;
use App\Filament\Applicant\Pages\Requirements;
use App\Filament\Resources\ApplicantIntakes\Pages\ViewApplicantIntake;
use App\Models\AdmissionRequirementPolicy;
use App\Models\ApplicantIntake;
use App\Models\CalendarEvent;
use App\Models\ChecklistItem;
use App\Models\DocumentEvidence;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL96D2AAdmissionsHardeningTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('test_tala_db', config('database.connections.mysql.database'));
        foreach (['applicant', User::StaffRoleRegistrar, User::StaffRoleAccounting] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Permission::findOrCreate('approve-documents', 'web');
        Storage::fake('local');
    }

    public function test_submission_requires_the_applicant_declaration(): void
    {
        $applicant = $this->applicant();
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $this->openAdmissions($term);
        $program = Program::factory()->create(['is_active' => true]);
        AdmissionRequirementPolicy::factory()->create([
            'requirement_type' => 'IDENTITY_DOCUMENT',
            'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
            'blocking_level' => ChecklistItem::BlockingHandover,
        ]);
        $path = "applicant-identity-documents/{$applicant->id}/declaration.pdf";
        Storage::disk('local')->put($path, 'identity evidence');
        $draft = app(ApplicantIntakeService::class)->saveDraft(
            $applicant,
            $this->completeDraftData($term, $program, $path),
        );

        try {
            app(ApplicantIntakeService::class)->submit($draft, false);
            $this->fail('Expected an unconfirmed application to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('information_confirmed', $exception->errors());
        }

        $this->assertSame(ApplicantIntake::StatusDraft, $draft->fresh()->status);
    }

    public function test_submission_rechecks_active_term_and_program_before_creating_checklist_records(): void
    {
        $applicant = $this->applicant();
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $this->openAdmissions($term);
        $program = Program::factory()->create(['is_active' => true]);
        AdmissionRequirementPolicy::factory()->create([
            'requirement_type' => 'IDENTITY_DOCUMENT',
            'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
            'blocking_level' => ChecklistItem::BlockingHandover,
        ]);
        $path = "applicant-identity-documents/{$applicant->id}/inactive-scope.pdf";
        Storage::disk('local')->put($path, 'identity evidence');
        $draft = app(ApplicantIntakeService::class)->saveDraft(
            $applicant,
            $this->completeDraftData($term, $program, $path),
        );
        $term->forceFill(['state' => 'inactive'])->save();

        try {
            app(ApplicantIntakeService::class)->submit($draft, true);
            $this->fail('Expected an inactive term to block submission.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('term_id', $exception->errors());
        }

        $term->forceFill(['state' => Term::StateActive])->save();
        $program->forceFill(['is_active' => false])->save();

        try {
            app(ApplicantIntakeService::class)->submit($draft->fresh(), true);
            $this->fail('Expected an inactive program to block submission.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('program_id', $exception->errors());
        }

        $this->assertSame(ApplicantIntake::StatusDraft, $draft->fresh()->status);
        $this->assertSame(0, $draft->checklistItems()->count());
    }

    public function test_rejection_replacement_and_acceptance_keep_checklist_and_evidence_synchronized(): void
    {
        $applicant = $this->applicant();
        $intake = ApplicantIntake::factory()->create([
            'user_id' => $applicant->id,
            'status' => ApplicantIntake::StatusPending,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ]);
        [$item, $original] = $this->digitalRequirement($intake, $applicant);
        $registrar = $this->registrar();
        $service = app(ApplicantEvidenceService::class);

        $service->review($item, $registrar, ApplicantEvidenceService::DecisionReject, 'The name is unreadable.');

        $this->assertSame(ChecklistItem::StatusRejected, $item->fresh()->status);
        $this->assertSame(DocumentEvidence::StatusRejected, $original->fresh()->status);
        $this->assertSame(ApplicantIntake::StatusActionRequired, $intake->fresh()->status);
        $this->assertSame(User::StatusApplicantActionRequired, $applicant->fresh()->status);
        $this->assertSame('The name is unreadable.', $item->fresh()->waiver_reason);

        $replacementPath = "applicant-evidence-replacements/{$applicant->id}/corrected.pdf";
        Storage::disk('local')->put($replacementPath, 'corrected identity evidence');
        $replacement = $service->replace($intake->fresh(), $item->fresh(), $applicant->fresh(), $replacementPath);

        $this->assertSame($original->id, $replacement->replaces_document_evidence_id);
        $this->assertSame(DocumentEvidence::StatusSubmitted, $replacement->status);
        $this->assertSame(ChecklistItem::StatusReceivedDigital, $item->fresh()->status);
        $this->assertSame(ApplicantIntake::StatusPending, $intake->fresh()->status);

        $service->review($item->fresh(), $registrar, ApplicantEvidenceService::DecisionAccept);

        $this->assertSame(ChecklistItem::StatusAccepted, $item->fresh()->status);
        $this->assertSame(DocumentEvidence::StatusAccepted, $replacement->fresh()->status);
        $this->assertNull($item->fresh()->waiver_reason);
    }

    public function test_physical_requirement_must_be_recorded_received_before_verification(): void
    {
        $applicant = $this->applicant();
        $intake = ApplicantIntake::factory()->create([
            'user_id' => $applicant->id,
            'status' => ApplicantIntake::StatusPending,
        ]);
        $policy = AdmissionRequirementPolicy::factory()->create([
            'requirement_type' => 'ORIGINAL_CREDENTIALS',
            'evidence_method' => ChecklistItem::EvidenceMethodPhysicalCopy,
            'blocking_level' => ChecklistItem::BlockingEnrollment,
        ]);
        $item = ChecklistItem::factory()->create([
            'owner_type' => ChecklistItem::OwnerApplicant,
            'applicant_intake_id' => $intake->id,
            'student_profile_id' => null,
            'source_policy_id' => $policy->id,
            'requirement_type' => 'ORIGINAL_CREDENTIALS',
            'evidence_method' => ChecklistItem::EvidenceMethodPhysicalCopy,
            'blocking_level' => ChecklistItem::BlockingEnrollment,
            'status' => ChecklistItem::StatusPending,
            'verification_status' => ChecklistItem::VerificationNotReviewed,
        ]);
        $registrar = $this->registrar();
        $service = app(ApplicantEvidenceService::class);

        try {
            $service->review($item, $registrar, ApplicantEvidenceService::DecisionAccept);
            $this->fail('Expected physical evidence verification to require recorded receipt first.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('evidence', $exception->errors());
        }

        $service->recordPhysicalReceipt($item->fresh(), $registrar, 'OR-2026-0001');

        $this->assertSame(ChecklistItem::StatusReceivedPhysical, $item->fresh()->status);
        $this->assertSame($registrar->id, $item->fresh()->reviewed_by);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => ChecklistItem::class,
            'subject_id' => $item->id,
            'event' => 'applicant_physical_evidence_received',
        ]);

        $service->review($item->fresh(), $registrar, ApplicantEvidenceService::DecisionAccept);

        $this->assertSame(ChecklistItem::StatusAccepted, $item->fresh()->status);
        $this->assertSame(ChecklistItem::VerificationVerified, $item->fresh()->verification_status);
    }

    public function test_registrar_evaluation_and_approval_are_ordered_and_block_unresolved_handover_requirements(): void
    {
        $applicant = $this->applicant();
        $intake = ApplicantIntake::factory()->create([
            'user_id' => $applicant->id,
            'status' => ApplicantIntake::StatusPending,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ]);
        [$item] = $this->digitalRequirement($intake, $applicant);
        $registrar = $this->registrar();
        $review = app(ApplicantReviewService::class);

        $review->markForEvaluation($intake, $registrar);

        $this->assertSame(ApplicantIntake::StatusForEvaluation, $intake->fresh()->status);
        $this->assertSame(User::StatusApplicantForEvaluation, $applicant->fresh()->status);

        try {
            $review->approve($intake->fresh(), $registrar);
            $this->fail('Expected an unresolved handover requirement to block approval.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('checklist', $exception->errors());
        }

        app(ApplicantEvidenceService::class)->review(
            $item,
            $registrar,
            ApplicantEvidenceService::DecisionAccept,
        );
        $review->approve($intake->fresh(), $registrar);

        $this->assertSame(ApplicantIntake::StatusApproved, $intake->fresh()->status);
        $this->assertSame($registrar->id, $intake->fresh()->approved_by);
        $this->assertSame(User::StatusApplicantApproved, $applicant->fresh()->status);
    }

    public function test_filament_registrar_actions_expose_the_ordered_evaluation_and_approval_flow(): void
    {
        $applicant = $this->applicant();
        $intake = ApplicantIntake::factory()->create([
            'user_id' => $applicant->id,
            'status' => ApplicantIntake::StatusPending,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ]);
        [$item] = $this->digitalRequirement($intake, $applicant);
        $registrar = $this->registrar();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)
            ->test(ViewApplicantIntake::class, ['record' => $intake->getRouteKey()])
            ->assertActionVisible('markForEvaluation')
            ->callAction('markForEvaluation')
            ->assertNotified('Application marked for evaluation');

        app(ApplicantEvidenceService::class)->review(
            $item->fresh(),
            $registrar,
            ApplicantEvidenceService::DecisionAccept,
        );

        Livewire::actingAs($registrar)
            ->test(ViewApplicantIntake::class, ['record' => $intake->fresh()->getRouteKey()])
            ->assertActionVisible('approveApplication')
            ->callAction('approveApplication')
            ->assertNotified('Application approved for handover');

        $this->assertSame(ApplicantIntake::StatusApproved, $intake->fresh()->status);
    }

    public function test_applicant_requirements_page_submits_a_private_corrected_version(): void
    {
        $applicant = $this->applicant();
        $intake = ApplicantIntake::factory()->create([
            'user_id' => $applicant->id,
            'status' => ApplicantIntake::StatusPending,
        ]);
        [$item, $original] = $this->digitalRequirement($intake, $applicant);
        app(ApplicantEvidenceService::class)->review(
            $item,
            $this->registrar(),
            ApplicantEvidenceService::DecisionReject,
            'Upload a clearer copy.',
        );
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant->fresh())
            ->test(Requirements::class)
            ->fillForm([
                'requirement_id' => $item->id,
                'replacement_file' => UploadedFile::fake()->create('corrected.pdf', 512, 'application/pdf'),
            ])
            ->call('replaceEvidence')
            ->assertHasNoFormErrors()
            ->assertNotified('Corrected evidence submitted')
            ->assertRedirect(Dashboard::getUrl());

        $replacement = DocumentEvidence::query()
            ->where('checklist_item_id', $item->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($original->id, $replacement->replaces_document_evidence_id);
        $this->assertSame(DocumentEvidence::StatusSubmitted, $replacement->status);
        Storage::disk('local')->assertExists($replacement->path);
    }

    public function test_applicant_may_withdraw_only_own_unreviewed_draft_or_pending_intake(): void
    {
        $applicant = $this->applicant();
        $intake = ApplicantIntake::factory()->create([
            'user_id' => $applicant->id,
            'status' => ApplicantIntake::StatusPending,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ]);

        app(WithdrawApplicantIntake::class)->execute(
            $intake,
            $applicant,
            'I am withdrawing this synthetic acceptance-test application.',
        );

        $this->assertSame(ApplicantIntake::StatusWithdrawn, $intake->fresh()->status);
        $this->assertNotNull($intake->fresh()->archived_at);
        $this->assertSame(User::StatusApplicantWithdrawn, $applicant->fresh()->status);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => ApplicantIntake::class,
            'subject_id' => $intake->id,
            'event' => 'applicant_intake_withdrawn',
        ]);
    }

    public function test_unauthorized_staff_cannot_review_or_download_private_evidence(): void
    {
        $applicant = $this->applicant();
        $intake = ApplicantIntake::factory()->create(['user_id' => $applicant->id]);
        [$item] = $this->digitalRequirement($intake, $applicant);
        $accounting = User::factory()->create(['status' => User::StatusActive]);
        $accounting->assignRole(User::StaffRoleAccounting);

        $this->expectException(AuthorizationException::class);
        app(ApplicantEvidenceService::class)->review(
            $item,
            $accounting,
            ApplicantEvidenceService::DecisionAccept,
        );
    }

    public function test_inactive_registrar_cannot_review_private_evidence(): void
    {
        $applicant = $this->applicant();
        $intake = ApplicantIntake::factory()->create(['user_id' => $applicant->id]);
        [$item] = $this->digitalRequirement($intake, $applicant);
        $registrar = $this->registrar();
        $registrar->forceFill(['status' => User::StatusInactive])->save();

        $this->expectException(AuthorizationException::class);
        app(ApplicantEvidenceService::class)->review(
            $item,
            $registrar->fresh(),
            ApplicantEvidenceService::DecisionAccept,
        );
    }

    public function test_inactive_registrar_cannot_transition_an_application(): void
    {
        $applicant = $this->applicant();
        $intake = ApplicantIntake::factory()->create([
            'user_id' => $applicant->id,
            'status' => ApplicantIntake::StatusPending,
        ]);
        $registrar = $this->registrar();
        $registrar->forceFill(['status' => User::StatusArchived])->save();

        $this->expectException(AuthorizationException::class);
        app(ApplicantReviewService::class)->markForEvaluation($intake, $registrar->fresh());
    }

    public function test_approved_intake_rejects_stale_evidence_review_without_state_mutation(): void
    {
        $applicant = $this->applicant();
        $registrar = $this->registrar();
        $intake = ApplicantIntake::factory()->approved($registrar)->create([
            'user_id' => $applicant->id,
        ]);
        [$item, $evidence] = $this->digitalRequirement($intake, $applicant);

        $this->assertFalse($registrar->can('review', $intake));
        $this->assertTrue($registrar->can('downloadEvidence', $intake));

        try {
            app(ApplicantEvidenceService::class)->review(
                $item,
                $registrar,
                ApplicantEvidenceService::DecisionReject,
                'This stale review must not revoke an approval.',
            );
            $this->fail('Expected an approved intake to reject stale evidence review.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame(ApplicantIntake::StatusApproved, $intake->fresh()->status);
        $this->assertSame(ChecklistItem::StatusPending, $item->fresh()->status);
        $this->assertSame(DocumentEvidence::StatusSubmitted, $evidence->fresh()->status);
    }

    public function test_replacement_rejects_invalid_or_duplicate_private_files_without_losing_history(): void
    {
        $applicant = $this->applicant();
        $intake = ApplicantIntake::factory()->create([
            'user_id' => $applicant->id,
            'status' => ApplicantIntake::StatusPending,
        ]);
        [$item, $original] = $this->digitalRequirement($intake, $applicant);
        $service = app(ApplicantEvidenceService::class);
        $service->review(
            $item,
            $this->registrar(),
            ApplicantEvidenceService::DecisionReject,
            'Replace this file.',
        );

        $invalidPath = "applicant-evidence-replacements/{$applicant->id}/invalid.txt";
        Storage::disk('local')->put($invalidPath, 'not an allowed evidence type');

        try {
            $service->replace($intake->fresh(), $item->fresh(), $applicant->fresh(), $invalidPath);
            $this->fail('Expected an unsupported evidence type to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('replacement_file', $exception->errors());
        }

        $duplicatePath = "applicant-evidence-replacements/{$applicant->id}/duplicate.pdf";
        Storage::disk('local')->put($duplicatePath, "%PDF-1.4\noriginal identity evidence\n%%EOF");

        try {
            $service->replace($intake->fresh(), $item->fresh(), $applicant->fresh(), $duplicatePath);
            $this->fail('Expected an unchanged replacement to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('replacement_file', $exception->errors());
        }

        $this->assertSame(1, $item->documentEvidence()->count());
        $this->assertSame(DocumentEvidence::StatusRejected, $original->fresh()->status);
        Storage::disk('local')->assertMissing($invalidPath);
        Storage::disk('local')->assertMissing($duplicatePath);
    }

    public function test_replacement_rejects_another_applicants_private_path_without_deleting_it(): void
    {
        $applicant = $this->applicant();
        $otherApplicant = $this->applicant();
        $intake = ApplicantIntake::factory()->create([
            'user_id' => $applicant->id,
            'status' => ApplicantIntake::StatusPending,
        ]);
        [$item, $original] = $this->digitalRequirement($intake, $applicant);
        $service = app(ApplicantEvidenceService::class);
        $service->review(
            $item,
            $this->registrar(),
            ApplicantEvidenceService::DecisionReject,
            'Replace this file.',
        );
        $otherPath = "applicant-evidence-replacements/{$otherApplicant->id}/corrected.pdf";
        Storage::disk('local')->put($otherPath, "%PDF-1.4\nother applicant\n%%EOF");

        try {
            $service->replace($intake->fresh(), $item->fresh(), $applicant->fresh(), $otherPath);
            $this->fail('Expected another applicant\'s private path to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('replacement_file', $exception->errors());
        }

        Storage::disk('local')->assertExists($otherPath);
        $this->assertSame(1, $item->documentEvidence()->count());
        $this->assertSame(DocumentEvidence::StatusRejected, $original->fresh()->status);
    }

    public function test_authorized_private_evidence_download_is_audited(): void
    {
        $applicant = $this->applicant();
        $path = "applicant-identity-documents/{$applicant->id}/audited.pdf";
        Storage::disk('local')->put($path, 'private identity evidence');
        $intake = ApplicantIntake::factory()->create([
            'user_id' => $applicant->id,
            'identity_evidence_reference' => $path,
        ]);
        $registrar = $this->registrar();

        app(ApplicantEvidenceService::class)->downloadIdentityEvidence($intake, $registrar);

        $this->assertDatabaseHas('output_access_logs', [
            'output_type' => 'ADMISSION_EVIDENCE',
            'source_record_type' => ApplicantIntake::class,
            'source_record_id' => $intake->id,
            'actor_user_id' => $registrar->id,
            'action' => 'DOWNLOAD',
            'sensitivity' => 'RESTRICTED',
            'status' => 'SUCCESS',
        ]);
    }

    public function test_livewire_testing_upload_disk_is_configured_for_browser_acceptance(): void
    {
        $this->assertSame('local', config('filesystems.disks.tmp-for-tests.driver'));
        $this->assertSame(
            storage_path('framework/testing/disks/tmp-for-tests'),
            config('filesystems.disks.tmp-for-tests.root'),
        );
    }

    public function test_applicant_dashboard_explains_a_saved_draft_without_false_submission_empty_states(): void
    {
        $applicant = $this->applicant();
        $term = Term::factory()->create();
        $program = Program::factory()->create();
        ApplicantIntake::factory()->create([
            'user_id' => $applicant->id,
            'term_id' => $term->id,
            'program_id' => $program->id,
            'status' => ApplicantIntake::StatusDraft,
            'draft_document_references' => [
                '1' => "applicant-evidence/drafts/{$applicant->id}/identity.pdf",
            ],
        ]);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)
            ->test(Dashboard::class)
            ->assertOk()
            ->assertSee('Draft saved')
            ->assertSee('1 document attached to this draft')
            ->assertSee('The Registrar checklist and review history are created after you submit')
            ->assertDontSee('No requirements configured for this application.')
            ->assertDontSee('No digital uploads recorded yet.')
            ->assertSeeHtml('class="tala-status-grid"');
    }

    public function test_applicant_upload_field_identifies_a_file_restored_from_the_saved_draft(): void
    {
        $applicationSource = file_get_contents(app_path('Filament/Applicant/Pages/Application.php'));
        $applicant = $this->applicant();
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $program = Program::factory()->create(['is_active' => true]);
        $policy = AdmissionRequirementPolicy::factory()->create([
            'admission_category' => ApplicantIntake::AdmissionCategoryFirstTimeCollege,
            'credential_basis' => ApplicantIntake::CredentialBasisSeniorHighSchool,
            'requirement_type' => 'IDENTITY_DOCUMENT',
            'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
            'blocking_level' => ChecklistItem::BlockingHandover,
        ]);
        $path = "applicant-requirement-documents/{$applicant->id}/{$policy->id}/private.pdf";
        Storage::disk('local')->put($path, "%PDF-1.4\nsaved draft evidence\n%%EOF");
        ApplicantIntake::factory()->create([
            'user_id' => $applicant->id,
            'term_id' => $term->id,
            'program_id' => $program->id,
            'status' => ApplicantIntake::StatusDraft,
            'admission_category' => ApplicantIntake::AdmissionCategoryFirstTimeCollege,
            'credential_basis' => ApplicantIntake::CredentialBasisSeniorHighSchool,
            'draft_document_references' => [
                (string) $policy->id => $path,
            ],
        ]);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        $this->assertIsString($applicationSource);
        $this->assertStringContainsString('->openable()', $applicationSource);

        Livewire::actingAs($applicant)
            ->test(Application::class)
            ->assertOk()
            ->assertSee('Saved in this draft. Select the arrow beside the filename to open it, or Remove to replace it.');
    }

    public function test_guardian_address_can_follow_the_structured_applicant_address_without_losing_manual_editing(): void
    {
        $applicant = $this->applicant();
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $program = Program::factory()->create(['is_active' => true]);
        $intake = ApplicantIntake::factory()->create([
            'user_id' => $applicant->id,
            'term_id' => $term->id,
            'program_id' => $program->id,
            'status' => ApplicantIntake::StatusDraft,
            'address_street' => '70 Madrigal Compound',
            'address_barangay' => 'Bagong Silang',
            'address_city' => 'San Pedro',
            'address_district' => null,
            'address_province' => 'Laguna',
            'guardian_address' => '70 Madrigal Compound, Bagong Silang, San Pedro, Laguna',
        ]);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        $component = Livewire::actingAs($applicant)
            ->test(Application::class)
            ->assertSet('data.guardian_address_same_as_applicant', true)
            ->set('data.address_district', 'District 1')
            ->assertSet(
                'data.guardian_address',
                '70 Madrigal Compound, Bagong Silang, San Pedro, District 1, Laguna',
            )
            ->call('saveDraft')
            ->assertHasNoErrors();

        $this->assertSame(
            '70 Madrigal Compound, Bagong Silang, San Pedro, District 1, Laguna',
            $intake->fresh()?->guardian_address,
        );

        $component
            ->set('data.guardian_address_same_as_applicant', false)
            ->set('data.guardian_address', 'Separate guardian residence')
            ->set('data.address_street', '71 Madrigal Compound')
            ->assertSet('data.guardian_address', 'Separate guardian residence');
    }

    /** @return array<string, mixed> */
    private function completeDraftData(Term $term, Program $program, string $identityPath): array
    {
        return [
            'term_id' => $term->id,
            'program_id' => $program->id,
            'admission_category' => ApplicantIntake::AdmissionCategoryFirstTimeCollege,
            'credential_basis' => ApplicantIntake::CredentialBasisSeniorHighSchool,
            'modality_preference' => ApplicantIntake::ModalityPreferenceFaceToFace,
            'birth_date' => '2005-05-10',
            'gender' => 'FEMALE',
            'civil_status' => 'SINGLE',
            'birth_place' => 'San Pedro, Laguna',
            'phone' => '09123456789',
            'address_barangay' => 'Bagong Silang',
            'address_street' => '70 Madrigal Compound',
            'address_city' => 'San Pedro',
            'address_province' => 'Laguna',
            'prior_school' => 'Sample Senior High School',
            'guardian_name' => 'Synthetic Guardian',
            'guardian_phone' => '09987654321',
            'guardian_address' => 'San Pedro, Laguna',
            'identity_evidence_reference' => $identityPath,
        ];
    }

    private function applicant(): User
    {
        $user = User::factory()->create([
            'status' => User::StatusApplicantPending,
            'email_verified_at' => now(),
        ]);
        $user->assignRole('applicant');

        return $user;
    }

    private function openAdmissions(Term $term): CalendarEvent
    {
        return CalendarEvent::factory()->for($term)->create([
            'event_type' => CalendarEvent::TypeWindow,
            'scope_type' => CalendarEvent::ScopeInstitution,
            'process_key' => CalendarEvent::ProcessAdmissions,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'day_of_week' => null,
            'starts_at' => null,
            'ends_at' => null,
            'blocks_scheduling' => false,
            'state' => CalendarEvent::StateActive,
        ]);
    }

    private function registrar(): User
    {
        $user = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $user->assignRole(User::StaffRoleRegistrar);
        $user->givePermissionTo('approve-documents');

        return $user;
    }

    /** @return array{ChecklistItem, DocumentEvidence} */
    private function digitalRequirement(ApplicantIntake $intake, User $applicant): array
    {
        $policy = AdmissionRequirementPolicy::factory()->create([
            'requirement_type' => 'IDENTITY_DOCUMENT',
            'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
            'blocking_level' => ChecklistItem::BlockingHandover,
        ]);
        $item = ChecklistItem::factory()->create([
            'owner_type' => ChecklistItem::OwnerApplicant,
            'applicant_intake_id' => $intake->id,
            'student_profile_id' => null,
            'source_policy_id' => $policy->id,
            'requirement_type' => 'IDENTITY_DOCUMENT',
            'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
            'blocking_level' => ChecklistItem::BlockingHandover,
            'status' => ChecklistItem::StatusPending,
            'verification_status' => ChecklistItem::VerificationNotReviewed,
        ]);
        $path = "applicant-evidence/{$item->id}.pdf";
        Storage::disk('local')->put($path, "%PDF-1.4\noriginal identity evidence\n%%EOF");
        $evidence = DocumentEvidence::factory()->create([
            'checklist_item_id' => $item->id,
            'path' => $path,
            'checksum' => hash_file('sha256', Storage::disk('local')->path($path)),
            'status' => DocumentEvidence::StatusSubmitted,
            'uploaded_by' => $applicant->id,
        ]);

        return [$item, $evidence];
    }
}
