<?php

namespace Tests\Feature;

use App\Actions\Applicants\ApplicantIntakeService;
use App\Filament\Applicant\Pages\Application;
use App\Filament\Applicant\Pages\Dashboard;
use App\Models\AdmissionOffering;
use App\Models\AdmissionRequirementPolicy;
use App\Models\ApplicantIntake;
use App\Models\DocumentRequirementItem;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApplicantIntakeSubmissionTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('applicant', 'web');
    }

    public function test_applicant_can_save_only_their_own_single_draft(): void
    {
        $applicant = $this->applicant();
        $otherApplicant = $this->applicant();
        $program = Program::factory()->create();

        $service = app(ApplicantIntakeService::class);
        $draft = $service->saveDraft($applicant, [
            'program_id' => $program->id,
            'contact_number' => '09123456789',
        ]);
        $updatedDraft = $service->saveDraft($applicant, [
            'program_id' => $program->id,
            'contact_number' => '09987654321',
        ]);

        $this->assertTrue($draft->is($updatedDraft));
        $this->assertSame(ApplicantIntake::StatusDraft, $updatedDraft->status);
        $this->assertSame('09987654321', $updatedDraft->contact_number);
        $this->assertSame(1, ApplicantIntake::query()->whereBelongsTo($applicant)->count());
        $this->assertSame(0, ApplicantIntake::query()->whereBelongsTo($otherApplicant)->count());
    }

    public function test_applicant_page_stores_identity_evidence_privately_when_saving_a_draft(): void
    {
        Storage::fake('local');

        $applicant = $this->applicant();
        $program = Program::factory()->create(['is_active' => true]);

        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)
            ->test(Application::class)
            ->fillForm([
                'program_id' => $program->id,
                'contact_number' => '09123456789',
                'identity_document_url' => UploadedFile::fake()->create(
                    'identity.pdf',
                    512,
                    'application/pdf',
                ),
            ])
            ->call('saveDraft')
            ->assertHasNoFormErrors()
            ->assertNotified('Application draft saved');

        $draft = $applicant->applicantIntake()->firstOrFail();

        $this->assertSame(ApplicantIntake::StatusDraft, $draft->status);
        $this->assertNotNull($draft->identity_document_url);
        Storage::disk('local')->assertExists($draft->identity_document_url);
    }

    public function test_submission_requires_complete_intake_and_identity_evidence(): void
    {
        $applicant = $this->applicant();
        $draft = app(ApplicantIntakeService::class)->saveDraft($applicant, []);

        try {
            app(ApplicantIntakeService::class)->submit($draft);
            $this->fail('Expected incomplete intake validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('program_id', $exception->errors());
            $this->assertArrayHasKey('identity_document_url', $exception->errors());
        }

        $this->assertSame(ApplicantIntake::StatusDraft, $draft->fresh()->status);
    }

    public function test_applicant_page_submits_a_complete_application(): void
    {
        Storage::fake('local');

        $applicant = $this->applicant();
        [$term, $program] = $this->admissionPolicy();
        $data = $this->completeIntakeData($term, $program);
        $data['identity_document_url'] = UploadedFile::fake()->create(
            'identity.pdf',
            512,
            'application/pdf',
        );

        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)
            ->test(Application::class)
            ->fillForm($data)
            ->call('submitApplication')
            ->assertHasNoFormErrors()
            ->assertNotified('Application submitted for Registrar review')
            ->assertRedirect(Dashboard::getUrl());

        $intake = $applicant->applicantIntake()->firstOrFail();

        $this->assertSame(ApplicantIntake::StatusPending, $intake->status);
        $this->assertNotNull($intake->identity_document_url);
        Storage::disk('local')->assertExists($intake->identity_document_url);
    }

    public function test_applicant_can_submit_a_complete_draft_and_policy_checklist_is_created(): void
    {
        $applicant = $this->applicant();
        [$term, $program] = $this->admissionPolicy();

        $service = app(ApplicantIntakeService::class);
        $draft = $service->saveDraft($applicant, $this->completeIntakeData($term, $program));
        $submitted = $service->submit($draft);

        $this->assertSame(ApplicantIntake::StatusPending, $submitted->status);
        $this->assertNotNull($submitted->submitted_at);
        $this->assertSame(1, $submitted->checklistItems()->count());
        $this->assertDatabaseHas('checklist_items', [
            'owner_type' => ApplicantIntake::class,
            'owner_id' => $submitted->id,
            'requirement_type' => 'psa_birth_certificate',
            'blocking_level' => 'blocks_handover',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => ApplicantIntake::class,
            'subject_id' => $submitted->id,
            'event' => 'applicant_intake_submitted',
            'causer_id' => $applicant->id,
        ]);
    }

    public function test_duplicate_official_identity_blocks_submission_without_creating_another_intake(): void
    {
        $applicant = $this->applicant();
        [$term, $program] = $this->admissionPolicy();
        $existingStudent = User::factory()->create();

        StudentProfile::factory()->create([
            'user_id' => $existingStudent->id,
            'lrn' => '123456789012',
        ]);

        $service = app(ApplicantIntakeService::class);
        $draft = $service->saveDraft($applicant, $this->completeIntakeData($term, $program));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A matching applicant or student record already exists.');

        $service->submit($draft);
    }

    public function test_submission_fails_when_no_active_admission_policy_matches(): void
    {
        $applicant = $this->applicant();
        $term = Term::factory()->create(['is_active' => true]);
        $program = Program::factory()->create(['is_active' => true]);

        $service = app(ApplicantIntakeService::class);
        $draft = $service->saveDraft($applicant, $this->completeIntakeData($term, $program));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No published admission offering matches this applicant scope.');

        $service->submit($draft);
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

    /**
     * @return array{Term, Program}
     */
    private function admissionPolicy(): array
    {
        $term = Term::factory()->create(['is_active' => true]);
        $program = Program::factory()->create(['is_active' => true]);
        $offering = AdmissionOffering::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'year_level' => '1st Year',
        ]);
        $policy = AdmissionRequirementPolicy::factory()->create([
            'admission_offering_id' => $offering->id,
        ]);
        DocumentRequirementItem::factory()->create([
            'admission_requirement_policy_id' => $policy->id,
            'key' => 'psa_birth_certificate',
            'gate_type' => DocumentRequirementItem::GateTypeAdmission,
            'permitted_evidence_methods' => ['physical_copy'],
        ]);

        return [$term, $program];
    }

    /**
     * @return array<string, mixed>
     */
    private function completeIntakeData(Term $term, Program $program): array
    {
        return [
            'term_id' => $term->id,
            'program_id' => $program->id,
            'lrn' => '123456789012',
            'birthdate' => '2005-05-10',
            'place_of_birth' => 'Manila',
            'gender' => 'female',
            'civil_status' => 'single',
            'contact_number' => '09123456789',
            'street' => '123 Main Street',
            'barangay' => 'San Isidro',
            'city' => 'Cabuyao',
            'province' => 'Laguna',
            'year_level' => '1st Year',
            'applicant_type' => ApplicantIntake::ApplicantTypeNew,
            'preferred_modality' => 'on_site',
            'identity_document_url' => 'applicant-identity-documents/identity.pdf',
            'orientation_modality_acknowledged' => true,
            'orientation_policy_accepted' => true,
        ];
    }
}
