<?php

namespace Tests\Feature;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Filament\Resources\StudentLifecycleChanges\Pages\CreateStudentLifecycleChange;
use App\Filament\Resources\StudentLifecycleChanges\Pages\ListStudentLifecycleChanges;
use App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource;
use App\Filament\Resources\StudentProfiles\Pages\ListStudentProfiles;
use App\Filament\Resources\StudentProfiles\Pages\ViewStudentProfile;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Filament\Student\Pages\LifecycleView;
use App\Models\Assessment;
use App\Models\CourseEnrollment;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\Hold;
use App\Models\ProgramShiftCreditEntry;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL73ProgressionLifecycleFilamentTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('testing', app()->environment());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());
        foreach ([User::StaffRoleRegistrar, User::StaffRoleAccounting, User::StaffRoleAcademicHead, User::StaffRoleSystemSuperAdmin, 'student'] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function future_leave_uses_shared_apply_cancel_actions_with_registrar_only_application_evidence(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $profile = StudentProfile::factory()->create(['lifecycle_status' => StudentProfile::LifecycleActive]);
        $term = Term::factory()->create();
        $returnTerm = Term::factory()->create(['starts_on' => today()->addMonths(5), 'ends_on' => today()->addMonths(8)]);
        $change = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'enrollment_id' => null,
            'type' => StudentLifecycleChange::TypeLeaveOfAbsence,
            'state' => StudentLifecycleChange::StateRecordedApproved,
            'expected_return_term_id' => $returnTerm->id,
            'effective_on' => today()->addDays(5),
            'impact_snapshot' => ['pending_application' => true, 'profile_status_before' => StudentProfile::LifecycleActive, 'profile_status_after' => StudentProfile::LifecycleActive, 'profile_status_on_application' => StudentProfile::LifecycleLeaveOfAbsence],
        ]);
        Livewire::test(ListStudentLifecycleChanges::class, ['selectedChangeId' => $change->id])
            ->assertSee('Approved effect')->assertSee('Current status, subjects and restrictions stay unchanged')
            ->assertActionDisabled(TestAction::make('apply')->table($change))
            ->assertActionVisible(TestAction::make('cancel')->table($change));

        $this->actingAs($this->staff(User::StaffRoleAcademicHead));
        Livewire::test(ListStudentLifecycleChanges::class, ['selectedChangeId' => $change->id])
            ->assertActionHidden(TestAction::make('apply')->table($change))
            ->assertActionHidden(TestAction::make('cancel')->table($change));

        $this->actingAs($registrar);
        $this->travel(6)->days();
        Livewire::test(ListStudentLifecycleChanges::class, ['selectedChangeId' => $change->id])
            ->callAction(TestAction::make('apply')->table($change))
            ->assertHasNoActionErrors()
            ->assertSee('Application effects')->assertSee('Applied by')->assertSee($registrar->name);
        $this->assertSame(StudentProfile::LifecycleLeaveOfAbsence, $profile->fresh()->lifecycle_status);
    }

    #[Test]
    public function resources_are_registered_and_policy_boundaries_are_server_enforced(): void
    {
        $resources = Filament::getPanel('admin')->getResources();
        $this->assertContains(StudentProfileResource::class, $resources);
        $this->assertContains(StudentLifecycleChangeResource::class, $resources);

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->assertTrue(StudentProfileResource::canAccess());
        $this->assertTrue(StudentLifecycleChangeResource::canCreate());
        Livewire::test(ListStudentProfiles::class)->assertOk();

        $accounting = $this->staff(User::StaffRoleAccounting);
        $this->actingAs($accounting);
        $this->assertTrue(StudentLifecycleChangeResource::canAccess());
        $this->assertFalse(StudentLifecycleChangeResource::canCreate());

        $student = $this->staff('student');
        $this->actingAs($student);
        $this->assertFalse(StudentLifecycleChangeResource::canAccess());
    }

    #[Test]
    public function operational_table_filters_and_program_shift_apply_action_visibility_work(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $profile = StudentProfile::factory()->create();
        $term = Term::factory()->create();
        $withdrawal = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'type' => StudentLifecycleChange::TypeWithdrawal,
            'state' => StudentLifecycleChange::StateApplied,
        ]);
        $shift = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'type' => StudentLifecycleChange::TypeProgramShift,
            'state' => StudentLifecycleChange::StateRecordedApproved,
        ]);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListStudentLifecycleChanges::class)
            ->filterTable('type', StudentLifecycleChange::TypeWithdrawal)
            ->assertCanSeeTableRecords([$withdrawal])
            ->assertCanNotSeeTableRecords([$shift]);

        Livewire::test(ListStudentLifecycleChanges::class)
            ->assertActionVisible(TestAction::make('apply')->table($shift))
            ->assertActionVisible(TestAction::make('cancel')->table($shift))
            ->assertActionHidden(TestAction::make('cancel')->table($withdrawal))
            ->assertActionHidden(TestAction::make('apply')->table($withdrawal));
    }

    #[Test]
    public function create_modal_contract_validates_required_approved_result_fields(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateStudentLifecycleChange::class)
            ->fillForm([])
            ->call('create')
            ->assertHasFormErrors([
                'student_profile_id' => 'required',
                'term_id' => 'required',
                'type' => 'required',
                'effective_on' => 'required',
                'reason' => 'required',
            ]);
    }

    #[Test]
    public function lifecycle_form_uses_the_same_official_enrollment_status_as_the_student_record(): void
    {
        $this->actingAs($this->staff(User::StaffRoleRegistrar));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $enrollment = Enrollment::factory()->create([
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'status' => 'pending_payment',
        ]);

        Livewire::test(CreateStudentLifecycleChange::class)
            ->fillForm([
                'student_profile_id' => $enrollment->student_profile_id,
                'term_id' => $enrollment->term_id,
            ])
            ->assertSee('Officially Enrolled')
            ->assertDontSee('Pending payment');
    }

    #[Test]
    public function student_selection_reveals_the_workarea_and_changing_student_clears_unsaved_details(): void
    {
        $this->actingAs($this->staff(User::StaffRoleRegistrar));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $student = StudentProfile::factory()->create();

        Livewire::test(CreateStudentLifecycleChange::class)
            ->assertSee('Choose a student')
            ->assertDontSee('What changed?')
            ->assertDontSee('Review effects')
            ->fillForm(['student_profile_id' => $student->id])
            ->assertDontSee('Choose a student')
            ->assertSee('What changed?')
            ->assertSee('Review effects')
            ->fillForm([
                'type' => StudentLifecycleChange::TypeWithdrawal,
                'reason' => 'An unsaved change for the first student.',
                'impact_confirmed' => true,
            ])
            ->call('changeStudent')
            ->assertSee('Choose a student')
            ->assertDontSee('What changed?')
            ->assertFormSet([
                'student_profile_id' => null,
                'type' => null,
                'reason' => null,
                'impact_confirmed' => false,
            ]);
    }

    #[Test]
    public function changing_change_type_discards_details_and_confirmation_from_the_previous_change(): void
    {
        $this->actingAs($this->staff(User::StaffRoleRegistrar));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateStudentLifecycleChange::class)
            ->fillForm(['student_profile_id' => StudentProfile::factory()->create()->id])
            ->fillForm([
                'type' => StudentLifecycleChange::TypeProgramShift,
                'expected_return_term_id' => 123,
                'target_program_id' => 123,
                'target_curriculum_version_id' => 123,
                'credit_entries' => [['curriculum_entry_id' => 123]],
                'impact_confirmed' => true,
            ])
            ->set('data.type', StudentLifecycleChange::TypeWithdrawal)
            ->assertFormSet([
                'expected_return_term_id' => null,
                'target_program_id' => null,
                'target_curriculum_version_id' => null,
                'credit_entries' => [],
                'impact_confirmed' => false,
            ]);
    }

    #[Test]
    public function recording_form_only_shows_relevant_change_details_and_rejects_separate_enrollment_and_graduation_tasks(): void
    {
        $this->actingAs($this->staff(User::StaffRoleRegistrar));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateStudentLifecycleChange::class)
            ->fillForm(['student_profile_id' => StudentProfile::factory()->create()->id])
            ->fillForm(['type' => StudentLifecycleChange::TypeLeaveOfAbsence])
            ->assertFormFieldIsVisible('expected_return_term_id')
            ->assertFormFieldIsHidden('target_program_id')
            ->fillForm(['type' => StudentLifecycleChange::TypeProgramShift])
            ->assertFormFieldIsHidden('expected_return_term_id')
            ->assertFormFieldIsVisible('target_program_id')
            ->fillForm(['type' => StudentLifecycleChange::TypeWithdrawal])
            ->fillForm(['type' => StudentLifecycleChange::TypeCompletion])
            ->call('create')
            ->assertHasFormErrors(['type' => 'in'])
            ->fillForm(['type' => StudentLifecycleChange::TypeSubjectDrop])
            ->call('create')
            ->assertHasFormErrors(['type' => 'in']);
    }

    #[Test]
    public function return_to_studies_is_unavailable_for_an_active_student_but_available_after_leave(): void
    {
        $this->actingAs($this->staff(User::StaffRoleRegistrar));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $student = StudentProfile::factory()->create(['lifecycle_status' => StudentProfile::LifecycleActive]);
        $page = Livewire::test(CreateStudentLifecycleChange::class)
            ->fillForm(['student_profile_id' => $student->id])
            ->assertFormFieldExists('type', fn (Radio $field): bool => $field->isOptionDisabled(StudentLifecycleChange::TypeReactivation, 'Return to studies'))
            ->fillForm(['type' => StudentLifecycleChange::TypeReactivation])
            ->call('create')
            ->assertHasFormErrors(['type' => 'in']);

        $student->update(['lifecycle_status' => StudentProfile::LifecycleLeaveOfAbsence]);
        $page->fillForm(['student_profile_id' => $student->id])
            ->assertFormFieldExists('type', fn (Radio $field): bool => ! $field->isOptionDisabled(StudentLifecycleChange::TypeReactivation, 'Return to studies'));
    }

    #[Test]
    public function head_lifecycle_record_preserves_oversight_context_and_names_all_term_scope(): void
    {
        $this->actingAs($this->staff(User::StaffRoleAcademicHead));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $term = Term::factory()->create();
        $change = StudentLifecycleChange::factory()->create(['term_id' => $term->id]);

        $page = Livewire::withQueryParams(['change' => $change->id, 'oversightTermId' => $term->id])
            ->test(ListStudentLifecycleChanges::class)
            ->assertSee('All terms.')
            ->assertSee('Back to Academic Oversight')
            ->assertSee(AcademicApprovals::getUrl(['termId' => $term->id]), false)
            ->assertSet('selectedChangeId', $change->id)
            ->assertCanSeeTableRecords([$change]);

        $this->assertArrayHasKey(
            AcademicApprovals::getUrl(['termId' => $term->id]),
            $page->instance()->getBreadcrumbs(),
        );
        Livewire::withQueryParams([]);
    }

    #[Test]
    public function selected_lifecycle_change_shows_original_effects_recording_and_credit_evidence_without_generic_handoffs(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $change = StudentLifecycleChange::factory()->create([
            'type' => StudentLifecycleChange::TypeProgramShift,
            'state' => StudentLifecycleChange::StateApplied,
            'impact_snapshot' => [
                'program_before' => ['name' => 'Original recorded program'],
                'program_after' => ['name' => 'Original approved target'],
                'profile_status_after' => StudentProfile::LifecycleActive,
                'course_enrollment_ids' => [123, 456],
                'cor_available_after' => false,
            ],
            'reason' => 'Approved change <script>alert("reason")</script>',
            'recorded_by' => $registrar->id,
            'authority' => 'Original approval reference',
            'private_source_reference' => 'Private office record',
        ]);
        $credit = ProgramShiftCreditEntry::factory()->create([
            'student_lifecycle_change_id' => $change->id,
            'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
            'numeric_grade' => '1.7500',
        ]);

        Livewire::test(ListStudentLifecycleChanges::class, ['selectedChangeId' => $change->id])
            ->assertSuccessful()
            ->assertSee('Original recorded program')
            ->assertSee('Original approved target')
            ->assertSee('Private office record')
            ->assertSee('Original approval reference')
            ->assertSee($registrar->name)
            ->assertSee('Approved change <script>alert("reason")</script>')
            ->assertDontSee('<script>alert("reason")</script>', false)
            ->assertSee('Affected subjects')
            ->assertSee('Unavailable')
            ->assertSee('Program shift credits')
            ->assertSee($credit->curriculumEntry->courseSpecification->title)
            ->assertSee('Accepted')
            ->assertSee('1.7500')
            ->assertDontSee('Open full record')
            ->assertDontSee('Now · Registrar')
            ->assertDontSee('Next · Student')
            ->assertDontSee('Schedule assignments released');

        $change->update(['impact_snapshot' => []]);
        Livewire::test(ListStudentLifecycleChanges::class, ['selectedChangeId' => $change->id])
            ->assertSuccessful()
            ->assertSee('The original effect snapshot is unavailable.')
            ->assertDontSee('Affected subjects')
            ->assertDontSee('Original recorded program');
    }

    #[Test]
    public function legacy_lifecycle_link_redirects_authorized_staff_to_the_selected_workspace_and_refuses_students(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $term = Term::factory()->create();
        $change = StudentLifecycleChange::factory()->create(['term_id' => $term->id]);
        $legacyUrl = StudentLifecycleChangeResource::getUrl('view', ['record' => $change, 'oversightTermId' => $term->id]);
        $head = $this->staff(User::StaffRoleAcademicHead);
        $head->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $head->forceFill(['two_factor_recovery_codes_acknowledged_at' => now()])->save();

        $this->actingAs($head)
            ->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleAcademicHead])
            ->get($legacyUrl)
            ->assertRedirect(StudentLifecycleChangeResource::getUrl('index', ['change' => $change->id, 'oversightTermId' => $term->id]));

        $this->actingAs($this->staff('student'))
            ->withSession([WorkspaceContextResolver::SessionKey => null])
            ->get($legacyUrl)
            ->assertForbidden();
    }

    #[Test]
    public function registrar_sees_standing_action_but_not_the_retired_generic_unit_load_exception(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $profile = StudentProfile::factory()->create();
        $enrollment = Enrollment::factory()->create(['student_profile_id' => $profile->id]);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $profilePage = Livewire::test(ViewStudentProfile::class, ['record' => $profile->getRouteKey(), 'viewTab' => 'academic-progress']);
        $profilePage->set('recordTab', 'academic')->assertSee('Record enrollment decision');
        $this->assertEmpty($profilePage->instance()->getCachedHeaderActions());
        $actions = collect(Livewire::test(ViewEnrollment::class, ['record' => $enrollment->getRouteKey()])
            ->instance()
            ->getCachedHeaderActions())
            ->flatMap(fn ($action): array => method_exists($action, 'getFlatActions') ? $action->getFlatActions() : [$action->getName() => $action]);
        $this->assertArrayNotHasKey('unitLoadException', $actions->all());
    }

    #[Test]
    public function student_record_separates_confirmed_standing_and_recommendation_with_history_and_holds(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $profile = StudentProfile::factory()->create([
            'academic_standing' => StudentProfile::StandingProbationary,
        ]);
        $priorTerm = Term::factory()->create(['label' => 'AY 2025-2026 First Semester']);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $priorTerm->id,
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => now(),
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
            'student_type' => null,
        ]);
        $lifecycleChange = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $priorTerm->id,
            'type' => StudentLifecycleChange::TypeLeaveOfAbsence,
            'state' => StudentLifecycleChange::StateApplied,
            'reason' => 'Approved temporary leave.',
        ]);
        Hold::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $priorTerm->id,
            'status' => Hold::StatusActive,
            'hold_type' => Hold::TypeAcademicDeficit,
            'blocking_level' => Hold::BlockingEnrollment,
            'reason' => 'Academic review required.',
            'resolution_requirement' => 'Meet the Academic Head.',
        ]);
        $offering = TermOffering::factory()->create(['term_id' => $priorTerm->id]);
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => 3,
            'added_at' => now(),
        ]);
        $roster = GradeRoster::factory()->create([
            'term_offering_id' => $offering->id,
            'state' => GradeRoster::StateReleased,
            'released_at' => now(),
        ]);
        $row = GradeRosterRow::factory()->create([
            'grade_roster_id' => $roster->id,
            'course_enrollment_id' => $courseEnrollment->id,
            'current_outcome_code' => '2.25',
            'current_outcome_category' => GradeRosterRow::CategoryPassing,
            'released_at' => now(),
        ]);
        Assessment::query()->create([
            'enrollment_id' => $enrollment->id,
            'version' => 1,
            'state' => Assessment::StateActive,
            'currency' => 'PHP',
            'subtotal' => 15000,
            'discount_total' => 0,
            'total' => 15000,
            'required_downpayment' => 5000,
        ]);

        GradeOutcomeEvent::factory()->create(['grade_roster_row_id' => $row->id, 'result_code' => '2.25', 'new_value' => 2.25, 'released_at' => now()]);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ViewStudentProfile::class, ['record' => $profile->getRouteKey()])
            ->assertSee('Academic summary')
            ->assertSee('Next enrollment')
            ->assertSee('AY 2025-2026 First Semester')
            ->assertSee('Officially Enrolled')
            ->assertSee('Released grades')
            ->assertSee('2.25')
            ->assertSee('Financial History')
            ->assertSee('PHP 15,000.00')
            ->assertSee('Open Enrollment')
            ->assertSee('Open Grade Roster')
            ->assertSee('Open Registration Case')
            ->assertSee('Open Lifecycle Record')
            ->assertSee('Approved temporary leave.')
            ->assertSee('Academic review required.')
            ->assertSee('Registrar Office');

        $this->assertNotNull($lifecycleChange->getKey());
    }

    #[Test]
    public function student_hub_lifecycle_projection_is_read_only_and_own_record_only(): void
    {
        $student = $this->staff('student');
        $ownProfile = StudentProfile::factory()->create(['user_id' => $student->id]);
        $otherProfile = StudentProfile::factory()->create();
        $own = StudentLifecycleChange::factory()->create(['student_profile_id' => $ownProfile->id, 'state' => StudentLifecycleChange::StateApplied]);
        $other = StudentLifecycleChange::factory()->create(['student_profile_id' => $otherProfile->id, 'state' => StudentLifecycleChange::StateApplied]);
        $this->actingAs($student);
        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::test(LifecycleView::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$other]);
    }

    #[Test]
    public function lifecycle_inspector_selection_is_limited_to_the_filtered_page_and_clears_stale_details(): void
    {
        $this->actingAs($this->staff(User::StaffRoleRegistrar));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $term = Term::factory()->create();
        $profile = StudentProfile::factory()->create(['student_number' => 'QA78-LIFECYCLE-SELECT']);
        $changes = StudentLifecycleChange::factory()->count(11)->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'state' => StudentLifecycleChange::StateApplied,
        ]);
        $page = Livewire::test(ListStudentLifecycleChanges::class)
            ->filterTable('term', $term->id)
            ->set('tableRecordsPerPage', 10);
        $visible = $page->instance()->getTableRecords();
        $this->assertCount(10, $visible);
        $hidden = $changes->first(fn ($change): bool => ! $visible->contains('id', $change->id));
        $page->call('selectChange', $visible->first()->id)
            ->assertSet('selectedChangeId', $visible->first()->id)
            ->assertDispatched('record-selected')
            ->searchTable('no-such-lifecycle-student')
            ->assertSee('No matching student changes')
            ->assertDontSee('Selected student change')
            ->call('clearLifecycleFilters')
            ->assertSet('selectedChangeId', null)
            ->assertSet('tableSearch', '');

        Livewire::test(ListStudentLifecycleChanges::class)
            ->filterTable('term', $term->id)
            ->set('tableRecordsPerPage', 10)
            ->call('selectChange', $hidden->id)
            ->assertStatus(404);
    }

    #[Test]
    public function lifecycle_deep_link_opens_the_selected_changes_page_and_respects_explicit_page_and_filter_context(): void
    {
        $this->actingAs($this->staff(User::StaffRoleRegistrar));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $profile = StudentProfile::factory()->create(['student_number' => 'QA78-LIFECYCLE-DEEP-LINK']);
        $term = Term::factory()->create();
        $oldest = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'effective_on' => '2026-01-01',
            'state' => StudentLifecycleChange::StateApplied,
            'reason' => 'PRIVATE-OLDER-SELECTED-CHANGE',
        ]);
        StudentLifecycleChange::factory()->count(10)->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'effective_on' => '2026-01-01',
            'state' => StudentLifecycleChange::StateApplied,
            'reason' => 'Visible newer student change',
        ]);

        $page = Livewire::withQueryParams(['change' => $oldest->id, 'search' => $profile->student_number])
            ->test(ListStudentLifecycleChanges::class)
            ->assertSee('PRIVATE-OLDER-SELECTED-CHANGE')
            ->assertCanSeeTableRecords([$oldest]);
        $this->assertSame(2, $page->instance()->getTableRecords()->currentPage());
        $this->assertSame(10, $page->instance()->getTableRecordsPerPage());

        Livewire::withQueryParams(['change' => $oldest->id, 'search' => $profile->student_number, 'page' => 1])
            ->test(ListStudentLifecycleChanges::class)
            ->assertCanNotSeeTableRecords([$oldest])
            ->assertDontSee('PRIVATE-OLDER-SELECTED-CHANGE');

        Livewire::withQueryParams([
            'change' => $oldest->id,
            'search' => $profile->student_number,
            'filters' => ['type' => ['value' => StudentLifecycleChange::TypeProgramShift]],
        ])->test(ListStudentLifecycleChanges::class)
            ->assertSee('No matching student changes')
            ->assertDontSee('PRIVATE-OLDER-SELECTED-CHANGE');

        $this->withSession([$page->instance()->getTablePerPageSessionKey() => 25]);
        $largerPage = Livewire::withQueryParams(['change' => $oldest->id, 'search' => $profile->student_number])
            ->test(ListStudentLifecycleChanges::class)
            ->assertSee('PRIVATE-OLDER-SELECTED-CHANGE')
            ->assertCanSeeTableRecords([$oldest]);
        $this->assertSame(25, $largerPage->instance()->getTableRecordsPerPage());
        $this->assertSame(1, $largerPage->instance()->getTableRecords()->currentPage());
        Livewire::withQueryParams([]);
    }

    #[Test]
    public function lifecycle_inspector_uses_recorded_program_facts_and_explains_future_application(): void
    {
        $this->freezeTime();
        $this->actingAs($this->staff(User::StaffRoleRegistrar));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $term = Term::factory()->create(['starts_on' => today()->subDay()]);
        $change = StudentLifecycleChange::factory()->create([
            'term_id' => $term->id,
            'type' => StudentLifecycleChange::TypeProgramShift,
            'effective_on' => today()->addDays(3),
            'state' => StudentLifecycleChange::StateRecordedApproved,
            'impact_snapshot' => ['program_before' => ['name' => 'Recorded former program']],
        ]);

        Livewire::test(ListStudentLifecycleChanges::class, ['selectedChangeId' => $change->id])
            ->assertSee('Recorded former program')
            ->assertSee('Target program unavailable')
            ->assertSee('Needs source review')
            ->assertSee('Review the missing target program or curriculum')
            ->assertActionDisabled(TestAction::make('apply')->table($change))
            ->assertActionEnabled(TestAction::make('cancel')->table($change));

        $target = CurriculumVersion::factory()->create();
        $change->update(['target_program_id' => $target->program_id, 'target_curriculum_version_id' => $target->id]);
        Livewire::test(ListStudentLifecycleChanges::class, ['selectedChangeId' => $change->id])
            ->assertSee('Waiting for effective date')
            ->assertSee('Lifecycle · Recorded approval')
            ->assertSee('Lifecycle · Apply program shift')
            ->assertSee('it is not applied automatically.')
            ->assertSee('Return here on or after')
            ->assertDontSee('Open student record')
            ->assertActionDisabled(TestAction::make('apply')->table($change))
            ->assertActionEnabled(TestAction::make('cancel')->table($change));

        $this->travel(4)->days();
        Livewire::test(ListStudentLifecycleChanges::class, ['selectedChangeId' => $change->id])
            ->assertSee('Ready to apply')
            ->assertSee('Open student record')
            ->assertSee(StudentProfileResource::getUrl('view', ['record' => $change->studentProfile, 'viewTab' => 'academic-progress']))
            ->assertActionEnabled(TestAction::make('apply')->table($change));
    }

    #[Test]
    public function lifecycle_inspector_does_not_offer_mutations_to_read_only_staff(): void
    {
        $change = StudentLifecycleChange::factory()->create(['type' => StudentLifecycleChange::TypeProgramShift]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach ([User::StaffRoleAcademicHead, User::StaffRoleAccounting, User::StaffRoleSystemSuperAdmin] as $role) {
            $this->actingAs($this->staff($role));
            Livewire::test(ListStudentLifecycleChanges::class, ['selectedChangeId' => $change->id])
                ->assertSee($change->reason)
                ->assertSee($change->private_source_reference)
                ->assertDontSee('Open full record')
                ->assertActionHidden(TestAction::make('apply')->table($change))
                ->assertActionHidden(TestAction::make('cancel')->table($change))
                ->assertDontSee('Record student change');
        }
        $this->actingAs($this->staff('student'));
        Livewire::test(ListStudentLifecycleChanges::class)->assertForbidden();
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
