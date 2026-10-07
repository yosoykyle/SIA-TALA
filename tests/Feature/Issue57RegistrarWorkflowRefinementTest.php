<?php

namespace Tests\Feature;

use App\Actions\Admissions\SaveAdmissionApplication;
use App\Actions\Authentication\WorkspaceContextResolver;
use App\Filament\Applicant\Pages\Application as ApplicantApplication;
use App\Filament\Pages\AssistedAdmissionApplication;
use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications;
use App\Filament\Resources\AdmissionCycles\Pages\ViewAdmissionCycle;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\ApplicantIntake;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Components\Wizard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Issue57RegistrarWorkflowRefinementTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('applicant', 'web');
        $regRole = Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $regRole->givePermissionTo(Permission::findOrCreate('approve-documents', 'web'));
        Role::findOrCreate(User::StaffRoleAcademicHead, 'web');
        Role::findOrCreate(User::StaffRoleAccounting, 'web');
        Role::findOrCreate(User::StaffRoleFaculty, 'web');
    }

    public function test_assisted_save_succeeds_with_only_reason_leaving_references_optional(): void
    {
        $registrar = $this->createStaffUser(User::StaffRoleRegistrar);
        [$application, $applicant, $cycle, $program] = $this->createDraftApplication();

        $action = app(SaveAdmissionApplication::class);

        $saved = $action->execute(
            applicant: $applicant,
            cycle: $cycle,
            data: [
                'term_id' => $application->term_id,
                'program_id' => $program->id,
                'application_path' => AdmissionApplication::PathFirstYear,
                'admission_category' => ApplicantIntake::AdmissionCategoryFirstTimeCollege,
                'credential_basis' => ApplicantIntake::CredentialBasisSeniorHighSchool,
                'first_name' => 'AssistedFirst',
                'last_name' => 'AssistedLast',
                'birth_date' => '2005-01-01',
                'citizenship_country_code' => 'PH',
                'email' => $applicant->email,
                'phone' => '09123456789',
                'current_city_municipality' => 'Synthetic City',
                'current_province' => 'Laguna',
                'prior_school_name' => 'High School',
                'prior_school_country_code' => 'PH',
                'prior_school_completion_year' => 2023,
                'privacy_notice_reference' => 'privacy-notice:synthetic-v1',
            ],
            application: $application,
            assistedBy: $registrar,
            assistanceReason: 'In-person applicant assistance at counter',
            assistanceAuthorityReference: null,
            assistanceEvidenceReference: null,
        );

        $this->assertSame('AssistedFirst', $saved->first_name);
        $activity = Activity::forSubject($saved)
            ->where('event', 'admission_assisted_draft_saved')
            ->latest('id')
            ->first();
        $this->assertNotNull($activity);
        $this->assertSame('In-person applicant assistance at counter', $activity->getExtraProperty('reason'));
        $this->assertNull($activity->getExtraProperty('authority_reference'));
        $this->assertNull($activity->getExtraProperty('evidence_reference'));
    }

    public function test_assisted_save_rejects_non_draft_applications(): void
    {
        $registrar = $this->createStaffUser(User::StaffRoleRegistrar);
        $term = Term::query()->first() ?? Term::factory()->create();
        $cycle = AdmissionCycle::factory()->published()->create(['term_id' => $term->id]);

        $applicant = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $applicant->assignRole('applicant');

        $application = AdmissionApplication::factory()->submitted()->create([
            'user_id' => $applicant->id,
            'admission_cycle_id' => $cycle->id,
            'term_id' => $term->id,
        ]);

        $action = app(SaveAdmissionApplication::class);

        $this->expectException(ValidationException::class);

        $action->execute(
            applicant: $applicant,
            cycle: $cycle,
            data: [
                'first_name' => 'ChangedName',
            ],
            application: $application,
            assistedBy: $registrar,
            assistanceReason: 'Assistance attempt on submitted record',
        );
    }

    public function test_assisted_entry_redirects_an_exact_submitted_case_to_registrar_review(): void
    {
        $registrar = $this->createStaffUser(User::StaffRoleRegistrar);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $term = Term::query()->first() ?? Term::factory()->create();
        $cycle = AdmissionCycle::factory()->published()->create(['term_id' => $term->id]);

        $applicant = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $applicant->assignRole('applicant');

        $application = AdmissionApplication::factory()->submitted()->create([
            'user_id' => $applicant->id,
            'admission_cycle_id' => $cycle->id,
            'term_id' => $term->id,
        ]);

        Livewire::withQueryParams(['applicant' => $applicant->id, 'application' => $application->id])
            ->test(AssistedAdmissionApplication::class)
            ->assertRedirect(AdmissionApplicationResource::getUrl('view', ['record' => $application]));
    }

    public function test_assisted_admission_page_save_and_exit_redirects_to_admissions_queue(): void
    {
        $page = new AssistedAdmissionApplication;
        $refMethod = new \ReflectionMethod($page, 'afterSaveAndExitUrl');
        $refMethod->setAccessible(true);

        $url = $refMethod->invoke($page);
        $this->assertSame(AdmissionApplicationResource::getUrl('index'), $url);
    }

    public function test_staff_entry_redirects_registrar_to_admissions_queue(): void
    {
        $registrar = $this->createStaffUser(User::StaffRoleRegistrar);

        $response = $this->actingAs($registrar)->get('/admin');

        $response->assertRedirect(AdmissionApplicationResource::getUrl('index'));
    }

    public function test_staff_entry_redirects_registrar_with_application_param_to_record_view(): void
    {
        $registrar = $this->createStaffUser(User::StaffRoleRegistrar);
        [$application] = $this->createDraftApplication();

        $response = $this->actingAs($registrar)->get('/admin?application='.$application->id);

        $response->assertRedirect(AdmissionApplicationResource::getUrl('view', ['record' => $application->id]));
    }

    public function test_admission_cycle_view_uses_manila_timezone_and_display_format_for_date_pickers(): void
    {
        $page = new ViewAdmissionCycle;
        $refMethod = new \ReflectionMethod($page, 'dateChangeSchema');
        $refMethod->setAccessible(true);

        /** @var list<DateTimePicker> $schema */
        $schema = $refMethod->invoke($page);

        $this->assertNotEmpty($schema);
        $closesAtPicker = $schema[0];
        $this->assertInstanceOf(DateTimePicker::class, $closesAtPicker);
        $this->assertSame('Asia/Manila', $closesAtPicker->getTimezone());
        $this->assertSame('M j, Y · g:i A', $closesAtPicker->getDisplayFormat());
    }

    public function test_admission_applications_table_has_closed_applications_tab(): void
    {
        $page = new ListAdmissionApplications;
        $tabs = $page->getTabs();

        $this->assertArrayHasKey('history', $tabs);
        $this->assertSame('Closed applications', $tabs['history']->getLabel());
    }

    public function test_assisted_entry_mounts_existing_draft_with_prior_assistance_event(): void
    {
        $registrar = $this->createStaffUser(User::StaffRoleRegistrar);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        [$application, $applicant, $cycle] = $this->createDraftApplication();

        Activity::create([
            'log_name' => 'admission_application',
            'description' => 'Assisted draft saved by Staff',
            'subject_type' => AdmissionApplication::class,
            'subject_id' => $application->id,
            'causer_type' => User::class,
            'causer_id' => $registrar->id,
            'event' => 'admission_assisted_draft_saved',
            'properties' => [
                'assistance_reason' => 'Prior assistance reason text',
                'routine_reference_no' => 'REF-999',
                'applicant_facing_reference_no' => 'APP-999',
            ],
        ]);

        Livewire::withQueryParams(['applicant' => $applicant->id])
            ->test(AssistedAdmissionApplication::class)
            ->assertSet('data.assistance_reason', 'Prior assistance reason text')
            ->assertSet('data.assistance_authority_reference', 'REF-999')
            ->assertSet('data.assistance_evidence_reference', 'APP-999')
            ->assertOk();
    }

    public function test_assisted_entry_mounts_existing_draft_without_prior_assistance_event(): void
    {
        $registrar = $this->createStaffUser(User::StaffRoleRegistrar);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        [$application, $applicant] = $this->createDraftApplication();

        Livewire::withQueryParams(['applicant' => $applicant->id])
            ->test(AssistedAdmissionApplication::class)
            ->assertSet('data.assistance_reason', null)
            ->assertOk();
    }

    public function test_assisted_entry_mounts_action_needed_route_with_safe_ineligibility_view(): void
    {
        $registrar = $this->createStaffUser(User::StaffRoleRegistrar);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        [$application, $applicant] = $this->createDraftApplication();
        $application->update(['application_state' => AdmissionApplication::StateActionNeeded]);

        $component = Livewire::withQueryParams(['applicant' => $applicant->id])
            ->test(AssistedAdmissionApplication::class)
            ->assertSee('Assisted entry unavailable for this applicant')
            ->assertOk();
        $this->assertFalse($component->instance()->isWizardVisible());
    }

    public function test_assisted_entry_blank_or_whitespace_reason_rejects_save_with_field_error_and_no_writes(): void
    {
        $registrar = $this->createStaffUser(User::StaffRoleRegistrar);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        [$application, $applicant] = $this->createDraftApplication();
        $initialActivityCount = Activity::where('subject_type', AdmissionApplication::class)->count();

        Livewire::withQueryParams(['applicant' => $applicant->id])
            ->test(AssistedAdmissionApplication::class)
            ->set('data.assistance_reason', '   ')
            ->call('saveDraft')
            ->assertHasErrors(['data.assistance_reason'])
            ->call('saveAndExit')
            ->assertHasErrors(['data.assistance_reason']);

        $this->assertSame($initialActivityCount, Activity::where('subject_type', AdmissionApplication::class)->count());
    }

    public function test_assisted_entry_wizard_visibility_gated_by_valid_assistance_context(): void
    {
        $registrar = $this->createStaffUser(User::StaffRoleRegistrar);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        [$application, $applicant] = $this->createDraftApplication();

        $component = Livewire::withQueryParams(['applicant' => $applicant->id])
            ->test(AssistedAdmissionApplication::class);

        $this->assertFalse($component->instance()->isWizardVisible());

        $component->set('data.assistance_reason', 'Applicant requested telephone assistance');
        $this->assertTrue($component->instance()->isWizardVisible());
    }

    public function test_assisted_draft_eligibility_not_blocked_by_newer_unrelated_terminal_record(): void
    {
        $registrar = $this->createStaffUser(User::StaffRoleRegistrar);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        [$application, $applicant, $cycle] = $this->createDraftApplication();

        $term2 = Term::factory()->recycle($cycle->term->academicYear)->create(['label' => 'Unrelated terminal application term']);
        $cycle2 = AdmissionCycle::factory()->published()->create(['term_id' => $term2->id]);
        $terminalApp = AdmissionApplication::factory()->create([
            'user_id' => $applicant->id,
            'admission_cycle_id' => $cycle2->id,
            'term_id' => $term2->id,
            'application_state' => AdmissionApplication::StateWithdrawn,
            'created_at' => now()->addHour(),
        ]);

        Livewire::withQueryParams(['applicant' => $applicant->id, 'cycle' => $cycle->id])
            ->test(AssistedAdmissionApplication::class)
            ->assertDontSee('Assisted entry unavailable for this applicant')
            ->assertOk();
    }

    public function test_assisted_entry_valid_save_and_exit_persists_attribution_and_redirects(): void
    {
        $registrar = $this->createStaffUser(User::StaffRoleRegistrar);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        [$application, $applicant] = $this->createDraftApplication();
        $this->freezeTime();
        $previousActivityId = (int) (Activity::max('id') ?? 0);

        Livewire::withQueryParams(['applicant' => $applicant->id])
            ->test(AssistedAdmissionApplication::class)
            ->set('data.assistance_reason', 'Phone assisted intake')
            ->set('data.first_name', 'Updated assisted name')
            ->set('data.assistance_authority_reference', 'REF-2026-TEST')
            ->set('data.assistance_evidence_reference', 'EVIDENCE-2026-TEST')
            ->call('saveAndExit')
            ->assertRedirect(AdmissionApplicationResource::getUrl('index'));

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => AdmissionApplication::class,
            'subject_id' => $application->id,
            'event' => 'admission_assisted_draft_saved',
        ]);
        $this->assertSame('Updated assisted name', $application->fresh()->first_name);
        $this->assertSame($applicant->id, $application->fresh()->user_id);
        $activity = Activity::forSubject($application)->where('id', '>', $previousActivityId)
            ->where('event', 'admission_assisted_draft_saved')->sole();
        $this->assertSame($registrar->id, $activity->causer_id);
        $this->assertSame(now()->toDateTimeString(), $activity->created_at->toDateTimeString());
        $this->assertSame('Phone assisted intake', $activity->getExtraProperty('reason'));
        $this->assertSame('REF-2026-TEST', $activity->getExtraProperty('authority_reference'));
        $this->assertSame('EVIDENCE-2026-TEST', $activity->getExtraProperty('evidence_reference'));
    }

    public function test_staff_entry_selected_non_registrar_context_redirects_to_role_destination_ignoring_application_param(): void
    {
        $user = $this->createStaffUser(User::StaffRoleAccounting);
        $user->assignRole(User::StaffRoleRegistrar);
        [$application] = $this->createDraftApplication();

        $response = $this->actingAs($user)
            ->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleAccounting])
            ->get('/admin?application='.$application->id);
        $response->assertRedirect('/admin/fee-plans');
    }

    public function test_staff_entry_selected_faculty_context_redirects_to_faculty_destination_ignoring_application_param(): void
    {
        $user = $this->createStaffUser(User::StaffRoleFaculty);
        [$application] = $this->createDraftApplication();

        $response = $this->actingAs($user)
            ->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleFaculty])
            ->get('/admin?application='.$application->id);
        $response->assertRedirect('/admin/my-availability');
    }

    public function test_staff_entry_multi_role_user_without_selected_context_is_blocked_by_panel_guard_middleware(): void
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole(User::StaffRoleRegistrar);
        $user->assignRole(User::StaffRoleAcademicHead);
        $user->saveAppAuthenticationSecret('base32secret3232');
        $user->acknowledgeRecoveryCodeStorage();

        $response = $this->actingAs($user)
            ->withSession([WorkspaceContextResolver::SessionKey => null])
            ->get('/admin');
        $response->assertForbidden();
    }

    public function test_staff_entry_registrar_with_invalid_application_param_falls_back_to_admissions_queue(): void
    {
        $registrar = $this->createStaffUser(User::StaffRoleRegistrar);
        $response = $this->actingAs($registrar)->get('/admin?application=99999999');
        $response->assertRedirect(AdmissionApplicationResource::getUrl('index'));
    }

    public function test_staff_entry_noncanonical_application_deep_link_falls_back_to_queue(): void
    {
        $registrar = $this->createStaffUser(User::StaffRoleRegistrar);
        [$application] = $this->createDraftApplication();
        $application->update(['application_state' => null]);

        $this->actingAs($registrar)->get('/admin?application='.$application->id)
            ->assertRedirect(AdmissionApplicationResource::getUrl('index'));
    }

    public function test_application_record_deep_link_rejects_unauthorized_staff_through_middleware(): void
    {
        $accounting = $this->createStaffUser(User::StaffRoleAccounting);
        [$application] = $this->createDraftApplication();

        $this->actingAs($accounting)->get(AdmissionApplicationResource::getUrl('view', ['record' => $application->id]))
            ->assertForbidden();
    }

    public function test_application_persist_draft_pre_persistence_validation_does_not_claim_upload_failure(): void
    {
        $registrar = $this->createStaffUser(User::StaffRoleRegistrar);
        [$application, $applicant] = $this->createDraftApplication();
        $before = $application->fresh()->getRawOriginal();
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::withQueryParams(['applicant' => $applicant->id])
            ->test(AssistedAdmissionApplication::class)
            ->set('data.assistance_reason', 'Telephone assistance')
            ->set('data.first_name', 'Retained entered name')
            ->set('data.email', 'invalid-email')
            ->call('saveAndExit')
            ->assertHasErrors(['data.email'])
            ->assertSet('saveStatus', 'failed')
            ->assertSet('data.first_name', 'Retained entered name')
            ->assertSet('saveStatusMessage', 'Draft could not be saved. Your entered work remains here; correct the identified item before continuing.')
            ->assertNoRedirect();

        $this->assertSame($before, $application->fresh()->getRawOriginal());
    }

    public function test_assisted_evidence_failure_after_facts_keeps_input_and_does_not_exit(): void
    {
        $registrar = $this->createStaffUser(User::StaffRoleRegistrar);
        [$application, $applicant] = $this->createDraftApplication();
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::withQueryParams(['applicant' => $applicant->id])
            ->test(Issue57EvidenceFailurePage::class)
            ->set('data.assistance_reason', 'Telephone assistance')
            ->set('data.first_name', 'Facts saved before failure')
            ->call('saveAndExit')
            ->assertSet('saveStatus', 'failed')
            ->assertSet('data.first_name', 'Facts saved before failure')
            ->assertSet('data.assistance_reason', 'Telephone assistance')
            ->assertSet('saveStatusMessage', 'Save incomplete. Some answers may have saved before a copy failed. Your entered work remains here; correct the identified item before continuing.')
            ->assertNoRedirect();

        $this->assertSame('Facts saved before failure', $application->fresh()->first_name);
        $this->assertSame($applicant->id, $application->fresh()->user_id);
        $this->assertSame(0, $application->evidenceVersions()->count());
    }

    public function test_closed_and_cancelled_applicant_drafts_allow_read_only_later_section_inspection(): void
    {
        foreach (['closed', 'cancelled'] as $state) {
            [$application, $applicant, $cycle] = $this->createDraftApplication();
            $cycle->update($state === 'closed'
                ? ['closes_at' => now()->subMinute()]
                : ['state' => AdmissionCycle::StateCancelled]);
            $this->actingAs($applicant);
            Filament::setCurrentPanel(Filament::getPanel('applicant'));
            $before = $application->fresh()->getRawOriginal();
            $activityCount = Activity::count();
            $component = Livewire::withQueryParams(['application' => $application->id])
                ->test(ApplicantApplication::class);
            $wizard = collect($component->instance()->form->getComponents())->first(fn ($item): bool => $item instanceof Wizard);
            $this->assertInstanceOf(Wizard::class, $wizard);
            $steps = array_values($wizard->getChildSchema()->getComponents());
            $wizard->goToStep($steps[3]->getKey());
            $this->assertSame(3, $wizard->getCurrentStepIndex());
            $component->call('callSchemaComponentMethod', $wizard->getKey(), 'nextStep', [$wizard->getCurrentStepIndex()])
                ->assertDispatched('next-wizard-step', key: $wizard->getKey());
            $this->assertSame($before, $application->fresh()->getRawOriginal());
            $component->call('saveDraft')->assertSet('saveStatus', 'failed')->assertNoRedirect()
                ->call('submitApplication')->assertSet('saveStatus', 'failed')->assertNoRedirect();
            $this->assertSame($before, $application->fresh()->getRawOriginal());
            $this->assertSame(0, $application->evidenceVersions()->count());
            $this->assertSame($activityCount, Activity::count());
            $component->callAction('discardDraft')->assertRedirect();
            $this->assertModelMissing($application);
        }
    }

    private function createStaffUser(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);
        $user->saveAppAuthenticationSecret('base32secret3232');
        $user->acknowledgeRecoveryCodeStorage();

        return $user;
    }

    /** @return array{AdmissionApplication, User, AdmissionCycle, Program} */
    private function createDraftApplication(): array
    {
        $term = Term::query()->first() ?? Term::factory()->create();
        $cycle = AdmissionCycle::factory()->published()->create(['term_id' => $term->id]);
        $program = Program::factory()->create();
        $cycle->programs()->attach($program->id, [
            'accepts_first_year' => true,
            'accepts_transferee' => true,
        ]);

        $applicant = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $applicant->assignRole('applicant');

        $application = AdmissionApplication::factory()->create([
            'user_id' => $applicant->id,
            'admission_cycle_id' => $cycle->id,
            'term_id' => $term->id,
            'program_id' => $program->id,
            'application_state' => AdmissionApplication::StateDraft,
        ]);

        return [$application, $applicant, $cycle, $program];
    }
}

class Issue57EvidenceFailurePage extends AssistedAdmissionApplication
{
    protected function persistEvidence(AdmissionApplication $application, array $evidence): void
    {
        throw ValidationException::withMessages(['evidence' => 'The uploaded copy could not be stored.']);
    }
}
