<?php

namespace Tests\Feature;

use App\Actions\AcademicSetup\CurriculumVersionLifecycleService;
use App\Actions\Enrollment\PlaceRegistrationProposal;
use App\Actions\Enrollment\PrepareRegistrationProposal;
use App\Actions\Enrollment\PrepareStandardRegistration;
use App\Actions\Enrollment\RecordRegistrationCreditEvaluation;
use App\Actions\Enrollment\StartRegistrationCase;
use App\Actions\Scheduling\TermCoverageProjection;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Models\AdmissionApplication;
use App\Models\ApplicationSubmissionVersion;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\FinancialAccommodation;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRosterRow;
use App\Models\Hold;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationProposalVersion;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL67EnrollmentPlacementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_standard_preparation_keeps_multi_year_offerings_in_the_earliest_unsatisfied_curriculum_position(): void
    {
        $profile = StudentProfile::factory()->create();
        $term = Term::factory()->create(['type' => Term::TypeFirstSemester, 'state' => Term::StateActive]);
        $case = Enrollment::factory()->for($profile)->for($term)->create();
        $timetable = PublishedTimetableVersion::factory()->for($term)->create();
        $sections = [];
        foreach (['First Year', 'Second Year'] as $index => $year) {
            $entry = CurriculumEntry::factory()->for($profile->curriculumVersion)->create([
                'year_level' => $year, 'term_type' => $term->type, 'sequence' => $index + 1,
            ]);
            $offering = TermOffering::factory()->for($term)->for($entry)->create(['state' => TermOffering::StateScheduled]);
            $section = Section::factory()->for($offering, 'termOffering')->create(['state' => Section::StateOpen]);
            PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create(['section_id' => $section->id]);
            $sections[] = $section;
        }

        $this->assertSame([$sections[0]->id], app(PrepareStandardRegistration::class)->sectionIds($case));
        $timetable->update(['state' => PublishedTimetableVersion::StateSuperseded]);
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        try {
            app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$sections[0]->id], $case->lock_version);
            $this->fail('A superseded timetable must not substitute for a current published source.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('timetable', $exception->errors());
            $this->assertSame(0, $case->proposalVersions()->count());
        }

        $this->assertSame(0, $case->courseEnrollments()->count());
        $this->assertSame(0, $case->seatReservations()->count());
    }

    public function test_non_active_lifecycle_blocks_registration_before_schedule_and_seat_effects(): void
    {
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        foreach ([StudentProfile::LifecycleCompleted, StudentProfile::LifecycleWithdrawn, StudentProfile::LifecycleInactive] as $lifecycle) {
            $profile = StudentProfile::factory()->create(['lifecycle_status' => $lifecycle]);
            try {
                app(StartRegistrationCase::class)->forContinuingStudent($profile, $term, $registrar, 'RegistrarAssisted', 'SYN-LIFECYCLE-CHECK');
                $this->fail('A blocked Student lifecycle must not create a Registration Case.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('student_profile', $exception->errors());
            }
            $this->assertSame(0, Enrollment::query()->where('credential_user_id', $profile->user_id)->count());
        }
    }

    public function test_legacy_placement_action_is_retired_from_the_canonical_registration_surface(): void
    {
        $case = Enrollment::factory()->create();
        foreach ([User::StaffRoleRegistrar, User::StaffRoleAcademicHead] as $role) {
            Role::findOrCreate($role, 'web');
            $actor = User::factory()->create(['status' => User::StatusActive]);
            $actor->assignRole($role);
            $actions = collect(Livewire::actingAs($actor)->test(ViewEnrollment::class, ['record' => $case->getRouteKey()])
                ->instance()->getCachedHeaderActions())
                ->flatMap(fn ($action): array => $action instanceof ActionGroup ? $action->getFlatActions() : [$action->getName() => $action]);
            $this->assertArrayNotHasKey('confirmPlacement', $actions->all());
        }
    }

    public function test_continuing_student_assigned_to_superseded_curriculum_can_enroll_after_successor_activation(): void
    {
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        $profile = StudentProfile::factory()->create();
        $priorCurriculum = $profile->curriculumVersion;
        $priorCurriculum->update(['state' => CurriculumVersion::StateActive]);

        $term = Term::factory()->create(['type' => Term::TypeFirstSemester, 'state' => Term::StateActive]);
        $entry = CurriculumEntry::factory()->for($priorCurriculum)->create([
            'year_level' => 'First Year',
            'term_label' => 'First Semester',
            'term_type' => $term->type,
            'sequence' => 1,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry)->create(['state' => TermOffering::StateScheduled]);
        $section = Section::factory()->for($offering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 30]);
        $timetable = PublishedTimetableVersion::factory()->for($term)->create(['state' => PublishedTimetableVersion::StatePublished]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create(['section_id' => $section->id]);

        $readySpec = CourseSpecification::factory()->create([
            'state' => CourseSpecification::StateActive,
            'credit_units' => 3,
            'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
            'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
        ]);
        CourseComponent::factory()->for($readySpec)->create([
            'component_type' => CourseComponent::TypeLecture,
            'meeting_pattern' => '2x90',
            'weekly_contact_hours' => 3,
        ]);
        $successorDraft = CurriculumVersion::factory()->create([
            'program_id' => $profile->program_id,
            'state' => CurriculumVersion::StateDraft,
        ]);
        CurriculumEntry::factory()->for($successorDraft)->for($readySpec)->create([
            'year_level' => '1',
            'term_label' => 'First Semester',
            'term_type' => $term->type,
        ]);

        $impact = app(CurriculumVersionLifecycleService::class)->activationImpact($successorDraft);
        $this->assertTrue($impact['has_active_term_setup']);

        app(CurriculumVersionLifecycleService::class)->activate($registrar, $successorDraft);
        $this->assertSame(CurriculumVersion::StateSuperseded, $priorCurriculum->fresh()->state);

        $coverage = app(TermCoverageProjection::class)->forTerm($term);
        $this->assertNotNull(collect($coverage['rows'])->firstWhere('curriculum_version_id', $priorCurriculum->id));

        $case = Enrollment::factory()->for($profile)->for($term)->create([
            'selection_basis' => Enrollment::SelectionStandardCurriculum,
        ]);

        $this->assertSame([$section->id], app(PrepareStandardRegistration::class)->sectionIds($case));
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        $this->assertSame($priorCurriculum->id, $proposal->curriculum_version_id);
    }

    public function test_individually_advised_irregular_student_can_combine_cross_year_regular_offerings_with_explicit_load_position(): void
    {
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        $profile = StudentProfile::factory()->create();
        $term = Term::factory()->create(['type' => Term::TypeFirstSemester, 'state' => Term::StateActive]);
        $package = TermCalendarPackage::factory()->for($term)->create(['state' => TermCalendarPackage::StateActive]);
        TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addDays(7)->toDateString(),
        ]);
        $timetable = PublishedTimetableVersion::factory()->for($term)->create(['state' => PublishedTimetableVersion::StatePublished]);

        $year1Entry = CurriculumEntry::factory()->for($profile->curriculumVersion)->create([
            'course_specification_id' => CourseSpecification::factory()->create(['title' => 'Foundations I', 'credit_units' => 3])->id,
            'year_level' => 'First Year',
            'term_label' => 'First Semester',
            'term_type' => $term->type,
            'sequence' => 1,
        ]);
        $year2EntryA = CurriculumEntry::factory()->for($profile->curriculumVersion)->create([
            'course_specification_id' => CourseSpecification::factory()->create(['title' => 'Systems Analysis', 'credit_units' => 3])->id,
            'year_level' => 'Second Year',
            'term_label' => 'First Semester',
            'term_type' => $term->type,
            'sequence' => 1,
        ]);
        CurriculumEntry::factory()->for($profile->curriculumVersion)->create([
            'course_specification_id' => CourseSpecification::factory()->create(['title' => 'Database Systems', 'credit_units' => 3])->id,
            'year_level' => 'Second Year',
            'term_label' => 'First Semester',
            'term_type' => $term->type,
            'sequence' => 2,
        ]);

        $sectionY1 = Section::factory()->for(
            TermOffering::factory()->for($term)->for($year1Entry)->create([
                'category' => TermOffering::CategoryRegular,
                'state' => TermOffering::StateScheduled,
            ]),
            'termOffering',
        )->create(['code' => 'BSIS-1A', 'state' => Section::StateOpen, 'capacity' => 25]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $sectionY1->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:30:00',
        ]);

        $sectionY2 = Section::factory()->for(
            TermOffering::factory()->for($term)->for($year2EntryA)->create([
                'category' => TermOffering::CategoryRegular,
                'state' => TermOffering::StateScheduled,
            ]),
            'termOffering',
        )->create(['code' => 'BSIS-2A', 'state' => Section::StateOpen, 'capacity' => 25]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $sectionY2->id,
            'day_of_week' => 2,
            'starts_at' => '10:00:00',
            'ends_at' => '11:30:00',
        ]);

        $case = Enrollment::factory()->for($profile)->for($term)->create([
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $case->getRouteKey()])
            ->mountAction('prepareProposal')
            ->assertMountedActionModalSee('First Year, First Semester')
            ->assertMountedActionModalSee('Second Year, First Semester')
            ->assertMountedActionModalSee('25 seats left');

        $positionKey = 'Second Year|First Semester|'.$term->type;
        $proposal = app(PrepareRegistrationProposal::class)->execute(
            $case,
            $registrar,
            [$sectionY1->id, $sectionY2->id],
            $case->lock_version,
            curriculumPosition: $positionKey,
        );
        $placed = app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);

        $this->assertSame('Second Year', data_get($placed->source_snapshot, 'unit_load.year_level'));
        $this->assertSame('6.00', data_get($placed->source_snapshot, 'unit_load.normal_total'));
        $this->assertSame('6.00', data_get($placed->source_snapshot, 'unit_load.requested_total'));
        $this->assertSame(2, $case->seatReservations()->count());
    }

    public function test_active_blocking_enrollment_hold_blocks_registration_unless_overridden_by_financial_accommodation(): void
    {
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        $profile = StudentProfile::factory()->create();
        $term = Term::factory()->create(['type' => Term::TypeFirstSemester, 'state' => Term::StateActive]);
        $package = TermCalendarPackage::factory()->for($term)->create(['state' => TermCalendarPackage::StateActive]);
        TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addDays(7)->toDateString(),
        ]);

        $entry = CurriculumEntry::factory()->for($profile->curriculumVersion)->create([
            'year_level' => 'First Year',
            'term_label' => 'First Semester',
            'term_type' => $term->type,
            'sequence' => 1,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry)->create(['state' => TermOffering::StateScheduled]);
        $section = Section::factory()->for($offering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 30]);
        $timetable = PublishedTimetableVersion::factory()->for($term)->create(['state' => PublishedTimetableVersion::StatePublished]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create(['section_id' => $section->id]);

        $hold = Hold::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'enrollment_id' => null,
            'hold_type' => Hold::TypeFinancial,
            'blocking_level' => Hold::BlockingEnrollment,
            'status' => Hold::StatusActive,
            'reason' => 'Unsettled prior-term tuition balance.',
            'resolution_requirement' => 'Settle the verified balance or obtain an Accounting accommodation.',
        ]);

        try {
            app(StartRegistrationCase::class)->forContinuingStudent($profile, $term, $registrar, 'RegistrarAssisted', 'HOLD-TEST-1');
            $this->fail('An active un-accommodated BlockingEnrollment hold must block starting a Registration Case.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('hold', $exception->errors());
            $message = implode(' ', $exception->errors()['hold']);
            $this->assertStringContainsString('Unsettled prior-term tuition balance.', $message);
            $this->assertStringContainsString('Settle the verified balance or obtain an Accounting accommodation.', $message);
        }
        $this->assertSame(0, Enrollment::query()->where('student_profile_id', $profile->id)->where('term_id', $term->id)->count());

        $existingCase = Enrollment::factory()->for($profile)->for($term)->create([
            'selection_basis' => Enrollment::SelectionStandardCurriculum,
        ]);
        $proposal = app(PrepareRegistrationProposal::class)->execute($existingCase, $registrar, [$section->id], $existingCase->lock_version);

        try {
            app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
            $this->fail('An active un-accommodated BlockingEnrollment hold must block seat placement.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('hold', $exception->errors());
            $this->assertStringContainsString('Unsettled prior-term tuition balance.', implode(' ', $exception->errors()['hold']));
        }
        $this->assertSame(0, $existingCase->seatReservations()->count());

        FinancialAccommodation::query()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'balance_snapshot' => 5000,
            'covered_amount' => 5000,
            'basis' => 'Approved installment promissory note',
            'allows_finance_gate' => true,
            'allows_next_term_enrollment' => false,
            'allows_reactivation' => false,
            'allows_record_release' => false,
            'waives_downpayment' => false,
            'authority' => 'Accounting Director',
            'status' => FinancialAccommodation::StatusActive,
            'effective_from' => today(),
            'expires_on' => today()->addMonth(),
        ]);

        $placed = app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
        $this->assertSame(1, $existingCase->seatReservations()->count());
        $this->assertSame($proposal->id, $placed->id);

        $otherProfile = StudentProfile::factory()->create([
            'program_id' => $profile->program_id,
            'curriculum_version_id' => $profile->curriculum_version_id,
        ]);
        Hold::factory()->create([
            'student_profile_id' => $otherProfile->id,
            'term_id' => $term->id,
            'enrollment_id' => null,
            'hold_type' => Hold::TypeFinancial,
            'blocking_level' => Hold::BlockingEnrollment,
            'status' => Hold::StatusActive,
            'reason' => $hold->reason,
            'resolution_requirement' => $hold->resolution_requirement,
        ]);
        FinancialAccommodation::query()->create([
            'student_profile_id' => $otherProfile->id,
            'term_id' => $term->id,
            'balance_snapshot' => 2500,
            'covered_amount' => 2500,
            'basis' => 'Approved term enrollment override',
            'allows_finance_gate' => true,
            'allows_next_term_enrollment' => false,
            'allows_reactivation' => false,
            'allows_record_release' => false,
            'waives_downpayment' => false,
            'authority' => 'Accounting Director',
            'status' => FinancialAccommodation::StatusActive,
            'effective_from' => today(),
            'expires_on' => today()->addMonth(),
        ]);

        $started = app(StartRegistrationCase::class)->forContinuingStudent($otherProfile, $term, $registrar, 'RegistrarAssisted', 'HOLD-OVERRIDE-1');
        $this->assertSame(Enrollment::OutcomeInProgress, $started->canonical_outcome);
    }

    public function test_continuing_regular_student_with_early_credit_in_target_term_transitions_to_individually_advised(): void
    {
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        $profile = StudentProfile::factory()->create();
        $curriculum = $profile->curriculumVersion;
        $summerTerm = Term::factory()->create([
            'type' => Term::TypeSpecialTerm,
            'state' => Term::StateClosed,
            'starts_on' => now()->subMonths(3)->toDateString(),
            'ends_on' => now()->subMonth()->toDateString(),
        ]);
        $term = Term::factory()->create([
            'type' => Term::TypeFirstSemester,
            'state' => Term::StateActive,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addMonths(4)->toDateString(),
        ]);
        $package = TermCalendarPackage::factory()->for($term)->create(['state' => TermCalendarPackage::StateActive]);
        TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addDays(7)->toDateString(),
        ]);

        $earlyEntry = CurriculumEntry::factory()->for($curriculum)->create([
            'year_level' => 'First Year',
            'term_label' => 'First Semester',
            'term_type' => $term->type,
            'sequence' => 1,
        ]);
        $remainingEntry = CurriculumEntry::factory()->for($curriculum)->create([
            'year_level' => 'First Year',
            'term_label' => 'First Semester',
            'term_type' => $term->type,
            'sequence' => 2,
        ]);

        $summerOffering = TermOffering::factory()->for($summerTerm)->for($earlyEntry)->create(['state' => TermOffering::StateScheduled]);
        $summerSection = Section::factory()->for($summerOffering, 'termOffering')->create(['state' => Section::StateOpen]);
        $priorEnrollment = Enrollment::factory()->for($profile)->for($summerTerm)->create([
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'officially_enrolled_at' => now()->subMonths(3),
        ]);
        $priorCourseEnrollment = CourseEnrollment::factory()->create([
            'enrollment_id' => $priorEnrollment->id,
            'term_offering_id' => $summerOffering->id,
            'section_id' => $summerSection->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
        ]);
        $gradeRow = GradeRosterRow::factory()->create([
            'course_enrollment_id' => $priorCourseEnrollment->id,
        ]);
        GradeOutcomeEvent::factory()->create([
            'grade_roster_row_id' => $gradeRow->id,
            'event_type' => GradeOutcomeEvent::TypeInitialRelease,
            'result_code' => '1.75',
            'released_at' => now()->subWeeks(2),
        ]);

        $timetable = PublishedTimetableVersion::factory()->for($term)->create(['state' => PublishedTimetableVersion::StatePublished]);
        $earlyOffering = TermOffering::factory()->for($term)->for($earlyEntry)->create(['state' => TermOffering::StateScheduled]);
        $earlySection = Section::factory()->for($earlyOffering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 25]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $earlySection->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:30:00',
        ]);

        $remainingOffering = TermOffering::factory()->for($term)->for($remainingEntry)->create(['state' => TermOffering::StateScheduled]);
        $remainingSection = Section::factory()->for($remainingOffering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 25]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $remainingSection->id,
            'day_of_week' => 2,
            'starts_at' => '10:00:00',
            'ends_at' => '11:30:00',
        ]);

        $started = app(StartRegistrationCase::class)->forContinuingStudent($profile, $term, $registrar, 'RegistrarAssisted', 'EARLY-CREDIT-START');
        $this->assertSame(Enrollment::SelectionIndividuallyAdvised, $started->selection_basis);

        $proposal = app(PrepareRegistrationProposal::class)->execute($started->fresh(), $registrar, [$remainingSection->id], $started->fresh()->lock_version);
        $placed = app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
        $this->assertSame(Enrollment::SelectionIndividuallyAdvised, $placed->selection_basis);
        $this->assertSame(1, $started->seatReservations()->count());

        $legacyProfile = StudentProfile::factory()->create([
            'program_id' => $profile->program_id,
            'curriculum_version_id' => $curriculum->id,
        ]);
        $legacyPriorEnrollment = Enrollment::factory()->for($legacyProfile)->for($summerTerm)->create([
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'officially_enrolled_at' => now()->subMonths(3),
        ]);
        $legacyCourseEnrollment = CourseEnrollment::factory()->create([
            'enrollment_id' => $legacyPriorEnrollment->id,
            'term_offering_id' => $summerOffering->id,
            'section_id' => $summerSection->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
        ]);
        $legacyGradeRow = GradeRosterRow::factory()->create([
            'course_enrollment_id' => $legacyCourseEnrollment->id,
        ]);
        GradeOutcomeEvent::factory()->create([
            'grade_roster_row_id' => $legacyGradeRow->id,
            'event_type' => GradeOutcomeEvent::TypeInitialRelease,
            'result_code' => '2.00',
            'released_at' => now()->subWeeks(2),
        ]);

        $legacyStandardCase = Enrollment::factory()->for($legacyProfile)->for($term)->create([
            'selection_basis' => Enrollment::SelectionStandardCurriculum,
        ]);
        $legacyProposal = app(PrepareRegistrationProposal::class)->execute(
            $legacyStandardCase,
            $registrar,
            [$remainingSection->id],
            $legacyStandardCase->lock_version,
        );
        app(PlaceRegistrationProposal::class)->execute($legacyProposal->fresh(), $registrar);

        $this->assertSame(Enrollment::SelectionIndividuallyAdvised, $legacyStandardCase->fresh()->selection_basis);
        $this->assertSame(Enrollment::SelectionIndividuallyAdvised, $legacyProposal->fresh()->selection_basis);
        $this->assertSame(1, $legacyStandardCase->seatReservations()->count());
    }

    public function test_credit_evaluation_accepts_superseded_curriculum_and_rejects_draft_curriculum(): void
    {
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        $supersededCurriculum = CurriculumVersion::factory()->create([
            'state' => CurriculumVersion::StateSuperseded,
        ]);
        $draftCurriculum = CurriculumVersion::factory()->create([
            'program_id' => $supersededCurriculum->program_id,
            'state' => CurriculumVersion::StateDraft,
        ]);
        $supersededEntry = CurriculumEntry::factory()->for($supersededCurriculum)->create();
        $draftEntry = CurriculumEntry::factory()->for($draftCurriculum)->create();

        $application = AdmissionApplication::factory()->create([
            'program_id' => $supersededCurriculum->program_id,
            'application_path' => AdmissionApplication::PathTransferee,
        ]);
        $submission = ApplicationSubmissionVersion::factory()->for($application, 'application')->create();
        $application->update(['current_submission_version_id' => $submission->id]);

        $case = Enrollment::factory()->create([
            'credential_user_id' => $application->user_id,
            'admission_application_id' => $application->id,
            'student_profile_id' => null,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
        ]);

        try {
            app(RecordRegistrationCreditEvaluation::class)->execute(
                $case,
                $registrar,
                [$draftEntry->id],
                'EVAL-DRAFT-REJECT',
                CarbonImmutable::today(),
                (int) $submission->id,
                (int) $draftCurriculum->id,
                (int) $case->lock_version,
            );
            $this->fail('Draft curricula must remain blocked for transferee credit evaluation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('evaluation', $exception->errors());
        }

        $evaluation = app(RecordRegistrationCreditEvaluation::class)->execute(
            $case,
            $registrar,
            [$supersededEntry->id],
            'EVAL-SUPERSEDED-OK',
            CarbonImmutable::today(),
            (int) $submission->id,
            (int) $supersededCurriculum->id,
            (int) $case->lock_version,
        );

        $this->assertSame($supersededCurriculum->id, $evaluation->curriculum_version_id);
        $this->assertSame([$supersededEntry->id], $evaluation->credited_curriculum_entry_ids);
    }

    public function test_graduating_overload_authority_action_is_only_visible_for_graduation_candidates(): void
    {
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        $regularProfile = StudentProfile::factory()->create([
            'academic_standing' => StudentProfile::StandingRegular,
        ]);
        $term = Term::factory()->create(['type' => Term::TypeFirstSemester, 'state' => Term::StateActive]);
        $timetable = PublishedTimetableVersion::factory()->for($term)->create(['state' => PublishedTimetableVersion::StatePublished]);
        $case = Enrollment::factory()->for($regularProfile)->for($term)->create([
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
        ]);
        $proposal = RegistrationProposalVersion::query()->create([
            'enrollment_id' => $case->id,
            'version' => 1,
            'state' => RegistrationProposalVersion::StateDraft,
            'purpose' => RegistrationProposalVersion::PurposeInitial,
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
            'published_timetable_version_id' => $timetable->id,
            'curriculum_version_id' => $regularProfile->curriculum_version_id,
            'source_snapshot' => [
                'unit_load' => ['requires_graduating_overload' => true],
            ],
            'content_hash' => hash('sha256', 'overload-visibility-test'),
            'prepared_by' => $registrar->id,
            'prepared_at' => now(),
        ]);
        $case->update(['current_proposal_version_id' => $proposal->id]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $case->getRouteKey()])
            ->assertActionHidden('recordGraduatingOverloadAuthority');

        $regularProfile->update(['academic_standing' => StudentProfile::StandingGraduationCandidate]);

        Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $case->getRouteKey()])
            ->assertActionVisible('recordGraduatingOverloadAuthority');
    }
}
