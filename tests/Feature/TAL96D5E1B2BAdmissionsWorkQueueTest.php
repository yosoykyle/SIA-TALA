<?php

namespace Tests\Feature;

use App\Actions\Applicants\ApplicantDuplicateCandidateFinder;
use App\Actions\Applicants\ApplicantEvidenceService;
use App\Actions\Applicants\ApplicantIntakeWorkflowPresenter;
use App\Actions\Applicants\HandOverApprovedApplicant;
use App\Filament\Resources\AdmissionRequirementPolicies\AdmissionRequirementPolicyResource;
use App\Filament\Resources\ApplicantIntakes\ApplicantIntakeResource;
use App\Filament\Resources\ApplicantIntakes\Pages\ListApplicantIntakes;
use App\Filament\Resources\ApplicantIntakes\Pages\ViewApplicantIntake;
use App\Filament\Resources\DuplicateProfileResolutionResource;
use App\Filament\Resources\StudentProfiles\RelationManagers\ChecklistItemsRelationManager;
use App\Models\ApplicantIntake;
use App\Models\ChecklistItem;
use App\Models\CurriculumVersion;
use App\Models\DocumentEvidence;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL96D5E1B2BAdmissionsWorkQueueTest extends TestCase
{
    use DatabaseTransactions;

    public function test_workflow_presenter_explains_stage_owner_next_action_and_handover_blockers(): void
    {
        $intake = ApplicantIntake::factory()->create([
            'status' => ApplicantIntake::StatusPending,
        ]);

        ChecklistItem::factory()->create([
            'applicant_intake_id' => $intake->id,
            'blocking_level' => ChecklistItem::BlockingHandover,
            'status' => ChecklistItem::StatusPending,
            'verification_status' => ChecklistItem::VerificationNotReviewed,
        ]);
        ChecklistItem::factory()->create([
            'applicant_intake_id' => $intake->id,
            'blocking_level' => ChecklistItem::BlockingHandover,
            'status' => ChecklistItem::StatusAccepted,
            'verification_status' => ChecklistItem::VerificationVerified,
        ]);

        $summary = app(ApplicantIntakeWorkflowPresenter::class)->present($intake);

        $this->assertSame('Evidence Review', $summary['stage']);
        $this->assertSame('Registrar', $summary['responsible_party']);
        $this->assertSame('Review submitted requirements', $summary['next_action']);
        $this->assertSame(1, $summary['handover_blocker_count']);
        $this->assertSame('1 of 2 requirements still blocks handover', $summary['requirements_summary']);
        $this->assertFalse($summary['ready_for_handover']);
    }

    public function test_workflow_presenter_distinguishes_applicant_action_and_completed_history(): void
    {
        $actionRequired = ApplicantIntake::factory()->create([
            'status' => ApplicantIntake::StatusActionRequired,
        ]);
        $handedOver = ApplicantIntake::factory()->approved()->create([
            'handed_over_at' => now(),
        ]);

        $actionSummary = app(ApplicantIntakeWorkflowPresenter::class)->present($actionRequired);
        $completedSummary = app(ApplicantIntakeWorkflowPresenter::class)->present($handedOver);

        $this->assertSame('Applicant Action Required', $actionSummary['stage']);
        $this->assertSame('Applicant', $actionSummary['responsible_party']);
        $this->assertSame('Wait for the applicant to replace or complete requirements', $actionSummary['next_action']);

        $this->assertSame('Student Record Created', $completedSummary['stage']);
        $this->assertSame('Enrollment Team / Student', $completedSummary['responsible_party']);
        $this->assertSame('Continue enrollment in Student Hub', $completedSummary['next_action']);
    }

    public function test_candidate_finder_surfaces_an_exact_active_official_record_for_every_admission_category(): void
    {
        $intake = ApplicantIntake::factory()->create([
            'admission_category' => ApplicantIntake::AdmissionCategoryTransfer,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birth_date' => '2004-04-03',
        ]);
        $candidate = StudentProfile::factory()->create([
            'first_name' => ' maria ',
            'last_name' => 'SANTOS ',
            'birth_date' => '2004-04-03',
        ]);
        StudentProfile::factory()->create([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birth_date' => '2004-04-03',
            'archived_at' => now(),
        ]);

        $matches = app(ApplicantDuplicateCandidateFinder::class)->find($intake);

        $this->assertCount(1, $matches);
        $this->assertTrue($matches->first()->is($candidate));
    }

    public function test_candidate_finder_excludes_the_official_profile_already_linked_to_the_same_intake(): void
    {
        $registrar = $this->registrar();
        $intake = ApplicantIntake::factory()->approved($registrar)->create([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birth_date' => '2004-04-03',
            'handed_over_at' => now(),
        ]);
        StudentProfile::factory()->create([
            'applicant_intake_id' => $intake->id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birth_date' => '2004-04-03',
        ]);

        $matches = app(ApplicantDuplicateCandidateFinder::class)->find($intake);

        $this->assertCount(0, $matches);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($registrar)
            ->get(ApplicantIntakeResource::getUrl('view', ['record' => $intake]))
            ->assertOk()
            ->assertSee('No exact active student record found')
            ->assertDontSee('Possible existing student record');
    }

    public function test_non_returning_handover_stops_when_an_exact_official_record_requires_investigation(): void
    {
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $registrar = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        $intake = ApplicantIntake::factory()->approved($registrar)->create([
            'admission_category' => ApplicantIntake::AdmissionCategoryFirstTimeCollege,
            'first_name' => 'Jose',
            'last_name' => 'Rizal',
            'birth_date' => '2003-06-19',
        ]);
        StudentProfile::factory()->create([
            'first_name' => 'Jose',
            'last_name' => 'Rizal',
            'birth_date' => '2003-06-19',
        ]);

        try {
            app(HandOverApprovedApplicant::class)->execute($intake, $registrar);
            $this->fail('The handover should stop when an exact official identity already exists.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'An existing official student record matches this applicant. Investigate the match before handover; a new profile was not created.',
                $exception->validator->errors()->first('student_profile'),
            );
        }

        $this->assertDatabaseMissing('student_profiles', [
            'applicant_intake_id' => $intake->id,
        ]);
    }

    public function test_first_time_handover_still_creates_one_profile_when_no_exact_candidate_exists(): void
    {
        [$registrar, $applicant] = $this->handoverUsers();
        $program = Program::factory()->create();
        $term = Term::factory()->create(['state' => Term::StateActive]);
        CurriculumVersion::factory()->create([
            'program_id' => $program->id,
            'state' => CurriculumVersion::StateActive,
        ]);
        $intake = ApplicantIntake::factory()->approved($registrar)->create([
            'user_id' => $applicant->id,
            'program_id' => $program->id,
            'term_id' => $term->id,
            'admission_category' => ApplicantIntake::AdmissionCategoryFirstTimeCollege,
            'first_name' => 'Unique',
            'last_name' => 'Applicant',
            'birth_date' => '2005-01-02',
        ]);
        $profileCountBefore = StudentProfile::query()->count();

        $studentProfile = app(HandOverApprovedApplicant::class)->execute($intake, $registrar);

        $this->assertSame($intake->id, $studentProfile->applicant_intake_id);
        $this->assertNotNull($intake->fresh()->handed_over_at);
        $this->assertDatabaseCount('student_profiles', $profileCountBefore + 1);
    }

    public function test_returning_handover_still_reuses_the_explicitly_confirmed_exact_profile(): void
    {
        [$registrar, $applicant] = $this->handoverUsers();
        $program = Program::factory()->create();
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $curriculum = CurriculumVersion::factory()->create([
            'program_id' => $program->id,
            'state' => CurriculumVersion::StateActive,
        ]);
        $intake = ApplicantIntake::factory()->approved($registrar)->create([
            'user_id' => $applicant->id,
            'program_id' => $program->id,
            'term_id' => $term->id,
            'admission_category' => ApplicantIntake::AdmissionCategoryReturning,
            'first_name' => 'Returning',
            'last_name' => 'Learner',
            'birth_date' => '2001-02-03',
        ]);
        $existingProfile = StudentProfile::factory()->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $curriculum->id,
            'first_name' => 'Returning',
            'last_name' => 'Learner',
            'birth_date' => '2001-02-03',
        ]);

        $result = app(HandOverApprovedApplicant::class)->execute($intake, $registrar, $existingProfile);

        $this->assertTrue($result->is($existingProfile));
        $this->assertSame($intake->id, $result->applicant_intake_id);
        $this->assertNotNull($intake->fresh()->handed_over_at);
    }

    public function test_configuration_and_duplicate_tools_remain_authorized_routes_but_not_primary_navigation(): void
    {
        $this->assertFalse(AdmissionRequirementPolicyResource::shouldRegisterNavigation());
        $this->assertFalse(DuplicateProfileResolutionResource::shouldRegisterNavigation());
        $this->assertArrayHasKey('index', AdmissionRequirementPolicyResource::getPages());
        $this->assertArrayHasKey('index', DuplicateProfileResolutionResource::getPages());
    }

    public function test_registrar_work_queue_uses_task_centered_tabs_columns_and_filters(): void
    {
        $registrar = $this->registrar();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)
            ->test(ListApplicantIntakes::class)
            ->assertSee('Needs Registrar Action')
            ->assertSee('Waiting on Applicant')
            ->assertSee('Approved / Handover Review')
            ->assertSee('Completed / History')
            ->assertTableColumnExists('workflow_stage')
            ->assertTableColumnExists('next_action')
            ->assertTableColumnExists('requirements_summary')
            ->assertTableColumnExists('last_activity_at')
            ->assertTableFilterExists('term')
            ->assertTableFilterExists('program')
            ->assertTableFilterExists('status')
            ->assertTableFilterExists('has_handover_blocker');

        $this->assertSame('Admissions Work Queue', ApplicantIntakeResource::getNavigationLabel());
    }

    public function test_work_queue_tabs_separate_registrar_applicant_handover_and_history_states(): void
    {
        $registrar = $this->registrar();
        $pending = ApplicantIntake::factory()->create(['status' => ApplicantIntake::StatusPending]);
        $actionRequired = ApplicantIntake::factory()->create(['status' => ApplicantIntake::StatusActionRequired]);
        $approved = ApplicantIntake::factory()->approved()->create();
        $handedOver = ApplicantIntake::factory()->approved()->create(['handed_over_at' => now()]);
        $withdrawn = ApplicantIntake::factory()->create([
            'status' => ApplicantIntake::StatusWithdrawn,
            'archived_at' => now(),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)
            ->test(ListApplicantIntakes::class)
            ->set('activeTab', 'needs_registrar_action')
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$actionRequired, $approved, $handedOver, $withdrawn])
            ->set('activeTab', 'waiting_on_applicant')
            ->assertCanSeeTableRecords([$actionRequired])
            ->assertCanNotSeeTableRecords([$pending, $approved, $handedOver, $withdrawn])
            ->set('activeTab', 'ready_for_handover')
            ->assertCanSeeTableRecords([$approved])
            ->assertCanNotSeeTableRecords([$pending, $actionRequired, $handedOver, $withdrawn])
            ->set('activeTab', 'completed_history')
            ->assertCanSeeTableRecords([$handedOver, $withdrawn])
            ->assertCanNotSeeTableRecords([$pending, $actionRequired, $approved]);
    }

    public function test_applicant_record_leads_with_workflow_readiness_and_identity_match_before_details(): void
    {
        $registrar = $this->registrar();
        $intake = ApplicantIntake::factory()->approved($registrar)->create([
            'first_name' => 'Ana',
            'last_name' => 'Dela Cruz',
            'birth_date' => '2004-09-08',
        ]);
        $candidate = StudentProfile::factory()->create([
            'first_name' => 'Ana',
            'last_name' => 'Dela Cruz',
            'birth_date' => '2004-09-08',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($registrar)
            ->get(ApplicantIntakeResource::getUrl('view', ['record' => $intake]))
            ->assertOk()
            ->assertSeeInOrder([
                'Current Workflow',
                'Identity Match Review',
                'Responsible Party',
                'Next Action',
                'Requirement Readiness',
                'Identity Match Check',
                'Possible existing student record',
                $candidate->student_number,
                'Application Scope',
                'Personal Information',
                'Application History',
                'Technical References',
            ]);

        Livewire::actingAs($registrar)
            ->test(ViewApplicantIntake::class, ['record' => $intake->getRouteKey()])
            ->assertActionHidden('handOverToStudent');
    }

    public function test_requirement_workspace_groups_review_actions_and_exposes_business_filters(): void
    {
        $registrar = $this->registrar();
        $intake = ApplicantIntake::factory()->create();
        $item = ChecklistItem::factory()->create([
            'applicant_intake_id' => $intake->id,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)
            ->test(ChecklistItemsRelationManager::class, [
                'ownerRecord' => $intake,
                'pageClass' => ViewApplicantIntake::class,
            ])
            ->assertCanSeeTableRecords([$item])
            ->assertTableFilterExists('status')
            ->assertTableFilterExists('evidence_method')
            ->assertTableFilterExists('blocking_level')
            ->assertTableFilterExists('verification_status')
            ->assertTableActionExists('recordPhysicalReceipt')
            ->assertTableActionExists('verifyDocument')
            ->assertTableActionExists('downloadEvidence')
            ->assertTableActionExists('rejectDocument');
    }

    public function test_authoritative_checklist_download_is_audited_and_replaces_the_identity_only_header_action(): void
    {
        Storage::fake('local');

        $registrar = $this->registrar();
        $intake = ApplicantIntake::factory()->create();
        $item = ChecklistItem::factory()->create([
            'owner_type' => ChecklistItem::OwnerApplicant,
            'applicant_intake_id' => $intake->id,
            'student_profile_id' => null,
            'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
        ]);
        $path = "applicant-evidence/{$item->id}.pdf";
        Storage::disk('local')->put($path, "%PDF-1.4\nadmission evidence\n%%EOF");
        DocumentEvidence::factory()->create([
            'checklist_item_id' => $item->id,
            'disk' => 'local',
            'path' => $path,
            'uploaded_by' => $intake->user_id,
        ]);

        app(ApplicantEvidenceService::class)->downloadChecklistEvidence($item, $registrar);

        $this->assertDatabaseHas('output_access_logs', [
            'output_type' => 'ADMISSION_EVIDENCE',
            'source_record_type' => ChecklistItem::class,
            'source_record_id' => $item->id,
            'actor_user_id' => $registrar->id,
            'action' => 'DOWNLOAD',
            'status' => 'SUCCESS',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)
            ->test(ViewApplicantIntake::class, ['record' => $intake->id])
            ->assertActionDoesNotExist('downloadIdentityDocument');
    }

    private function registrar(): User
    {
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        Permission::findOrCreate('approve-documents', 'web');

        $registrar = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $registrar->givePermissionTo('approve-documents');

        return $registrar;
    }

    /** @return array{User, User} */
    private function handoverUsers(): array
    {
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        Role::findOrCreate('applicant', 'web');
        Role::findOrCreate('student', 'web');
        Permission::findOrCreate('approve-documents', 'web');

        $registrar = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $registrar->givePermissionTo('approve-documents');

        $applicant = User::factory()->create([
            'status' => User::StatusApplicantApproved,
            'email_verified_at' => now(),
        ]);
        $applicant->assignRole('applicant');

        return [$registrar, $applicant];
    }
}
