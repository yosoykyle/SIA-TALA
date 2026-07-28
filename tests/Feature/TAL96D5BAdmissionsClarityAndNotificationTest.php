<?php

namespace Tests\Feature;

use App\Actions\Applicants\ApplicantEvidenceService;
use App\Actions\Applicants\ApplicantReviewService;
use App\Actions\Applicants\ApplicantStatusNotificationService;
use App\Filament\Applicant\Pages\Application;
use App\Filament\Resources\ApplicantIntakes\Pages\ViewApplicantIntake;
use App\Filament\Resources\StudentProfiles\RelationManagers\ChecklistItemsRelationManager;
use App\Mail\ApplicantStatusChangedMail;
use App\Models\AdmissionRequirementPolicy;
use App\Models\ApplicantIntake;
use App\Models\CalendarEvent;
use App\Models\ChecklistItem;
use App\Models\DocumentEvidence;
use App\Models\OperationalEvent;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL96D5BAdmissionsClarityAndNotificationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('test_tala_db', config('database.connections.mysql.database'));

        Role::findOrCreate('applicant', 'web');
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        Permission::findOrCreate('approve-documents', 'web');
        AdmissionRequirementPolicy::query()->update([
            'state' => AdmissionRequirementPolicy::StateSuperseded,
        ]);
        Storage::fake('local');
    }

    public function test_personal_information_step_blocks_incomplete_data_while_save_draft_remains_partial(): void
    {
        $applicant = $this->applicant();
        [$term, $program] = $this->openScope();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)
            ->test(Application::class)
            ->assertFormFieldDoesNotExist('modality_preference', 'form')
            ->set('data.term_id', $term->id)
            ->set('data.program_id', $program->id)
            ->set('data.admission_category', ApplicantIntake::AdmissionCategoryFirstTimeCollege)
            ->set('data.credential_basis', ApplicantIntake::CredentialBasisSeniorHighSchool)
            ->set('data.first_name', null)
            ->set('data.last_name', null)
            ->goToNextWizardStep('form')
            ->assertHasFormErrors([
                'first_name',
                'last_name',
                'gender',
                'civil_status',
                'birth_date',
                'birth_place',
                'phone',
                'address_street',
                'address_barangay',
                'address_city',
                'address_province',
                'guardian_name',
                'guardian_phone',
                'guardian_address',
                'prior_school',
            ], 'form');

        Livewire::actingAs($applicant)
            ->test(Application::class)
            ->set('data.term_id', $term->id)
            ->set('data.program_id', $program->id)
            ->set('data.admission_category', ApplicantIntake::AdmissionCategoryFirstTimeCollege)
            ->set('data.credential_basis', ApplicantIntake::CredentialBasisSeniorHighSchool)
            ->set('data.first_name', null)
            ->set('data.last_name', null)
            ->call('saveDraft')
            ->assertHasNoErrors()
            ->assertNotified('Application draft saved');

        $this->assertDatabaseHas('applicant_intakes', [
            'user_id' => $applicant->id,
            'term_id' => $term->id,
            'program_id' => $program->id,
            'status' => ApplicantIntake::StatusDraft,
            'first_name' => $applicant->first_name,
            'last_name' => $applicant->last_name,
            'phone' => null,
        ]);
    }

    public function test_invalid_draft_value_returns_field_error_and_persistent_failure_notification(): void
    {
        $applicant = $this->applicant();
        [$term, $program] = $this->openScope();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)
            ->test(Application::class)
            ->set('data.term_id', $term->id)
            ->set('data.program_id', $program->id)
            ->set('data.admission_category', ApplicantIntake::AdmissionCategoryFirstTimeCollege)
            ->set('data.credential_basis', ApplicantIntake::CredentialBasisSeniorHighSchool)
            ->set('data.phone', '123')
            ->call('saveDraft')
            ->assertHasErrors(['data.phone'])
            ->assertNotified('Application draft was not saved');

        $this->assertDatabaseMissing('applicant_intakes', [
            'user_id' => $applicant->id,
            'term_id' => $term->id,
        ]);
    }

    public function test_registrar_checklist_uses_human_labels_and_exposes_evidence_method(): void
    {
        $applicant = $this->applicant();
        $registrar = $this->registrar();
        $intake = ApplicantIntake::factory()->create([
            'user_id' => $applicant->id,
            'status' => ApplicantIntake::StatusPending,
        ]);
        ChecklistItem::factory()->create([
            'applicant_intake_id' => $intake->id,
            'requirement_type' => 'FORM_137',
            'evidence_method' => ChecklistItem::EvidenceMethodPhysicalCopy,
            'blocking_level' => ChecklistItem::BlockingEnrollment,
            'status' => ChecklistItem::StatusPending,
            'verification_status' => ChecklistItem::VerificationNotReviewed,
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)
            ->test(ChecklistItemsRelationManager::class, [
                'ownerRecord' => $intake,
                'pageClass' => ViewApplicantIntake::class,
            ])
            ->assertSee('Form 137')
            ->assertSee('Physical Copy')
            ->assertSee('Blocks Enrollment')
            ->assertSee('Pending')
            ->assertSee('Not Reviewed');
    }

    public function test_applicant_requirements_explain_physical_delivery_without_showing_an_upload(): void
    {
        $applicant = $this->applicant();
        $intake = ApplicantIntake::factory()->create([
            'user_id' => $applicant->id,
            'status' => ApplicantIntake::StatusPending,
        ]);
        ChecklistItem::factory()->create([
            'applicant_intake_id' => $intake->id,
            'requirement_type' => 'FORM_137',
            'evidence_method' => ChecklistItem::EvidenceMethodPhysicalCopy,
            'blocking_level' => ChecklistItem::BlockingEnrollment,
            'status' => ChecklistItem::StatusPending,
            'verification_status' => ChecklistItem::VerificationNotReviewed,
        ]);

        $this->actingAs($applicant)
            ->get('/applicant/requirements')
            ->assertOk()
            ->assertSee('Form 137')
            ->assertSee('Bring to the Registrar')
            ->assertSee('Blocks Enrollment')
            ->assertDontSee('Upload Form 137');
    }

    public function test_rejected_evidence_queues_one_action_required_email_with_operational_evidence(): void
    {
        Mail::fake();

        $applicant = $this->applicant();
        $registrar = $this->registrar();
        $intake = ApplicantIntake::factory()->create([
            'user_id' => $applicant->id,
            'status' => ApplicantIntake::StatusPending,
        ]);
        $item = ChecklistItem::factory()->create([
            'applicant_intake_id' => $intake->id,
            'requirement_type' => 'IDENTITY_DOCUMENT',
            'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
            'blocking_level' => ChecklistItem::BlockingHandover,
            'status' => ChecklistItem::StatusReceivedDigital,
            'verification_status' => ChecklistItem::VerificationNotReviewed,
        ]);
        DocumentEvidence::factory()->create([
            'checklist_item_id' => $item->id,
            'uploaded_by' => $applicant->id,
            'status' => DocumentEvidence::StatusSubmitted,
        ]);

        app(ApplicantEvidenceService::class)->review(
            $item,
            $registrar,
            ApplicantEvidenceService::DecisionReject,
            'Upload a clearer identity document.',
        );
        app(ApplicantStatusNotificationService::class)->record(
            ApplicantIntake::query()->findOrFail($intake->id),
        );

        Mail::assertQueued(
            ApplicantStatusChangedMail::class,
            fn (ApplicantStatusChangedMail $mail): bool => $mail->hasTo($applicant->email)
                && $mail->status === ApplicantIntake::StatusActionRequired
                && $mail->applicantIntakeId === $intake->id,
        );
        Mail::assertQueued(ApplicantStatusChangedMail::class, 1);
        $this->assertDatabaseHas('operational_events', [
            'event_domain' => OperationalEvent::DomainNotifications,
            'event_type' => OperationalEvent::TypeApplicantActionRequiredEmail,
            'user_id' => $applicant->id,
            'related_record_type' => ApplicantIntake::class,
            'related_record_id' => $intake->id,
        ]);
        $this->assertSame(
            1,
            OperationalEvent::query()
                ->where('event_type', OperationalEvent::TypeApplicantActionRequiredEmail)
                ->where('related_record_id', $intake->id)
                ->count(),
        );
    }

    public function test_approval_queues_one_approved_for_handover_email_with_operational_evidence(): void
    {
        Mail::fake();

        $applicant = $this->applicant();
        $registrar = $this->registrar();
        $intake = ApplicantIntake::factory()->create([
            'user_id' => $applicant->id,
            'status' => ApplicantIntake::StatusForEvaluation,
        ]);
        ChecklistItem::factory()->create([
            'applicant_intake_id' => $intake->id,
            'requirement_type' => 'IDENTITY_DOCUMENT',
            'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
            'blocking_level' => ChecklistItem::BlockingHandover,
            'status' => ChecklistItem::StatusAccepted,
            'verification_status' => ChecklistItem::VerificationVerified,
            'reviewed_by' => $registrar->id,
            'reviewed_at' => now(),
        ]);

        $approved = app(ApplicantReviewService::class)->approve($intake, $registrar);
        app(ApplicantStatusNotificationService::class)->record($approved);

        Mail::assertQueued(
            ApplicantStatusChangedMail::class,
            fn (ApplicantStatusChangedMail $mail): bool => $mail->hasTo($applicant->email)
                && $mail->status === ApplicantIntake::StatusApproved
                && $mail->applicantIntakeId === $intake->id,
        );
        Mail::assertQueued(ApplicantStatusChangedMail::class, 1);
        $this->assertDatabaseHas('operational_events', [
            'event_domain' => OperationalEvent::DomainNotifications,
            'event_type' => OperationalEvent::TypeApplicantApprovedEmail,
            'user_id' => $applicant->id,
            'related_record_type' => ApplicantIntake::class,
            'related_record_id' => $intake->id,
        ]);
        $this->assertSame(
            1,
            OperationalEvent::query()
                ->where('event_type', OperationalEvent::TypeApplicantApprovedEmail)
                ->where('related_record_id', $intake->id)
                ->count(),
        );
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

    /** @return array{Term, Program} */
    private function openScope(): array
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
}
