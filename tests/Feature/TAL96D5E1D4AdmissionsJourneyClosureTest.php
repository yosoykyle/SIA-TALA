<?php

namespace Tests\Feature;

use App\Actions\Applicants\ApplicantEvidenceService;
use App\Actions\Applicants\ApplicantIntakeWorkflowPresenter;
use App\Filament\Applicant\Pages\Dashboard;
use App\Filament\Resources\ApplicantIntakes\Pages\ViewApplicantIntake;
use App\Filament\Resources\StudentProfiles\RelationManagers\ChecklistItemsRelationManager;
use App\Mail\ApplicantStatusChangedMail;
use App\Models\ApplicantIntake;
use App\Models\ChecklistItem;
use App\Models\DocumentEvidence;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL96D5E1D4AdmissionsJourneyClosureTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('test_tala_db', config('database.connections.mysql.database'));

        Role::findOrCreate('applicant', 'web');
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        Permission::findOrCreate('approve-documents', 'web');
    }

    public function test_presenter_reports_resolved_outstanding_and_handover_blocker_counts(): void
    {
        $intake = ApplicantIntake::factory()->create([
            'status' => ApplicantIntake::StatusPending,
        ]);
        ChecklistItem::factory()->create([
            'applicant_intake_id' => $intake->id,
            'blocking_level' => ChecklistItem::BlockingHandover,
            'status' => ChecklistItem::StatusAccepted,
            'verification_status' => ChecklistItem::VerificationVerified,
        ]);
        ChecklistItem::factory()->create([
            'applicant_intake_id' => $intake->id,
            'blocking_level' => ChecklistItem::BlockingHandover,
            'status' => ChecklistItem::StatusPending,
            'verification_status' => ChecklistItem::VerificationNotReviewed,
        ]);
        ChecklistItem::factory()->create([
            'applicant_intake_id' => $intake->id,
            'blocking_level' => ChecklistItem::BlockingRetentionOnly,
            'status' => ChecklistItem::StatusPending,
            'verification_status' => ChecklistItem::VerificationNotReviewed,
        ]);

        $summary = app(ApplicantIntakeWorkflowPresenter::class)->present($intake);

        $this->assertSame(1, $summary['resolved_requirement_count']);
        $this->assertSame(2, $summary['outstanding_requirement_count']);
        $this->assertSame(1, $summary['handover_blocker_count']);
        $this->assertSame(
            '1 of 3 requirements resolved; 2 outstanding; 1 blocks handover',
            $summary['requirements_summary'],
        );
    }

    public function test_registrar_can_record_reasoned_waiver_and_undertaking_outcomes_with_audit_evidence(): void
    {
        $applicant = $this->applicant();
        $registrar = $this->registrar();
        $intake = ApplicantIntake::factory()->create([
            'user_id' => $applicant->id,
            'status' => ApplicantIntake::StatusActionRequired,
        ]);
        $waivedItem = ChecklistItem::factory()->create([
            'applicant_intake_id' => $intake->id,
            'status' => ChecklistItem::StatusRejected,
            'verification_status' => ChecklistItem::VerificationRejected,
        ]);
        $undertakingItem = ChecklistItem::factory()->create([
            'applicant_intake_id' => $intake->id,
            'status' => ChecklistItem::StatusPending,
            'verification_status' => ChecklistItem::VerificationNotReviewed,
        ]);
        $service = app(ApplicantEvidenceService::class);

        $service->waive(
            $waivedItem,
            $registrar,
            'Registrar approved a policy exception after checking the original record.',
        );
        $service->approveUndertaking(
            $undertakingItem,
            $registrar,
            'Submit the original Form 137 to the Registrar by the enrollment deadline.',
        );

        $this->assertSame(ChecklistItem::StatusWaived, $waivedItem->fresh()->status);
        $this->assertSame(
            ChecklistItem::VerificationRejected,
            $waivedItem->fresh()->verification_status,
            'Waiving the requirement must not erase the recorded evidence rejection.',
        );
        $this->assertSame(
            'Registrar approved a policy exception after checking the original record.',
            $waivedItem->fresh()->waiver_reason,
        );
        $this->assertSame(ChecklistItem::StatusUndertakingApproved, $undertakingItem->fresh()->status);
        $this->assertSame(
            ChecklistItem::VerificationNotReviewed,
            $undertakingItem->fresh()->verification_status,
        );
        $this->assertSame(
            'Submit the original Form 137 to the Registrar by the enrollment deadline.',
            $undertakingItem->fresh()->undertaking_terms,
        );
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => ChecklistItem::class,
            'subject_id' => $waivedItem->id,
            'event' => 'applicant_requirement_waived',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => ChecklistItem::class,
            'subject_id' => $undertakingItem->id,
            'event' => 'applicant_undertaking_approved',
        ]);
    }

    public function test_authority_resolutions_require_detail_and_an_authorized_registrar_without_mutation(): void
    {
        $applicant = $this->applicant();
        $intake = ApplicantIntake::factory()->create([
            'user_id' => $applicant->id,
            'status' => ApplicantIntake::StatusPending,
        ]);
        $item = ChecklistItem::factory()->create([
            'applicant_intake_id' => $intake->id,
            'status' => ChecklistItem::StatusPending,
            'verification_status' => ChecklistItem::VerificationNotReviewed,
        ]);
        $service = app(ApplicantEvidenceService::class);

        try {
            $service->waive($item, $this->registrar(), ' ');
            $this->fail('A waiver without a recorded reason must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Explain why the Registrar is waiving this requirement.',
                $exception->validator->errors()->first('waiver_reason'),
            );
        }

        try {
            $service->approveUndertaking($item, $applicant, 'Submit the original before enrollment.');
            $this->fail('An Applicant must not approve an undertaking.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(
                'Only Registrar staff with document-approval permission may review private evidence.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(ChecklistItem::StatusPending, $item->fresh()->status);
        $this->assertNull($item->fresh()->reviewed_at);
        $this->assertDatabaseMissing('activity_log', [
            'subject_type' => ChecklistItem::class,
            'subject_id' => $item->id,
            'event' => 'applicant_requirement_waived',
        ]);
        $this->assertDatabaseMissing('activity_log', [
            'subject_type' => ChecklistItem::class,
            'subject_id' => $item->id,
            'event' => 'applicant_undertaking_approved',
        ]);
    }

    public function test_registrar_actions_use_the_evidence_method_and_expose_authority_resolutions(): void
    {
        $registrar = $this->registrar();
        $intake = ApplicantIntake::factory()->create();
        $digital = ChecklistItem::factory()->create([
            'applicant_intake_id' => $intake->id,
            'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
            'status' => ChecklistItem::StatusReceivedDigital,
        ]);
        DocumentEvidence::factory()->create([
            'checklist_item_id' => $digital->id,
            'status' => DocumentEvidence::StatusSubmitted,
        ]);
        $physical = ChecklistItem::factory()->create([
            'applicant_intake_id' => $intake->id,
            'evidence_method' => ChecklistItem::EvidenceMethodPhysicalCopy,
            'status' => ChecklistItem::StatusReceivedPhysical,
        ]);
        $metadata = ChecklistItem::factory()->create([
            'applicant_intake_id' => $intake->id,
            'evidence_method' => ChecklistItem::EvidenceMethodMetadataOnly,
            'status' => ChecklistItem::StatusPending,
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)
            ->test(ChecklistItemsRelationManager::class, [
                'ownerRecord' => $intake,
                'pageClass' => ViewApplicantIntake::class,
            ])
            ->assertTableActionHasLabel('verifyDocument', 'Verify Digital Evidence', $digital)
            ->assertTableActionHasLabel('verifyDocument', 'Verify Physical Requirement', $physical)
            ->assertTableActionHasLabel('verifyDocument', 'Verify Staff-Tracked Requirement', $metadata)
            ->assertTableActionHasLabel('rejectDocument', 'Reject Digital Evidence', $digital)
            ->assertTableActionHasLabel('rejectDocument', 'Reject Physical Requirement', $physical)
            ->assertTableActionHasLabel('rejectDocument', 'Reject Staff-Tracked Requirement', $metadata)
            ->assertTableActionExists('waiveRequirement')
            ->assertTableActionExists('approveUndertaking')
            ->callTableAction('waiveRequirement', $digital, [
                'waiver_reason' => 'Registrar approved the documented policy exception.',
            ])
            ->assertNotified('Requirement waived')
            ->callTableAction('approveUndertaking', $physical, [
                'undertaking_terms' => 'Submit the original physical record before enrollment closes.',
            ])
            ->assertNotified('Undertaking approved');

        $this->assertSame(ChecklistItem::StatusWaived, $digital->fresh()->status);
        $this->assertSame(ChecklistItem::StatusUndertakingApproved, $physical->fresh()->status);
    }

    public function test_applicant_home_is_compact_and_requirements_owns_per_item_detail(): void
    {
        $applicant = $this->applicant();
        $intake = ApplicantIntake::factory()->create([
            'user_id' => $applicant->id,
            'status' => ApplicantIntake::StatusPending,
        ]);
        ChecklistItem::factory()->create([
            'applicant_intake_id' => $intake->id,
            'requirement_type' => 'IDENTITY_DOCUMENT',
            'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
            'blocking_level' => ChecklistItem::BlockingHandover,
            'status' => ChecklistItem::StatusAccepted,
            'verification_status' => ChecklistItem::VerificationVerified,
        ]);
        ChecklistItem::factory()->create([
            'applicant_intake_id' => $intake->id,
            'requirement_type' => 'FORM_137',
            'evidence_method' => ChecklistItem::EvidenceMethodPhysicalCopy,
            'blocking_level' => ChecklistItem::BlockingEnrollment,
            'status' => ChecklistItem::StatusPending,
            'verification_status' => ChecklistItem::VerificationNotReviewed,
        ]);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)
            ->test(Dashboard::class)
            ->assertOk()
            ->assertSee('1 of 2 requirements resolved')
            ->assertSee('Review Requirements')
            ->assertDontSee('Identity Document')
            ->assertDontSee('Submitted Digital Documents');

        $this->actingAs($applicant)
            ->get('/applicant/requirements')
            ->assertOk()
            ->assertSee('Identity Document')
            ->assertSee('Form 137')
            ->assertSee('Bring to the Registrar')
            ->assertSee('Latest evidence')
            ->assertSee('Registrar instruction');
    }

    public function test_applicant_status_mail_renders_scope_owner_and_safe_next_action(): void
    {
        $mail = new ApplicantStatusChangedMail(
            operationalEventId: 99,
            applicantIntakeId: 42,
            recipientName: 'Maria Applicant',
            status: ApplicantIntake::StatusActionRequired,
            statusLabel: 'Action Required',
            guidance: 'A requirement needs correction.',
            actionUrl: 'https://tala.test/applicant/requirements',
            operationalEventType: 'applicant_action_required_email',
            programLabel: 'Bachelor of Science in Information Systems',
            termLabel: 'AY 2026-2027, First Semester',
            responsibleOffice: 'Registrar',
            nextAction: 'Review the Registrar instruction and replace the rejected digital evidence.',
        );

        $rendered = $mail->render();

        $this->assertStringContainsString('Application reference: #42', $rendered);
        $this->assertStringContainsString('Bachelor of Science in Information Systems', $rendered);
        $this->assertStringContainsString('AY 2026-2027, First Semester', $rendered);
        $this->assertStringContainsString('Responsible office: Registrar', $rendered);
        $this->assertStringContainsString(
            'Review the Registrar instruction and replace the rejected digital evidence.',
            $rendered,
        );
    }

    private function applicant(): User
    {
        $applicant = User::factory()->create([
            'status' => User::StatusApplicantPending,
            'email_verified_at' => now(),
        ]);
        $applicant->assignRole('applicant');

        return $applicant;
    }

    private function registrar(): User
    {
        $registrar = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $registrar->givePermissionTo('approve-documents');

        return $registrar;
    }
}
