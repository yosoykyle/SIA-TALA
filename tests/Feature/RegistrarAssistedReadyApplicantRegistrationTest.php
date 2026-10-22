<?php

namespace Tests\Feature;

use App\Actions\Enrollment\PrepareRegistrationProposal;
use App\Actions\Enrollment\RegistrationWindowApplicability;
use App\Actions\Enrollment\StartRegistrationCase;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\Enrollments\Pages\ListEnrollments;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Models\AcademicYear;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionDecision;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationSubmissionVersion;
use App\Models\Assessment;
use App\Models\CorVersion;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\OfficialCredentialResult;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrarEnrollmentClearance;
use App\Models\RegistrationCaseEvent;
use App\Models\RegistrationProposalVersion;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RegistrarAssistedReadyApplicantRegistrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());

        foreach ([User::StaffRoleRegistrar, User::StaffRoleAccounting, User::StaffRoleSystemSuperAdmin, 'student', 'applicant'] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_advised_applicant_prepares_published_and_externally_arranged_subjects_without_a_student_profile(): void
    {
        [$case, $registrar, $sections] = $this->advisedPreparationFixture();
        $foreignCurriculum = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateActive]);
        $foreignEntry = CurriculumEntry::factory()->for($foreignCurriculum)->for(CourseSpecification::factory()->create([
            'title' => 'Foreign program subject', 'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
        ]), 'courseSpecification')->create();
        $foreignOffering = TermOffering::factory()->for($case->term)->for($foreignEntry)->create(['state' => TermOffering::StateScheduled]);
        Section::factory()->for($foreignOffering, 'termOffering')->create(['state' => Section::StateOpen]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)->test(ViewEnrollment::class, ['record' => $case->id])
            ->assertSee('Prepare subjects')->assertSee('No subjects selected')
            ->assertDontSee('Assessed total')->assertDontSee('Five finalization checkpoints')
            ->mountAction('prepareProposal')
            ->assertMountedActionModalSee('Published subject')->assertMountedActionModalSee('Externally arranged subject')
            ->assertMountedActionModalDontSee('Foreign program subject')
            ->assertMountedActionModalDontSee('Curriculum term for the normal unit load')
            ->setActionData(['section_ids' => $sections->pluck('id')->all()])->callMountedAction()
            ->assertHasNoActionErrors()->assertNotified('Subjects saved for review')
            ->assertRedirect(EnrollmentResource::getUrl('view', ['record' => $case]));

        $case->refresh();
        $this->assertNull($case->student_profile_id);
        $this->assertFalse($case->credentialUser->hasRole('student'));
        $this->assertSame(RegistrationProposalVersion::StateDraft, $case->currentProposalVersion->state);
        $this->assertSame($sections->pluck('id')->sort()->values()->all(), $case->currentProposalVersion->items->pluck('section_id')->sort()->values()->all());
        $this->assertSame(0, $case->courseEnrollments()->count());
    }

    public function test_subject_preparation_rejects_an_older_open_dialog_without_replacing_the_newer_draft(): void
    {
        [$case, $registrar, $sections] = $this->advisedPreparationFixture();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($registrar)->test(ViewEnrollment::class, ['record' => $case->id])->mountAction('prepareProposal');
        $newer = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$sections->first()->id], $case->lock_version);

        $page->setActionData(['section_ids' => $sections->pluck('id')->all()])->callMountedAction()->assertNotified('Subjects were not saved');

        $this->assertSame($newer->id, $case->fresh()->current_proposal_version_id);
        $this->assertSame(1, $case->proposalVersions()->count());
        $this->assertSame([$sections->first()->id], $newer->items()->pluck('section_id')->all());
    }

    public function test_seat_reservation_uses_the_reviewed_subjects_and_rejects_a_newer_draft(): void
    {
        [$case, $registrar, $sections] = $this->advisedPreparationFixture();
        $draft = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$sections->first()->id], $case->lock_version);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($registrar)->test(ViewEnrollment::class, ['record' => $case->id])
            ->assertSee('Review subjects')->assertDontSee('Assessed total')->mountAction('placeProposal');
        $newer = app(PrepareRegistrationProposal::class)->execute($case->fresh(), $registrar, $sections->pluck('id')->all(), $case->fresh()->lock_version);

        $page->callMountedAction()->assertNotified('Seats were not reserved');
        $this->assertSame(0, $case->seatReservations()->count());
        $this->assertSame($newer->id, $case->fresh()->current_proposal_version_id);

        Livewire::actingAs($registrar)->test(ViewEnrollment::class, ['record' => $case->id])
            ->mountAction('placeProposal')->callMountedAction()->assertNotified('Seats reserved')
            ->assertRedirect(EnrollmentResource::getUrl('view', ['record' => $case]));
        $this->assertSame(2, $case->seatReservations()->where('status', EnrollmentSeatReservation::StatusActive)->count());
        $this->assertSame(RegistrationProposalVersion::StateSuperseded, $draft->fresh()->state);
    }

    public function test_advised_applicant_can_name_the_normal_curriculum_term_for_mixed_level_subjects(): void
    {
        [$case, $registrar, $sections] = $this->advisedPreparationFixture(mixedLevels: true);
        $curriculum = $sections->first()->termOffering->curriculumEntry->curriculumVersion;
        CurriculumEntry::factory()->for($curriculum)->for(CourseSpecification::factory()->create(['credit_units' => '6.00']), 'courseSpecification')->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)->test(ViewEnrollment::class, ['record' => $case->id])
            ->mountAction('prepareProposal')
            ->setActionData(['section_ids' => $sections->pluck('id')->all()])
            ->assertMountedActionModalSee('Curriculum term for the normal unit load')
            ->setActionData([
                'section_ids' => $sections->pluck('id')->all(),
                'curriculum_position' => 'First Year|First Semester|'.Term::TypeFirstSemester,
            ])->callMountedAction()->assertHasNoActionErrors()->assertNotified('Subjects saved for review');

        $this->assertSame('First Year', data_get($case->fresh()->currentProposalVersion->source_snapshot, 'unit_load.year_level'));
        $this->assertSame('9.00', data_get($case->fresh()->currentProposalVersion->source_snapshot, 'unit_load.normal_total'));
        $this->assertNull($case->fresh()->student_profile_id);
    }

    /** @return array{Enrollment, User, Collection<int, Section>} */
    private function advisedPreparationFixture(bool $mixedLevels = false): array
    {
        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);
        $case = Enrollment::factory()->for($term)->create([
            'admission_application_id' => $application->id, 'credential_user_id' => $application->user_id,
            'student_profile_id' => null, 'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
        ]);
        $curriculum = CurriculumVersion::factory()->for($application->program)->create(['state' => CurriculumVersion::StateActive, 'effective_entry_term_id' => $term->id]);
        $timetable = PublishedTimetableVersion::factory()->for($term)->create();
        $sections = collect();
        foreach ([false, true] as $external) {
            $specification = CourseSpecification::factory()->create([
                'title' => $external ? 'Externally arranged subject' : 'Published subject',
                'credit_units' => '3.00',
                'scheduling_treatment' => $external ? CourseSpecification::SchedulingExternallyArranged : CourseSpecification::SchedulingRecurring,
            ]);
            $entry = CurriculumEntry::factory()->for($curriculum)->for($specification, 'courseSpecification')->create([
                'year_level' => $external && $mixedLevels ? 'Second Year' : 'First Year',
            ]);
            $offering = TermOffering::factory()->for($term)->for($entry)->create([
                'state' => TermOffering::StateScheduled,
                'category' => $mixedLevels ? TermOffering::CategorySpecial : TermOffering::CategoryRegular,
                'special_reason' => $mixedLevels ? 'Authorized mixed-level advising fixture' : null,
            ]);
            $section = Section::factory()->for($offering, 'termOffering')->create(['state' => Section::StateOpen]);
            if (! $external) {
                PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create(['section_id' => $section->id]);
            }
            $sections->push($section);
        }

        return [$case, $registrar, $sections];
    }

    public function test_registrar_changes_allowed_learners_without_a_typed_reference_and_preserves_the_audit_source(): void
    {
        $registrar = $this->createRegistrar();
        $term = Term::factory()->create([
            'registration_applicability' => 'ReadyApplicants',
            'registration_applicability_authority' => 'EARLIER-SOURCE',
        ]);
        $dates = [$term->starts_on->toDateString(), $term->ends_on->toDateString()];
        app(RegistrationWindowApplicability::class)->record($term, $registrar, 'ReadyApplicants');
        $this->assertSame('EARLIER-SOURCE', $term->fresh()->registration_applicability_authority);
        $this->assertSame(0, DB::table('activity_log')->where('subject_type', $term->getMorphClass())
            ->where('subject_id', $term->id)->where('event', 'registration_applicability_recorded')->count());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)->test(ListEnrollments::class)
            ->mountAction('enrollmentApplicability')
            ->setActionData(['term_id' => $term->id])
            ->assertActionDataSet(['applicability' => 'ReadyApplicants'])
            ->assertMountedActionModalDontSee('School authorization reference')
            ->setActionData([
                'applicability' => 'StandardContinuing',
                'authority_reference' => 'FORGED-UNREQUESTED-REFERENCE',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Allowed learners saved');

        $term->refresh();
        $this->assertSame('StandardContinuing', $term->registration_applicability);
        $this->assertNull($term->registration_applicability_authority);
        $this->assertSame($dates, [$term->starts_on->toDateString(), $term->ends_on->toDateString()]);
        $setting = app(RegistrationWindowApplicability::class);
        $this->assertTrue($setting->permits($term, Enrollment::SelectionStandardCurriculum, false));
        $this->assertFalse($setting->permits($term, Enrollment::SelectionStandardCurriculum, true));
        $this->assertFalse($setting->permits($term, Enrollment::SelectionIndividuallyAdvised, false));

        $entry = DB::table('activity_log')->where('subject_type', $term->getMorphClass())
            ->where('subject_id', $term->id)->where('event', 'registration_applicability_recorded')->sole();
        $facts = json_decode($entry->properties, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($registrar->id, (int) $entry->causer_id);
        $this->assertNotNull($entry->created_at);
        $this->assertSame('EARLIER-SOURCE', $facts['before']['registration_applicability_authority']);
        $this->assertSame('ReadyApplicants', $facts['before']['registration_applicability']);
        $this->assertSame('StandardContinuing', $facts['after']['registration_applicability']);
        $this->assertNull($facts['after']['registration_applicability_authority']);

        $entryCount = DB::table('activity_log')->where('subject_type', $term->getMorphClass())->where('subject_id', $term->id)->count();
        $setting->record($term, $registrar, 'StandardContinuing');
        $this->assertSame($entryCount, DB::table('activity_log')->where('subject_type', $term->getMorphClass())->where('subject_id', $term->id)->count());

        $legacy = $setting->record($term, $registrar, 'ReadyApplicants', '  SUPPLIED-SOURCE  ');
        $this->assertSame('SUPPLIED-SOURCE', $legacy->registration_applicability_authority);
    }

    public function test_allowed_learner_setting_refuses_an_invalid_group_and_an_unrelated_staff_member(): void
    {
        $term = Term::factory()->create(['registration_applicability' => 'ReadyApplicants']);
        $setting = app(RegistrationWindowApplicability::class);
        try {
            $setting->record($term, $this->createRegistrar(), 'UnknownGroup');
            $this->fail('An unknown audience must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('applicability', $exception->errors());
        }

        $accounting = User::factory()->create(['status' => User::StatusActive]);
        $accounting->assignRole(User::StaffRoleAccounting);
        try {
            $setting->record($term, $accounting, 'AllEligible');
            $this->fail('Accounting must not change the enrollment audience.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Only Registrar may record enrollment window applicability.', $exception->getMessage());
        }

        $this->assertSame('ReadyApplicants', $term->fresh()->registration_applicability);
        $this->assertSame(0, DB::table('activity_log')->where('subject_type', $term->getMorphClass())
            ->where('subject_id', $term->id)->where('event', 'registration_applicability_recorded')->count());
    }

    public function test_registrar_record_lookup_keeps_official_and_in_progress_cases_reachable(): void
    {
        $registrar = $this->createRegistrar();
        $pending = Enrollment::factory()->create(['canonical_outcome' => Enrollment::OutcomeInProgress]);
        $pending->credentialUser->update(User::staffNamePayload('Maria', 'Cruz', 'Santos'));
        $official = Enrollment::factory()->create(['canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)->test(ListEnrollments::class)
            ->assertSet('activeTab', 'overview')
            ->assertSeeHtml('wire:partial="action-modals"')
            ->mountAction('enrollmentApplicability')
            ->assertActionMounted('enrollmentApplicability')
            ->assertMountedActionModalDontSee('School authorization reference')
            ->unmountAction()
            ->set('activeTab', 'all_cases')
            ->assertCanSeeTableRecords([$pending, $official])
            ->set('activeTab', 'official_history')
            ->assertCanSeeTableRecords([$official])
            ->assertCanNotSeeTableRecords([$pending])
            ->set('activeTab', 'all_cases')
            ->searchTable($pending->case_reference)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$official])
            ->searchTable('Maria Santos')
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$official])
            ->searchTable('Maria '.$pending->studentProfile->student_number)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$official])
            ->searchTable('Maria Unrelatedname')
            ->assertCanNotSeeTableRecords([$pending, $official]);
    }

    public function test_registrar_can_start_registration_case_for_ready_applicant(): void
    {
        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $test = Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->assertActionExists('startReadyApplicantRegistration')
            ->callAction('startReadyApplicantRegistration', [
                'application_id' => $application->id,
                'term_id' => $term->id,
                'authority_reference' => 'In-person intake desk, Slip #R-2026-001',
            ])
            ->assertHasNoActionErrors();

        $enrollment = Enrollment::query()
            ->where('admission_application_id', $application->id)
            ->where('term_id', $term->id)
            ->first();

        $this->assertNotNull($enrollment);
        $this->assertSame($application->user_id, $enrollment->credential_user_id);
        $this->assertSame('RegistrarAssisted', $enrollment->start_method);
        $this->assertSame($registrar->id, $enrollment->started_by);
        $this->assertNull($enrollment->student_profile_id);
        $this->assertSame(Enrollment::OutcomeInProgress, $enrollment->canonical_outcome);

        $test->assertRedirect(EnrollmentResource::getUrl('view', ['record' => $enrollment]));

        $event = RegistrationCaseEvent::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('event_type', 'Started')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('RegistrarAssisted', $event->reason);
        $this->assertNull($event->authority_reference);
        $this->assertSame($registrar->id, $event->actor_id);
    }

    public function test_starting_case_for_applicant_with_existing_case_opens_existing_case_without_duplicate(): void
    {
        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);

        // Pre-create the enrollment case
        $existingEnrollment = app(StartRegistrationCase::class)->forReadyApplicant(
            $application,
            $term,
            $registrar,
            'RegistrarAssisted',
            'Initial intake voucher #101',
        );

        $initialCount = Enrollment::query()->where('admission_application_id', $application->id)->count();
        $this->assertSame(1, $initialCount);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // 1. In startReadyApplicantRegistration, the applicant with an existing case is excluded from options
        $component = Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->mountAction('startReadyApplicantRegistration');

        $schemaName = $component->instance()->getMountedActionSchemaName();
        $schema = $component->instance()->getSchema($schemaName);
        $options = $schema->getComponent('application_id')->getOptions();
        $this->assertArrayNotHasKey($application->id, $options);

        $this->assertFalse($component->instance()->readyApplicantListData()['applications']->contains('id', $application->id));
        $this->assertSame(EnrollmentResource::getUrl('index', ['tab' => 'ready_applicants']), $component->instance()->getAction('readyApplicants')->getUrl());

        // 3. Directly accessing the case on ViewEnrollment succeeds
        Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $existingEnrollment->getRouteKey()])
            ->assertSuccessful();

        $afterCount = Enrollment::query()->where('admission_application_id', $application->id)->count();
        $this->assertSame(1, $afterCount);
    }

    public function test_starting_case_rejects_wrong_term_selection(): void
    {
        $registrar = $this->createRegistrar();
        $termA = $this->createTermWithOpenEnrollmentWindow('Term A');
        $termB = $this->createTermWithOpenEnrollmentWindow('Term B');
        $application = $this->createReadyApplicant($termA);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->callAction('startReadyApplicantRegistration', [
                'application_id' => $application->id,
                'term_id' => $termB->id,
                'authority_reference' => 'Wrong term intake voucher #999',
            ])
            ->assertNotified('Registration not started');

        $this->assertDatabaseMissing('enrollments', [
            'admission_application_id' => $application->id,
            'term_id' => $termB->id,
        ]);
    }

    public function test_domain_service_rejects_wrong_term_for_ready_applicant(): void
    {
        $registrar = $this->createRegistrar();
        $termA = $this->createTermWithOpenEnrollmentWindow('Term A');
        $termB = $this->createTermWithOpenEnrollmentWindow('Term B');
        $application = $this->createReadyApplicant($termA);

        $this->expectException(ValidationException::class);

        app(StartRegistrationCase::class)->forReadyApplicant(
            $application,
            $termB,
            $registrar,
            'RegistrarAssisted',
            'Intake desk',
        );
    }

    public function test_starting_case_rejects_ineligible_applicant(): void
    {
        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);

        // Make applicant ineligible by dropping the admitted state
        $application->update(['application_state' => AdmissionApplication::StateSubmitted]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->callAction('startReadyApplicantRegistration', [
                'application_id' => $application->id,
                'term_id' => $term->id,
                'authority_reference' => 'Ineligible test voucher',
            ])
            ->assertHasActionErrors(['application_id']);

        $this->assertDatabaseMissing('enrollments', [
            'admission_application_id' => $application->id,
        ]);
    }

    public function test_domain_service_rejects_ineligible_applicant(): void
    {
        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);

        $application->update(['application_state' => AdmissionApplication::StateSubmitted]);

        $this->expectException(ValidationException::class);

        app(StartRegistrationCase::class)->forReadyApplicant(
            $application,
            $term,
            $registrar,
            'RegistrarAssisted',
            'Ineligible domain test voucher',
        );
    }

    public function test_stale_request_rejected_when_applicant_readiness_is_revoked_after_modal_mount(): void
    {
        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $testable = Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->mountAction('startReadyApplicantRegistration', [
                'application_id' => $application->id,
                'term_id' => $term->id,
            ])
            ->setActionData([
                'application_id' => $application->id,
                'term_id' => $term->id,
                'authority_reference' => 'Stale verification intake voucher #STALE-1',
            ]);

        // Stale transition: Registrar clearance is revoked after staff mounted the action.
        $priorClearance = $application->enrollmentClearances()->whereDoesntHave('successor')->sole();
        RegistrarEnrollmentClearance::factory()->forAdmittedApplication($application)->create([
            'supersedes_clearance_id' => $priorClearance->id,
            'recorded_by' => $registrar->id,
            'reason' => 'External school checks require further review.',
        ]);

        $testable->callMountedAction()
            ->assertHasActionErrors(['application_id']);

        $this->assertDatabaseMissing('enrollments', [
            'admission_application_id' => $application->id,
            'term_id' => $term->id,
        ]);
    }

    public function test_starting_case_rejects_closed_enrollment_window(): void
    {
        $registrar = $this->createRegistrar();
        $term = Term::factory()->create(['state' => Term::StateActive]);
        // Calendar window closed
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);
        TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => now()->subDays(10)->toDateString(),
            'closes_on' => now()->subDays(2)->toDateString(),
        ]);

        $application = $this->createReadyApplicant($term);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->callAction('startReadyApplicantRegistration', [
                'application_id' => $application->id,
                'term_id' => $term->id,
                'authority_reference' => 'Closed window voucher',
            ])
            ->assertNotified('Registration not started');

        $this->assertDatabaseMissing('enrollments', [
            'admission_application_id' => $application->id,
        ]);
    }

    public function test_routine_assisted_start_accepts_no_intake_reference_and_records_the_actor(): void
    {
        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->callAction('startReadyApplicantRegistration', [
                'application_id' => $application->id,
                'term_id' => $term->id,
                'authority_reference' => '',
            ])
            ->assertHasNoActionErrors();

        $case = Enrollment::query()->where('admission_application_id', $application->id)->sole();
        $this->assertSame($registrar->id, $case->started_by);
        $this->assertNull($case->registrationEvents()->where('event_type', 'Started')->sole()->authority_reference);
    }

    public function test_unauthorized_user_cannot_start_assisted_registration(): void
    {
        $accountingUser = User::factory()->create(['status' => User::StatusActive]);
        $accountingUser->assignRole(User::StaffRoleAccounting);

        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($accountingUser)
            ->test(ListEnrollments::class)
            ->assertActionHidden('startReadyApplicantRegistration')
            ->call('mountAction', 'startReadyApplicantRegistration', [
                'application_id' => $application->id,
                'term_id' => $term->id,
                'authority_reference' => 'Unauthorized attempt',
            ])
            ->assertActionNotMounted('startReadyApplicantRegistration');

        $this->assertDatabaseMissing('enrollments', ['started_by' => $accountingUser->id]);
    }

    public function test_domain_service_rejects_non_registrar_assisted_start(): void
    {
        $accountingUser = User::factory()->create(['status' => User::StatusActive]);
        $accountingUser->assignRole(User::StaffRoleAccounting);

        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);

        $this->expectException(AuthorizationException::class);

        app(StartRegistrationCase::class)->forReadyApplicant(
            $application,
            $term,
            $accountingUser,
            'RegistrarAssisted',
            'Unauthorized staff attempt',
        );
    }

    public function test_starting_case_does_not_create_student_profile_seat_assessment_or_cor(): void
    {
        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);

        $initialProfiles = StudentProfile::count();
        $initialSeats = EnrollmentSeatReservation::count();
        $initialAssessments = Assessment::count();
        $initialCors = CorVersion::count();

        $enrollment = app(StartRegistrationCase::class)->forReadyApplicant(
            $application,
            $term,
            $registrar,
            'RegistrarAssisted',
            'Strict identity boundary test',
        );

        $this->assertNull($enrollment->student_profile_id);
        $this->assertSame($initialProfiles, StudentProfile::count());
        $this->assertSame($initialSeats, EnrollmentSeatReservation::count());
        $this->assertSame($initialAssessments, Assessment::count());
        $this->assertSame($initialCors, CorVersion::count());
    }

    public function test_ordinary_continuing_start_cannot_bypass_dates_or_audience_and_late_start_requires_its_own_approval(): void
    {
        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();
        $term->update(['registration_applicability' => 'ReadyApplicants']);
        $profile = StudentProfile::factory()->create(['academic_standing' => StudentProfile::StandingRegular]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $data = ['student_profile_id' => $profile->id, 'term_id' => $term->id];
        $forged = [...$data, 'start_method' => 'LateAuthority', 'authority_reference' => 'UNREQUESTED-EXCEPTION'];

        Livewire::actingAs($registrar)->test(ListEnrollments::class)
            ->callAction('startContinuingEnrollment', $forged)
            ->assertNotified('Enrollment not started');
        $this->assertDatabaseMissing('enrollments', ['student_profile_id' => $profile->id, 'term_id' => $term->id]);

        $term->update(['registration_applicability' => 'AllEligible']);
        TermCalendarWindow::query()->whereHas('package', fn (Builder $query): Builder => $query->where('term_id', $term->id))
            ->where('window_type', TermCalendarWindow::TypeEnrollment)
            ->update(['opens_on' => now()->subDays(10)->toDateString(), 'closes_on' => now()->subDays(2)->toDateString()]);
        Livewire::actingAs($registrar)->test(ListEnrollments::class)
            ->callAction('startContinuingEnrollment', $forged)
            ->assertNotified('Enrollment not started');
        $this->assertDatabaseMissing('enrollments', ['student_profile_id' => $profile->id, 'term_id' => $term->id]);

        $late = Livewire::actingAs($registrar)->test(ListEnrollments::class)
            ->mountAction('startLateContinuingEnrollment')
            ->assertMountedActionModalSee('Late enrollment approval reference')
            ->assertMountedActionModalDontSee('Enrollment timing')
            ->setActionData([...$data, 'start_method' => 'RegistrarAssisted', 'authority_reference' => ''])
            ->callMountedAction()->assertHasActionErrors(['authority_reference' => 'required']);
        $this->assertDatabaseMissing('enrollments', ['student_profile_id' => $profile->id, 'term_id' => $term->id]);

        $late->setActionData([...$data, 'start_method' => 'RegistrarAssisted', 'authority_reference' => 'EXISTING-LATE-APPROVAL'])
            ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Enrollment started');
        $case = Enrollment::query()->where('student_profile_id', $profile->id)->where('term_id', $term->id)->sole();
        $this->assertSame('LateAuthority', $case->start_method);
        $event = $case->registrationEvents()->where('event_type', 'Started')->sole();
        $this->assertSame('EXISTING-LATE-APPROVAL', $event->authority_reference);
        $this->assertSame($registrar->id, $event->actor_id);
        $this->assertNotNull($event->recorded_at);

        Livewire::actingAs($registrar)->test(ListEnrollments::class)
            ->callAction('startLateContinuingEnrollment', [...$data, 'authority_reference' => 'EXISTING-LATE-APPROVAL'])
            ->assertHasNoActionErrors()->assertNotified('Existing enrollment opened');
        $this->assertSame(1, Enrollment::query()->where('student_profile_id', $profile->id)->where('term_id', $term->id)->count());
        $this->assertSame(1, $case->registrationEvents()->where('event_type', 'Started')->count());
    }

    public function test_applicant_self_service_and_continuing_student_starts_remain_intact(): void
    {
        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);
        $applicantUser = $application->user;

        // 1. Applicant self-service start
        $applicantEnrollment = app(StartRegistrationCase::class)->forReadyApplicant(
            $application,
            $term,
            $applicantUser,
            'SelfService',
        );

        $this->assertNotNull($applicantEnrollment);
        $this->assertSame('SelfService', $applicantEnrollment->start_method);
        $this->assertSame($applicantUser->id, $applicantEnrollment->started_by);

        // 2. Continuing student start by registrar
        $registrar = $this->createRegistrar();
        $studentUser = User::factory()->create(['status' => User::StatusActive]);
        $studentUser->assignRole('student');
        $profile = StudentProfile::factory()->create([
            'user_id' => $studentUser->id,
            'academic_standing' => StudentProfile::StandingRegular,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $data = [
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'authority_reference' => '',
        ];
        $component = Livewire::actingAs($registrar)->test(ListEnrollments::class)
            ->mountAction('startContinuingEnrollment')
            ->assertMountedActionModalDontSee('Enrollment timing')
            ->assertMountedActionModalDontSee('Late enrollment approval reference')
            ->assertMountedActionModalDontSee('Intake reference');
        $studentSelect = $component->instance()->getSchema($component->instance()->getMountedActionSchemaName())
            ->getComponent('student_profile_id');
        $this->assertArrayHasKey($profile->id, $studentSelect->getSearchResults($profile->student_number));

        $component->setActionData([...$data, 'start_method' => 'LateAuthority', 'authority_reference' => 'UNREQUESTED-EXCEPTION'])
            ->callMountedAction()->assertHasNoActionErrors();
        $continuingEnrollment = Enrollment::query()->where('student_profile_id', $profile->id)->where('term_id', $term->id)->first();

        $this->assertNotNull($continuingEnrollment);
        $this->assertSame('RegistrarAssisted', $continuingEnrollment->start_method);
        $this->assertSame($profile->id, $continuingEnrollment->student_profile_id);
        $this->assertNull($continuingEnrollment->registrationEvents()->where('event_type', 'Started')->sole()->authority_reference);
        $component->assertRedirect(EnrollmentResource::getUrl('view', ['record' => $continuingEnrollment]));

        Livewire::actingAs($registrar)->test(ListEnrollments::class)
            ->callAction('startContinuingEnrollment', $data)
            ->assertHasNoActionErrors()
            ->assertRedirect(EnrollmentResource::getUrl('view', ['record' => $continuingEnrollment]));
        $this->assertSame(1, Enrollment::query()->where('student_profile_id', $profile->id)->where('term_id', $term->id)->count());
    }

    public function test_selected_ready_applicant_opens_with_read_only_context_and_prefilled_arguments(): void
    {
        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->set('activeTab', 'ready_applicants')
            ->call('startRegistrationForApplicant', $application->id, $term->id)
            ->assertActionMounted('startReadyApplicantRegistration')
            ->assertActionDataSet([
                'application_id' => $application->id,
                'term_id' => $term->id,
            ]);

        $schema = $component->instance()->getSchema($component->instance()->getMountedActionSchemaName());
        $this->assertTrue($schema->getComponent('application_id', withHidden: true)->isHidden());
        $this->assertInstanceOf(Hidden::class, $schema->getComponent('term_id', withHidden: true));
        $modal = $component->getMountedActionModalHtml();
        $this->assertStringContainsString($application->application_reference, $modal);
        $this->assertStringContainsString($application->program->name, $modal);
        $this->assertStringContainsString($term->label, $modal);
        $this->assertStringNotContainsString('Intake reference', $modal);

    }

    public function test_ready_applicants_page_excludes_started_official_and_closed_enrollments(): void
    {
        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();

        // Applicant 1: Ready, no case yet
        $appWithoutCase = $this->createReadyApplicant($term);

        // Applicant 2: InProgress case
        $appWithActiveCase = $this->createReadyApplicant($term);
        $activeCase = app(StartRegistrationCase::class)->forReadyApplicant(
            $appWithActiveCase,
            $term,
            $registrar,
            'RegistrarAssisted',
            'Intake-001',
        );

        // Applicant 3: Officially enrolled case
        $appWithEnrolledCase = $this->createReadyApplicant($term);
        $enrolledCase = app(StartRegistrationCase::class)->forReadyApplicant(
            $appWithEnrolledCase,
            $term,
            $registrar,
            'RegistrarAssisted',
            'Intake-002',
        );
        $enrolledCase->update([
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => now(),
        ]);

        // Applicant 4: Cancelled case
        $appWithCancelledCase = $this->createReadyApplicant($term);
        $cancelledCase = app(StartRegistrationCase::class)->forReadyApplicant(
            $appWithCancelledCase,
            $term,
            $registrar,
            'RegistrarAssisted',
            'Intake-003',
        );
        $cancelledCase->update([
            'canonical_outcome' => Enrollment::OutcomeCancelledByLearner,
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->set('activeTab', 'ready_applicants')
            ->assertSee($appWithoutCase->application_reference)
            ->assertSee('Start enrollment')
            ->assertSee('Find an enrollment')
            ->assertDontSee($appWithActiveCase->application_reference)
            ->assertDontSee($appWithEnrolledCase->application_reference)
            ->assertDontSee($appWithCancelledCase->application_reference);

        $this->assertSame([$appWithoutCase->id], $component->instance()->readyApplicantListData()['applications']->pluck('id')->all());
    }

    public function test_existing_case_is_accessible_when_window_closed_while_new_intake_fails_closed(): void
    {
        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);

        // Start existing case while window is open
        $existingEnrollment = app(StartRegistrationCase::class)->forReadyApplicant(
            $application,
            $term,
            $registrar,
            'RegistrarAssisted',
            'Intake desk',
        );

        // Close the enrollment window
        TermCalendarWindow::query()
            ->where('window_type', TermCalendarWindow::TypeEnrollment)
            ->update([
                'opens_on' => now()->addDays(10)->toDateString(),
                'closes_on' => now()->addDays(20)->toDateString(),
            ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // 1. Attempting to start a NEW registration case for another applicant fails closed due to calendar gate
        $anotherApp = $this->createReadyApplicant($term);
        Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->callAction('startReadyApplicantRegistration', [
                'application_id' => $anotherApp->id,
                'term_id' => $term->id,
                'authority_reference' => 'Attempt while window closed',
            ])
            ->assertNotified('Registration not started');

        $this->assertDatabaseMissing('enrollments', [
            'admission_application_id' => $anotherApp->id,
        ]);

        // 2. Existing case view access on REG-E02 remains fully accessible and authorized without window check
        Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $existingEnrollment->getRouteKey()])
            ->assertSuccessful();
    }

    public function test_unexpected_exception_is_reported_safely_without_leaking_internal_details(): void
    {
        Exceptions::fake();

        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);

        $injectedSecret = 'Sensitive DB connection string: mysql://secret@internal-cluster:3306';

        $mockService = \Mockery::mock(StartRegistrationCase::class);
        $mockService->shouldReceive('forReadyApplicant')
            ->once()
            ->andThrow(new \RuntimeException($injectedSecret));
        $this->app->instance(StartRegistrationCase::class, $mockService);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $test = Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->callAction('startReadyApplicantRegistration', [
                'application_id' => $application->id,
                'term_id' => $term->id,
                'authority_reference' => 'Valid authority reference',
            ]);

        // 1. Verify user-facing notification in session before assertNotified pulls it
        $notifications = session('filament.claimed_notifications') ?? session('filament.notifications') ?? [];
        $this->assertNotEmpty($notifications);
        $notification = collect($notifications)->firstWhere('title', 'Registration not started');
        $this->assertNotNull($notification);
        $this->assertSame(
            'An unexpected error occurred while starting registration. Please try again or contact system support.',
            $notification['body']
        );
        $this->assertStringNotContainsString($injectedSecret, json_encode($notifications));

        // 2. Filament notification assertion
        $test->assertNotified('Registration not started');

        // 3. Verify the unexpected exception was reported via Laravel exception handling
        Exceptions::assertReported(fn (\RuntimeException $e): bool => $e->getMessage() === $injectedSecret);
    }

    public function test_authorized_to_view_non_registrar_cannot_invoke_assisted_registration_or_access_ready_applicants(): void
    {
        $accountingUser = User::factory()->create(['status' => User::StatusActive]);
        $accountingUser->assignRole(User::StaffRoleAccounting);

        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // 1. For Accounting user, assisted registration actions do not exist in their surface
        Livewire::actingAs($accountingUser)
            ->test(ListEnrollments::class)
            ->assertActionHidden('startReadyApplicantRegistration')
            ->assertActionHidden('readyApplicants')
            ->assertActionHidden('startContinuingEnrollment')
            ->call('mountAction', 'startContinuingEnrollment')
            ->assertActionNotMounted('startContinuingEnrollment')
            ->assertActionHidden('startLateContinuingEnrollment')
            ->call('mountAction', 'startLateContinuingEnrollment')
            ->assertActionNotMounted('startLateContinuingEnrollment');

        // 2. Direct invocation of startRegistrationForApplicant fails server-side with 403
        Livewire::actingAs($accountingUser)
            ->test(ListEnrollments::class)
            ->call('startRegistrationForApplicant', $application->id, $term->id)
            ->assertForbidden();
    }

    public function test_existing_exact_term_case_opens_without_open_window_and_is_filtered_from_start_selector(): void
    {
        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();
        $applicationWithCase = $this->createReadyApplicant($term);
        $applicationWithoutCase = $this->createReadyApplicant($term);

        // Start existing case while window is open
        $existingEnrollment = app(StartRegistrationCase::class)->forReadyApplicant(
            $applicationWithCase,
            $term,
            $registrar,
            'RegistrarAssisted',
            'Intake desk reference',
        );

        // Close the enrollment window
        TermCalendarWindow::query()
            ->where('window_type', TermCalendarWindow::TypeEnrollment)
            ->update([
                'opens_on' => now()->addDays(10)->toDateString(),
                'closes_on' => now()->addDays(20)->toDateString(),
            ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // 1. Selector removes applicant with existing case, while applicant without case is available
        $component = Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->mountAction('startReadyApplicantRegistration');

        $schemaName = $component->instance()->getMountedActionSchemaName();
        $schema = $component->instance()->getSchema($schemaName);
        $options = $schema->getComponent('application_id')->getOptions();

        $this->assertArrayNotHasKey($applicationWithCase->id, $options);
        $this->assertArrayHasKey($applicationWithoutCase->id, $options);

        // 2. When window is closed, existing case UI route (REG-E02 ViewEnrollment) remains open and accessible
        Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $existingEnrollment->getRouteKey()])
            ->assertSuccessful();
    }

    public function test_case_matching_is_strictly_exact_term(): void
    {
        $registrar = $this->createRegistrar();
        $term1 = $this->createTermWithOpenEnrollmentWindow('Term 1');
        $term2 = $this->createTermWithOpenEnrollmentWindow('Term 2');

        $application = $this->createReadyApplicant($term1);

        // Create an enrollment for this applicant in a DIFFERENT term (Term 2)
        Enrollment::query()->create([
            'credential_user_id' => $application->user_id,
            'admission_application_id' => null,
            'term_id' => $term2->id,
            'case_reference' => 'REG-TERM2-001',
            'selection_basis' => 'ApplicantAdmissionProjection',
            'canonical_outcome' => Enrollment::OutcomeInProgress,
            'status' => 'pending_review',
            'started_by' => $registrar->id,
            'start_method' => 'RegistrarAssisted',
            'started_at' => now(),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($registrar)->test(ListEnrollments::class)
            ->set('activeTab', 'ready_applicants')
            ->assertSee($application->application_reference)
            ->assertDontSee('REG-TERM2-001');
        $this->assertTrue($component->instance()->readyApplicantListData()['applications']->contains('id', $application->id));
    }

    public function test_ready_applicant_search_resets_pagination_and_handles_no_matches(): void
    {
        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();
        $applications = collect(range(1, 11))
            ->map(fn (): AdmissionApplication => $this->createReadyApplicant($term))
            ->sortBy([['last_name', 'asc'], ['first_name', 'asc'], ['id', 'asc']])
            ->values();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($registrar)->test(ListEnrollments::class)
            ->set('activeTab', 'ready_applicants');
        $firstPage = $component->instance()->readyApplicantListData()['applications'];
        $this->assertSame(11, $firstPage->total());
        $this->assertCount(10, $firstPage);

        $component->call('setPage', 2, 'readyApplicantsPage');
        $this->assertSame([$applications->last()->id], $component->instance()->readyApplicantListData()['applications']->pluck('id')->all());
        $component->set('readyApplicantSearch', mb_strtolower($applications->first()->application_reference));
        $results = $component->instance()->readyApplicantListData()['applications'];
        $this->assertSame(1, $results->currentPage());
        $this->assertSame([$applications->first()->id], $results->pluck('id')->all());
        $component->set('readyApplicantSearch', 'no-such-ready-applicant')->assertSee('No matching applicants');
        $this->assertSame(0, $component->instance()->readyApplicantListData()['applications']->total());
    }

    public function test_selected_ready_applicant_cannot_be_replaced_through_form_data(): void
    {
        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();
        $selected = $this->createReadyApplicant($term);
        $other = $this->createReadyApplicant($term);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)->test(ListEnrollments::class)
            ->call('startRegistrationForApplicant', $selected->id, $term->id)
            ->setActionData(['application_id' => $other->id, 'term_id' => $term->id, 'authority_reference' => null])
            ->callMountedAction()
            ->assertNotified('Registration not started');

        $this->assertDatabaseMissing('enrollments', ['admission_application_id' => $selected->id]);
        $this->assertDatabaseMissing('enrollments', ['admission_application_id' => $other->id]);
    }

    private function createRegistrar(): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole(User::StaffRoleRegistrar);

        return $user;
    }

    private function createTermWithOpenEnrollmentWindow(?string $label = null): Term
    {
        static $termSequence = 3000;
        $termSequence++;

        $academicYear = AcademicYear::query()->firstOrCreate(
            ['label' => "AY-{$termSequence}-".($termSequence + 1)],
            [
                'starts_on' => "{$termSequence}-08-01",
                'ends_on' => ($termSequence + 1).'-05-31',
                'state' => AcademicYear::StateActive,
            ]
        );

        $term = Term::factory()->for($academicYear)->create([
            'label' => ($label ?? 'Academic Year 2026-2027 1st Semester')." #{$termSequence}",
            'state' => Term::StateActive,
        ]);

        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);

        TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addDays(5)->toDateString(),
        ]);

        return $term;
    }

    private function createReadyApplicant(Term $term): AdmissionApplication
    {
        $cycle = AdmissionCycle::factory()->for($term)->create();
        $requirementSet = AdmissionRequirementSet::factory()->for($cycle)->create([
            'state' => AdmissionRequirementSet::StateDraft,
        ]);

        $requirement = AdmissionRequirement::factory()->create([
            'admission_requirement_set_id' => $requirementSet->id,
            'due_stage' => AdmissionRequirement::DueEnrollmentReadiness,
        ]);

        $requirementSet->update([
            'state' => AdmissionRequirementSet::StatePublished,
            'effective_at' => now(),
            'published_at' => now(),
        ]);

        $applicant = User::factory()->create(['status' => User::StatusActive]);
        $applicant->assignRole('applicant');
        $program = Program::factory()->create();

        $application = AdmissionApplication::factory()->create([
            'user_id' => $applicant->id,
            'admission_cycle_id' => $cycle->id,
            'term_id' => $term->id,
            'program_id' => $program->id,
            'application_state' => AdmissionApplication::StateAdmitted,
            'application_path' => AdmissionApplication::PathFirstYear,
        ]);

        $version = ApplicationSubmissionVersion::factory()->create([
            'admission_application_id' => $application->id,
            'admission_requirement_set_id' => $requirementSet->id,
        ]);

        $application->update(['current_submission_version_id' => $version->id]);

        $decision = AdmissionDecision::factory()->create([
            'admission_application_id' => $application->id,
            'application_submission_version_id' => $version->id,
            'decision' => AdmissionDecision::DecisionAdmitted,
        ]);
        RegistrarEnrollmentClearance::factory()->forAdmittedApplication($application)->create([
            'result' => RegistrarEnrollmentClearance::ResultCleared,
            'external_checks_confirmed' => true,
            'recorded_by' => $decision->decided_by,
        ]);

        OfficialCredentialResult::factory()->verified()->create([
            'admission_application_id' => $application->id,
            'admission_requirement_id' => $requirement->id,
        ]);

        return $application->fresh();
    }
}
