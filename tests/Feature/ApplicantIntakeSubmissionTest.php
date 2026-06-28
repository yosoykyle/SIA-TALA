<?php

namespace Tests\Feature;

use App\Actions\Applicants\ApplicantIntakeService;
use App\Filament\Applicant\Pages\Application;
use App\Filament\Applicant\Pages\Dashboard;
use App\Models\AdmissionRequirementPolicy;
use App\Models\ApplicantIntake;
use App\Models\ChecklistItem;
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
    }

    public function test_applicant_can_save_only_their_own_single_draft(): void
    {
        $applicant = $this->applicant();
        $otherApplicant = $this->applicant();
        [$term, $program] = $this->scope();
        $data = $this->draftData($term, $program);

        $service = app(ApplicantIntakeService::class);
        $draft = $service->saveDraft($applicant, [...$data, 'phone' => '09123456789']);
        $updatedDraft = $service->saveDraft($applicant, [...$data, 'phone' => '09987654321']);

        $this->assertTrue($draft->is($updatedDraft));
        $this->assertSame(ApplicantIntake::StatusDraft, $updatedDraft->status);
        $this->assertSame('09987654321', $updatedDraft->phone);
        $this->assertSame(1, ApplicantIntake::query()->whereBelongsTo($applicant)->count());
        $this->assertSame(0, ApplicantIntake::query()->whereBelongsTo($otherApplicant)->count());
    }

    public function test_applicant_page_stores_identity_evidence_privately_when_saving_a_draft(): void
    {
        $applicant = $this->applicant();
        [$term, $program] = $this->scope();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)
            ->test(Application::class)
            ->fillForm([
                ...$this->draftData($term, $program),
                'identity_evidence_reference' => UploadedFile::fake()->create('identity.pdf', 512, 'application/pdf'),
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
        [$term, $program] = $this->scope();
        $draft = app(ApplicantIntakeService::class)->saveDraft($applicant, $this->draftData($term, $program));

        try {
            app(ApplicantIntakeService::class)->submit($draft);
            $this->fail('Expected incomplete intake validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('birth_date', $exception->errors());
            $this->assertArrayHasKey('identity_evidence_reference', $exception->errors());
        }

        $this->assertSame(ApplicantIntake::StatusDraft, $draft->fresh()->status);
    }

    public function test_applicant_page_submits_a_complete_application(): void
    {
        $applicant = $this->applicant();
        [$term, $program] = $this->admissionPolicy();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)
            ->test(Application::class)
            ->fillForm([
                ...$this->completeIntakeData($term, $program, writeEvidence: false),
                'identity_evidence_reference' => UploadedFile::fake()->create('identity.pdf', 512, 'application/pdf'),
                'information_confirmed' => true,
            ])
            ->call('submitApplication')
            ->assertHasNoFormErrors()
            ->assertNotified('Application submitted for Registrar review')
            ->assertRedirect(Dashboard::getUrl());

        $intake = $applicant->applicantIntake()->firstOrFail();
        $this->assertSame(ApplicantIntake::StatusPending, $intake->status);
        Storage::disk('local')->assertExists($intake->identity_evidence_reference);
        $this->assertSame(1, $intake->checklistItems()->firstOrFail()->documentEvidence()->count());
    }

    public function test_applicant_can_submit_a_complete_draft_and_policy_checklist_is_created(): void
    {
        $applicant = $this->applicant();
        [$term, $program] = $this->admissionPolicy();
        $service = app(ApplicantIntakeService::class);
        $submitted = $service->submit($service->saveDraft($applicant, $this->completeIntakeData($term, $program)));

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
        $draft = app(ApplicantIntakeService::class)->saveDraft($applicant, $this->completeIntakeData($term, $program));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A matching applicant or student record already exists.');
        app(ApplicantIntakeService::class)->submit($draft);
    }

    public function test_submission_fails_when_no_active_admission_policy_matches(): void
    {
        $applicant = $this->applicant();
        [$term, $program] = $this->scope();
        $draft = app(ApplicantIntakeService::class)->saveDraft($applicant, $this->completeIntakeData($term, $program));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No effective admission requirement policy matches this intake.');
        app(ApplicantIntakeService::class)->submit($draft);
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
        return [
            Term::factory()->create(['state' => Term::StateActive]),
            Program::factory()->create(['is_active' => true]),
        ];
    }

    /** @return array{Term, Program} */
    private function admissionPolicy(): array
    {
        [$term, $program] = $this->scope();
        AdmissionRequirementPolicy::factory()->create([
            'requirement_type' => 'IDENTITY_DOCUMENT',
            'evidence_method' => 'DIGITAL_UPLOAD',
            'blocking_level' => ChecklistItem::BlockingHandover,
        ]);

        return [$term, $program];
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
    private function completeIntakeData(Term $term, Program $program, bool $writeEvidence = true): array
    {
        $path = 'applicant-identity-documents/identity.pdf';

        if ($writeEvidence) {
            Storage::disk('local')->put($path, 'identity evidence');
        }

        return [
            ...$this->draftData($term, $program),
            'birth_date' => '2005-05-10',
            'phone' => '09123456789',
            'prior_school' => 'Sample Senior High School',
            'identity_evidence_reference' => $path,
        ];
    }
}
