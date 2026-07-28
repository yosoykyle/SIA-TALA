<?php

namespace Tests\Feature;

use App\Actions\Applicants\ApplicantIntakeService;
use App\Filament\Applicant\Pages\Application;
use App\Filament\Applicant\Pages\Dashboard;
use App\Models\AdmissionRequirementPolicy;
use App\Models\ApplicantIntake;
use App\Models\CalendarEvent;
use App\Models\ChecklistItem;
use App\Models\DocumentEvidence;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApplicantIntakeSubmissionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('test_tala_db', config('database.connections.mysql.database'));
        Role::findOrCreate('applicant', 'web');
        Storage::fake('local');
        AdmissionRequirementPolicy::query()->update([
            'state' => AdmissionRequirementPolicy::StateSuperseded,
        ]);
    }

    public function test_applicant_can_save_only_their_own_single_draft(): void
    {
        $applicant = $this->applicant();
        $otherApplicant = $this->applicant();
        [$term, $program] = $this->scope();
        $data = $this->draftData($term, $program);

        $service = app(ApplicantIntakeService::class);
        $draft = $service->saveDraft($applicant, [
            ...$data,
            'phone' => '09123456789',
            'modality_preference' => ApplicantIntake::ModalityPreferenceOnline,
        ]);
        $updatedDraft = $service->saveDraft($applicant, [...$data, 'phone' => '09987654321']);

        $this->assertTrue($draft->is($updatedDraft));
        $this->assertSame(ApplicantIntake::StatusDraft, $updatedDraft->status);
        $this->assertSame('09987654321', $updatedDraft->phone);
        $this->assertNull($updatedDraft->modality_preference);
        $this->assertSame(1, ApplicantIntake::query()->whereBelongsTo($applicant)->count());
        $this->assertSame(0, ApplicantIntake::query()->whereBelongsTo($otherApplicant)->count());
    }

    public function test_applicant_page_stores_identity_evidence_privately_when_saving_a_draft(): void
    {
        $applicant = $this->applicant();
        [$term, $program, $identityPolicy] = $this->admissionPolicy();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)
            ->test(Application::class)
            ->fillForm([
                ...$this->draftData($term, $program),
                "document_uploads.{$identityPolicy->id}" => UploadedFile::fake()->create('identity.pdf', 512, 'application/pdf'),
                'information_confirmed' => true,
            ])
            ->call('saveDraft')
            ->assertHasNoFormErrors()
            ->assertNotified('Application draft saved');

        $draft = $applicant->applicantIntake()->firstOrFail();
        $this->assertSame(ApplicantIntake::StatusDraft, $draft->status);
        $this->assertNotNull($draft->identity_evidence_reference);
        Storage::disk('local')->assertExists($draft->identity_evidence_reference);
    }

    public function test_submission_requires_complete_intake_and_identity_evidence(): void
    {
        $applicant = $this->applicant();
        [$term, $program, $identityPolicy] = $this->admissionPolicy();
        $draft = app(ApplicantIntakeService::class)->saveDraft($applicant, $this->completeIntakeData($applicant, $term, $program, writeEvidence: false));

        try {
            app(ApplicantIntakeService::class)->submit($draft, true);
            $this->fail('Expected incomplete intake validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey("document_uploads.{$identityPolicy->id}", $exception->errors());
        }

        $this->assertSame(ApplicantIntake::StatusDraft, $draft->fresh()->status);
    }

    public function test_applicant_page_submits_a_complete_application(): void
    {
        $applicant = $this->applicant();
        [$term, $program, $identityPolicy] = $this->admissionPolicy();
        $birthPolicy = AdmissionRequirementPolicy::factory()->create([
            'requirement_type' => 'BIRTH_CERTIFICATE',
            'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
            'blocking_level' => ChecklistItem::BlockingHandover,
        ]);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)
            ->test(Application::class)
            ->fillForm([
                ...$this->completeIntakeData($applicant, $term, $program, writeEvidence: false),
                "document_uploads.{$identityPolicy->id}" => UploadedFile::fake()->create('identity.pdf', 512, 'application/pdf'),
                "document_uploads.{$birthPolicy->id}" => UploadedFile::fake()->create('birth-certificate.pdf', 512, 'application/pdf'),
                'information_confirmed' => true,
            ])
            ->call('submitApplication')
            ->assertHasNoFormErrors()
            ->assertNotified('Application submitted for Registrar review')
            ->assertRedirect(Dashboard::getUrl());

        $intake = $applicant->applicantIntake()->firstOrFail();
        $this->assertSame(ApplicantIntake::StatusPending, $intake->status);
        Storage::disk('local')->assertExists($intake->identity_evidence_reference);
        $this->assertSame(2, $intake->checklistItems()->count());
        $this->assertSame(
            2,
            DocumentEvidence::query()
                ->whereHas('checklistItem', fn ($query) => $query->where('applicant_intake_id', $intake->id))
                ->count(),
        );
    }

    public function test_applicant_page_shows_the_required_declaration_before_submission(): void
    {
        $applicant = $this->applicant();
        [$term, $program, $identityPolicy] = $this->admissionPolicy();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)
            ->test(Application::class)
            ->fillForm([
                ...$this->completeIntakeData($applicant, $term, $program, writeEvidence: false),
                "document_uploads.{$identityPolicy->id}" => UploadedFile::fake()->create('identity.pdf', 512, 'application/pdf'),
                'information_confirmed' => false,
            ])
            ->call('submitApplication')
            ->assertHasErrors(['data.information_confirmed']);

        $this->assertNull($applicant->applicantIntake()->first());
    }

    public function test_submit_action_is_bound_to_the_wizards_final_step(): void
    {
        $applicant = $this->applicant();
        $this->scope();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)
            ->test(Application::class)
            ->assertSee('Submit Application')
            ->assertSeeHtml('wire:submit="submitApplication"')
            ->assertSeeHtml('x-bind:class="{ \'fi-hidden\': ! isLastStep() }"')
            ->assertDontSeeHtml('wire:click="submitApplication"');
    }

    public function test_applicant_page_explains_when_no_effective_requirement_policy_exists(): void
    {
        AdmissionRequirementPolicy::query()->update([
            'state' => AdmissionRequirementPolicy::StateSuperseded,
        ]);

        $applicant = $this->applicant();
        [$term, $program] = $this->scope();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)
            ->test(Application::class)
            ->fillForm([
                ...$this->completeIntakeData($applicant, $term, $program, writeEvidence: false),
                'information_confirmed' => true,
            ])
            ->call('submitApplication')
            ->assertNotified('Application cannot be submitted');

        $this->assertSame(ApplicantIntake::StatusDraft, $applicant->applicantIntake()->sole()->status);
    }

    public function test_applicant_can_submit_a_complete_draft_and_policy_checklist_is_created(): void
    {
        $applicant = $this->applicant();
        [$term, $program] = $this->admissionPolicy();
        $service = app(ApplicantIntakeService::class);
        $submitted = $service->submit($service->saveDraft($applicant, $this->completeIntakeData($applicant, $term, $program)), true);

        $this->assertSame(ApplicantIntake::StatusPending, $submitted->status);
        $this->assertNotNull($submitted->submitted_at);
        $this->assertDatabaseHas('checklist_items', [
            'owner_type' => ChecklistItem::OwnerApplicant,
            'applicant_intake_id' => $submitted->id,
            'student_profile_id' => null,
            'requirement_type' => 'IDENTITY_DOCUMENT',
            'blocking_level' => ChecklistItem::BlockingHandover,
        ]);
        $this->assertDatabaseHas('document_evidence', [
            'checklist_item_id' => $submitted->checklistItems()->firstOrFail()->id,
            'disk' => 'local',
            'status' => 'SUBMITTED',
        ]);
    }

    public function test_duplicate_official_identity_blocks_submission_without_creating_another_intake(): void
    {
        $applicant = $this->applicant();
        [$term, $program] = $this->admissionPolicy();
        StudentProfile::factory()->create([
            'first_name' => $applicant->first_name,
            'last_name' => $applicant->last_name,
            'birth_date' => '2005-05-10',
        ]);
        $draft = app(ApplicantIntakeService::class)->saveDraft($applicant, $this->completeIntakeData($applicant, $term, $program));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A matching applicant or student record already exists.');
        app(ApplicantIntakeService::class)->submit($draft, true);
    }

    public function test_submission_fails_when_no_active_admission_policy_matches(): void
    {
        AdmissionRequirementPolicy::query()->update([
            'state' => AdmissionRequirementPolicy::StateSuperseded,
        ]);

        $applicant = $this->applicant();
        [$term, $program] = $this->scope();
        $draft = app(ApplicantIntakeService::class)->saveDraft($applicant, $this->completeIntakeData($applicant, $term, $program));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No effective admission requirement policy matches this intake.');
        app(ApplicantIntakeService::class)->submit($draft, true);
    }

    public function test_policy_driven_multi_upload_creates_one_evidence_record_per_digital_requirement(): void
    {
        $applicant = $this->applicant();
        [$term, $program, $identityPolicy] = $this->admissionPolicy();
        $birthPolicy = AdmissionRequirementPolicy::factory()->create([
            'requirement_type' => 'BIRTH_CERTIFICATE',
            'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
            'blocking_level' => ChecklistItem::BlockingHandover,
        ]);
        AdmissionRequirementPolicy::factory()->create([
            'requirement_type' => 'FORM_137',
            'evidence_method' => ChecklistItem::EvidenceMethodPhysicalCopy,
            'blocking_level' => ChecklistItem::BlockingEnrollment,
        ]);
        $identityPath = "applicant-requirement-documents/{$applicant->id}/{$identityPolicy->id}/identity.pdf";
        $birthPath = "applicant-requirement-documents/{$applicant->id}/{$birthPolicy->id}/birth-certificate.pdf";
        Storage::disk('local')->put($identityPath, "%PDF-1.4\nidentity\n%%EOF");
        Storage::disk('local')->put($birthPath, "%PDF-1.4\nbirth\n%%EOF");

        $draft = app(ApplicantIntakeService::class)->saveDraft($applicant, [
            ...$this->completeIntakeData($applicant, $term, $program, writeEvidence: false),
            'document_uploads' => [
                $identityPolicy->id => $identityPath,
                $birthPolicy->id => $birthPath,
            ],
        ]);
        $submitted = app(ApplicantIntakeService::class)->submit($draft, true);

        $this->assertSame(3, $submitted->checklistItems()->count());
        $this->assertSame(
            2,
            DocumentEvidence::query()
                ->whereHas('checklistItem', fn ($query) => $query->where('applicant_intake_id', $submitted->id))
                ->count(),
        );
        $this->assertSame(
            2,
            $submitted->checklistItems()->where('status', ChecklistItem::StatusReceivedDigital)->count(),
        );
        $this->assertSame($identityPath, $submitted->identity_evidence_reference);
    }

    public function test_partial_draft_is_saved_but_missing_blocking_digital_requirement_stops_submission(): void
    {
        $applicant = $this->applicant();
        [$term, $program, $identityPolicy] = $this->admissionPolicy();
        $birthPolicy = AdmissionRequirementPolicy::factory()->create([
            'requirement_type' => 'BIRTH_CERTIFICATE',
            'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
            'blocking_level' => ChecklistItem::BlockingHandover,
        ]);
        $identityPath = "applicant-requirement-documents/{$applicant->id}/{$identityPolicy->id}/partial-identity.pdf";
        Storage::disk('local')->put($identityPath, "%PDF-1.4\nidentity\n%%EOF");
        $draft = app(ApplicantIntakeService::class)->saveDraft($applicant, [
            ...$this->completeIntakeData($applicant, $term, $program, writeEvidence: false),
            'document_uploads' => [$identityPolicy->id => $identityPath],
        ]);

        $this->assertSame(ApplicantIntake::StatusDraft, $draft->status);
        $this->assertSame([$identityPolicy->id => $identityPath], $draft->draft_document_references);

        try {
            app(ApplicantIntakeService::class)->submit($draft, true);
            $this->fail('Expected the missing blocking digital requirement to stop submission.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey("document_uploads.{$birthPolicy->id}", $exception->errors());
        }
    }

    public function test_nonblocking_digital_requirement_may_remain_pending_at_submission(): void
    {
        $applicant = $this->applicant();
        [$term, $program, $identityPolicy] = $this->admissionPolicy();
        $optionalPolicy = AdmissionRequirementPolicy::factory()->create([
            'requirement_type' => 'GOOD_MORAL',
            'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
            'blocking_level' => ChecklistItem::BlockingAdvisoryOnly,
        ]);
        $identityPath = "applicant-requirement-documents/{$applicant->id}/{$identityPolicy->id}/required-only.pdf";
        Storage::disk('local')->put($identityPath, "%PDF-1.4\nidentity\n%%EOF");

        $submitted = app(ApplicantIntakeService::class)->submit(
            app(ApplicantIntakeService::class)->saveDraft($applicant, [
                ...$this->completeIntakeData($applicant, $term, $program, writeEvidence: false),
                'document_uploads' => [$identityPolicy->id => $identityPath],
            ]),
            true,
        );
        $optionalItem = $submitted->checklistItems()->where('source_policy_id', $optionalPolicy->id)->sole();

        $this->assertSame(ChecklistItem::StatusPending, $optionalItem->status);
        $this->assertSame(0, $optionalItem->documentEvidence()->count());
    }

    public function test_changing_application_scope_removes_unretained_draft_files(): void
    {
        $applicant = $this->applicant();
        [$term, $program, $firstTimePolicy] = $this->admissionPolicy();
        $transferPolicy = AdmissionRequirementPolicy::factory()->create([
            'admission_category' => ApplicantIntake::AdmissionCategoryTransfer,
            'credential_basis' => ApplicantIntake::CredentialBasisTransferCredentials,
            'requirement_type' => 'IDENTITY_DOCUMENT',
            'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
            'blocking_level' => ChecklistItem::BlockingHandover,
        ]);
        $oldPath = "applicant-requirement-documents/{$applicant->id}/{$firstTimePolicy->id}/old-scope.pdf";
        $newPath = "applicant-requirement-documents/{$applicant->id}/{$transferPolicy->id}/new-scope.pdf";
        Storage::disk('local')->put($oldPath, 'old');
        Storage::disk('local')->put($newPath, 'new');
        $service = app(ApplicantIntakeService::class);
        $service->saveDraft($applicant, [
            ...$this->draftData($term, $program),
            'document_uploads' => [$firstTimePolicy->id => $oldPath],
        ]);
        $updated = $service->saveDraft($applicant, [
            ...$this->draftData($term, $program),
            'admission_category' => ApplicantIntake::AdmissionCategoryTransfer,
            'credential_basis' => ApplicantIntake::CredentialBasisTransferCredentials,
            'document_uploads' => [$transferPolicy->id => $newPath],
        ]);

        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($newPath);
        $this->assertSame([$transferPolicy->id => $newPath], $updated->draft_document_references);
    }

    public function test_draft_rejects_another_applicants_private_upload_path_without_deleting_it(): void
    {
        $applicant = $this->applicant();
        $otherApplicant = $this->applicant();
        [$term, $program, $identityPolicy] = $this->admissionPolicy();
        $otherPath = "applicant-requirement-documents/{$otherApplicant->id}/{$identityPolicy->id}/identity.pdf";
        Storage::disk('local')->put($otherPath, "%PDF-1.4\nother applicant\n%%EOF");

        try {
            app(ApplicantIntakeService::class)->saveDraft($applicant, [
                ...$this->completeIntakeData($applicant, $term, $program, writeEvidence: false),
                'document_uploads' => [$identityPolicy->id => $otherPath],
            ]);
            $this->fail('Expected another applicant\'s private path to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey("document_uploads.{$identityPolicy->id}", $exception->errors());
        }

        Storage::disk('local')->assertExists($otherPath);
        $this->assertNull($applicant->applicantIntake()->first());
    }

    public function test_tampered_stored_reference_is_neither_deleted_on_draft_update_nor_accepted_at_submission(): void
    {
        $applicant = $this->applicant();
        $otherApplicant = $this->applicant();
        [$term, $program, $identityPolicy] = $this->admissionPolicy();
        $service = app(ApplicantIntakeService::class);
        $draft = $service->saveDraft(
            $applicant,
            $this->completeIntakeData($applicant, $term, $program),
        );
        $otherPath = "applicant-requirement-documents/{$otherApplicant->id}/{$identityPolicy->id}/identity.pdf";
        Storage::disk('local')->put($otherPath, "%PDF-1.4\nother applicant\n%%EOF");
        $draft->forceFill([
            'draft_document_references' => [$identityPolicy->id => $otherPath],
            'identity_evidence_reference' => $otherPath,
        ])->save();

        $updated = $service->saveDraft(
            $applicant,
            $this->completeIntakeData($applicant, $term, $program, writeEvidence: false),
        );

        Storage::disk('local')->assertExists($otherPath);
        $updated->forceFill([
            'draft_document_references' => [$identityPolicy->id => $otherPath],
            'identity_evidence_reference' => $otherPath,
        ])->save();

        try {
            $service->submit($updated->fresh(), true);
            $this->fail('Expected the stored cross-applicant reference to be rejected at submission.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey("document_uploads.{$identityPolicy->id}", $exception->errors());
        }

        Storage::disk('local')->assertExists($otherPath);
        $this->assertSame(ApplicantIntake::StatusDraft, $updated->fresh()->status);
    }

    private function applicant(): User
    {
        $user = User::factory()->create(['status' => User::StatusApplicantPending]);
        $user->assignRole('applicant');

        return $user;
    }

    /** @return array{Term, Program} */
    private function scope(): array
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);

        CalendarEvent::factory()->for($term)->create([
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

        return [$term, Program::factory()->create(['is_active' => true])];
    }

    /** @return array{Term, Program, AdmissionRequirementPolicy} */
    private function admissionPolicy(): array
    {
        [$term, $program] = $this->scope();
        $policy = AdmissionRequirementPolicy::factory()->create([
            'requirement_type' => 'IDENTITY_DOCUMENT',
            'evidence_method' => 'DIGITAL_UPLOAD',
            'blocking_level' => ChecklistItem::BlockingHandover,
        ]);

        return [$term, $program, $policy];
    }

    /** @return array<string, mixed> */
    private function draftData(Term $term, Program $program): array
    {
        return [
            'term_id' => $term->id,
            'program_id' => $program->id,
            'admission_category' => ApplicantIntake::AdmissionCategoryFirstTimeCollege,
            'credential_basis' => ApplicantIntake::CredentialBasisSeniorHighSchool,
        ];
    }

    /** @return array<string, mixed> */
    private function completeIntakeData(User $applicant, Term $term, Program $program, bool $writeEvidence = true): array
    {
        $path = "applicant-identity-documents/{$applicant->id}/identity.pdf";

        if ($writeEvidence) {
            Storage::disk('local')->put($path, 'identity evidence');
        }

        $data = [
            ...$this->draftData($term, $program),
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
        ];

        if ($writeEvidence) {
            $data['identity_evidence_reference'] = $path;
        }

        return $data;
    }
}
