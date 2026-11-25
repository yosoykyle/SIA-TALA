<?php

namespace Tests\Feature\Enrollment;

use App\Actions\StudentLifecycle\ShiftProgramAndStartEnrollment;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\Enrollments\Pages\ListEnrollments;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Filament\Resources\Enrollments\Schemas\ProgramShiftEnrollmentForm;
use App\Filament\Student\Pages\Enrollment as StudentEnrollmentPage;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\Program;
use App\Models\ProgramShiftCreditEntry;
use App\Models\PublishedTimetableVersion;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ShifteeEnrollmentUiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('testing', app()->environment());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());

        foreach (['student', User::StaffRoleRegistrar, User::StaffRoleAccounting] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        config([
            'institution.address' => 'Synthetic Servitech Campus, Philippines',
            'institution.public.support_phone' => '0947 737 9208',
        ]);
        Mail::fake();
    }

    #[Test]
    public function workbench_shows_shiftee_action_and_keeps_ready_applicant_absent(): void
    {
        $registrar = $this->registrar();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->assertSet('activeTab', 'overview')
            ->assertSee('Shiftee (Program shift)')
            ->assertSee('Continuing student')
            ->assertSee('Approved late enrollment')
            ->assertDontSee('Ready applicant')
            ->assertActionExists('shiftProgramAndEnroll');

        $accounting = User::factory()->create(['status' => User::StatusActive]);
        $accounting->assignRole(User::StaffRoleAccounting);

        Livewire::actingAs($accounting)
            ->test(ListEnrollments::class)
            ->assertActionHidden('shiftProgramAndEnroll');
    }

    #[Test]
    public function shift_program_and_enroll_modal_shifts_student_credits_subject_starts_individually_advised_enrollment_and_redirects(): void
    {
        $fixture = $this->shifteeFixture();
        $registrar = $this->registrar();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->mountAction('shiftProgramAndEnroll')
            ->setActionData([
                'student_profile_id' => $fixture['student']->id,
                'term_id' => $fixture['futureTerm']->id,
                'effective_on' => $fixture['futureTerm']->starts_on->toDateString(),
                'target_program_id' => $fixture['targetProgram']->id,
                'target_curriculum_version_id' => $fixture['targetCurriculum']->id,
                'reason' => 'Approved shift to target curriculum.',
                'credit_rows' => [
                    'e'.$fixture['targetCreditedEntry']->id => [
                        'status' => ProgramShiftEnrollmentForm::StatusCredit,
                        'source_grade_outcome_event_id' => $fixture['sourceEvent']->id,
                    ],
                ],
                'apply_and_enroll' => true,
                'impact_confirmed' => true,
            ])
            ->assertMountedActionModalSee('Already satisfied by SIA record')
            ->assertMountedActionModalSee('Pre-select equivalent SIA matches')
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Program shift applied and enrollment started');

        $student = $fixture['student']->fresh();
        $this->assertSame($fixture['targetProgram']->id, $student->program_id);
        $this->assertSame($fixture['targetCurriculum']->id, $student->curriculum_version_id);

        $change = StudentLifecycleChange::query()
            ->where('student_profile_id', $student->id)
            ->where('type', StudentLifecycleChange::TypeProgramShift)
            ->sole();
        $this->assertSame(StudentLifecycleChange::StateApplied, $change->state);
        $this->assertDatabaseHas('program_shift_credit_entries', [
            'student_lifecycle_change_id' => $change->id,
            'curriculum_entry_id' => $fixture['targetCreditedEntry']->id,
            'source_grade_outcome_event_id' => $fixture['sourceEvent']->id,
            'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
        ]);

        $enrollment = Enrollment::query()
            ->where('student_profile_id', $student->id)
            ->where('term_id', $fixture['futureTerm']->id)
            ->sole();
        $this->assertSame(Enrollment::SelectionIndividuallyAdvised, $enrollment->selection_basis);
        $this->assertSame('RegistrarAssisted', $enrollment->start_method);
        $component->assertRedirect(EnrollmentResource::getUrl('view', ['record' => $enrollment]));
    }

    #[Test]
    public function view_enrollment_preparing_displays_irregular_shiftee_status_and_excludes_shift_credited_subjects(): void
    {
        $fixture = $this->shifteeFixture();
        $registrar = $this->registrar();

        $creditedOffering = TermOffering::factory()->create([
            'term_id' => $fixture['futureTerm']->id,
            'curriculum_entry_id' => $fixture['targetCreditedEntry']->id,
            'state' => TermOffering::StateScheduled,
        ]);
        $creditedSection = Section::factory()->create([
            'term_offering_id' => $creditedOffering->id,
            'code' => 'SHIFT-CRED-A',
            'state' => Section::StateOpen,
        ]);

        $remainingOffering = TermOffering::factory()->create([
            'term_id' => $fixture['futureTerm']->id,
            'curriculum_entry_id' => $fixture['targetRemainingEntry']->id,
            'state' => TermOffering::StateScheduled,
        ]);
        $remainingSection = Section::factory()->create([
            'term_offering_id' => $remainingOffering->id,
            'code' => 'SHIFT-REM-A',
            'state' => Section::StateOpen,
        ]);

        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $fixture['futureTerm']->id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'input_snapshot' => [],
            'input_hash' => hash('sha256', fake()->uuid()),
            'solver_version' => 'test',
            'published_at' => now(),
            'publication_version' => 1,
        ]);
        PublishedTimetableVersion::factory()->create([
            'term_id' => $fixture['futureTerm']->id,
            'schedule_run_id' => $run->id,
            'version' => 1,
            'state' => PublishedTimetableVersion::StatePublished,
            'published_at' => now(),
            'published_by' => $registrar->id,
        ]);

        $result = app(ShiftProgramAndStartEnrollment::class)->recordAndOptionallyEnroll([
            'student_profile_id' => $fixture['student']->id,
            'term_id' => $fixture['futureTerm']->id,
            'effective_on' => $fixture['futureTerm']->starts_on->toDateString(),
            'reason' => 'Shifted into target program with credited course.',
            'target_program_id' => $fixture['targetProgram']->id,
            'target_curriculum_version_id' => $fixture['targetCurriculum']->id,
            'credit_entries' => [[
                'curriculum_entry_id' => $fixture['targetCreditedEntry']->id,
                'source_grade_outcome_event_id' => $fixture['sourceEvent']->id,
                'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
            ]],
        ], $registrar, applyAndEnroll: true);

        $enrollment = $result['enrollment'];
        $this->assertInstanceOf(Enrollment::class, $enrollment);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $enrollment->id])
            ->assertSee('Status:')
            ->assertSee('Irregular – Shiftee')
            ->assertSee('Credited subjects: 1 / 3. Remaining: 2.')
            ->mountAction('prepareProposal')
            ->assertMountedActionModalSee($remainingSection->code)
            ->assertMountedActionModalDontSee($creditedSection->code);

        // Also verify the student panel does not ask an already-shifted student to upload a shifting approval letter.
        Filament::setCurrentPanel(Filament::getPanel('student'));
        Livewire::actingAs($fixture['student']->user)
            ->test(StudentEnrollmentPage::class)
            ->assertSee('Irregular – Shiftee')
            ->mountAction('applyAsIrregular')
            ->assertMountedActionModalDontSee('Shiftees: shifting approval letter');
    }

    #[Test]
    public function applying_an_existing_recorded_approved_shift_via_modal_works_end_to_end(): void
    {
        $fixture = $this->shifteeFixture();
        $registrar = $this->registrar();

        $recorded = app(ShiftProgramAndStartEnrollment::class)->recordAndOptionallyEnroll([
            'student_profile_id' => $fixture['student']->id,
            'term_id' => $fixture['futureTerm']->id,
            'effective_on' => $fixture['futureTerm']->starts_on->toDateString(),
            'reason' => 'Pre-recorded shift awaiting application.',
            'target_program_id' => $fixture['targetProgram']->id,
            'target_curriculum_version_id' => $fixture['targetCurriculum']->id,
            'credit_entries' => [[
                'curriculum_entry_id' => $fixture['targetCreditedEntry']->id,
                'source_grade_outcome_event_id' => $fixture['sourceEvent']->id,
                'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
            ]],
        ], $registrar, applyAndEnroll: false);

        $pendingShift = $recorded['change'];
        $this->assertSame(StudentLifecycleChange::StateRecordedApproved, $pendingShift->state);
        $this->assertNotSame($fixture['targetProgram']->id, $fixture['student']->fresh()->program_id);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->mountAction('shiftProgramAndEnroll')
            ->setActionData([
                'student_profile_id' => $fixture['student']->id,
                'term_id' => $fixture['futureTerm']->id,
                'impact_confirmed' => true,
            ])
            ->assertMountedActionModalSee('Existing approved shift found:')
            ->assertMountedActionModalSee('1 credit')
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Program shift applied and enrollment started');

        $this->assertSame(StudentLifecycleChange::StateApplied, $pendingShift->fresh()->state);
        $this->assertSame($fixture['targetProgram']->id, $fixture['student']->fresh()->program_id);
        $this->assertSame($fixture['targetCurriculum']->id, $fixture['student']->fresh()->curriculum_version_id);

        $enrollment = Enrollment::query()
            ->where('student_profile_id', $fixture['student']->id)
            ->where('term_id', $fixture['futureTerm']->id)
            ->sole();
        $this->assertSame(Enrollment::SelectionIndividuallyAdvised, $enrollment->selection_basis);
        $component->assertRedirect(EnrollmentResource::getUrl('view', ['record' => $enrollment]));
    }

    /** @return array<string, mixed> */
    private function shifteeFixture(): array
    {
        $student = StudentProfile::factory()->create(['lifecycle_status' => StudentProfile::LifecycleActive]);
        $student->user->assignRole('student');

        $currentTerm = Term::factory()->create([
            'state' => Term::StateActive,
            'starts_on' => today()->subMonths(2),
            'ends_on' => today()->addWeeks(2),
        ]);
        $currentEnrollment = Enrollment::factory()->create([
            'student_profile_id' => $student->id,
            'credential_user_id' => $student->user_id,
            'term_id' => $currentTerm->id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => today()->subMonths(2),
        ]);

        // Course 1: identical course passed at SIA
        $identicalSpec = CourseSpecification::factory()->create([
            'title' => 'Understanding the Self',
            'credit_units' => 3.00,
            'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
            'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
        ]);
        $identicalSourceEntry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $student->curriculum_version_id,
            'course_specification_id' => $identicalSpec->id,
            'year_level' => '1',
            'term_label' => 'First Semester',
        ]);
        $this->addPassedCourse($currentEnrollment, $currentTerm, $identicalSourceEntry, '1.50');

        // Course 2: equivalent course passed at SIA
        $sourceSpec = CourseSpecification::factory()->create([
            'title' => 'Intro to Computer Science',
            'credit_units' => 3.00,
            'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
            'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
        ]);
        $sourceEntry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $student->curriculum_version_id,
            'course_specification_id' => $sourceSpec->id,
            'year_level' => '1',
            'term_label' => 'First Semester',
        ]);
        $sourceEvent = $this->addPassedCourse($currentEnrollment, $currentTerm, $sourceEntry, '1.75');

        $futureTerm = Term::factory()->create([
            'state' => Term::StateActive,
            'starts_on' => today()->addMonth(),
            'ends_on' => today()->addMonths(5),
            'registration_applicability' => 'AllEligible',
        ]);
        $package = TermCalendarPackage::factory()->create([
            'term_id' => $futureTerm->id,
            'state' => TermCalendarPackage::StateActive,
        ]);
        TermCalendarWindow::factory()->create([
            'term_calendar_package_id' => $package->id,
            'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => today()->subDay(),
            'closes_on' => today()->addWeek(),
        ]);

        $targetProgram = Program::factory()->create(['name' => 'BS Information Technology']);
        $targetCurriculum = CurriculumVersion::factory()->create([
            'program_id' => $targetProgram->id,
            'state' => CurriculumVersion::StateActive,
        ]);

        // Target entry 1: identical course (already satisfied)
        $targetIdenticalSpec = CourseSpecification::factory()->create([
            'course_id' => $identicalSpec->course_id,
            'title' => 'Understanding the Self',
            'credit_units' => 3.00,
            'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
            'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
        ]);
        $targetIdenticalEntry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $targetCurriculum->id,
            'course_specification_id' => $targetIdenticalSpec->id,
            'year_level' => '1',
            'term_label' => 'First Semester',
        ]);

        // Target entry 2: equivalent course (credited via shift)
        $targetCreditedSpec = CourseSpecification::factory()->create([
            'title' => 'Intro to Computing',
            'credit_units' => 3.00,
            'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
            'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
        ]);
        $targetCreditedEntry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $targetCurriculum->id,
            'course_specification_id' => $targetCreditedSpec->id,
            'year_level' => '1',
            'term_label' => 'First Semester',
        ]);
        CourseRequirement::factory()->create([
            'course_specification_id' => $targetCreditedSpec->id,
            'related_course_id' => $sourceSpec->course_id,
            'rule_type' => CourseRequirement::TypeEquivalency,
            'group_key' => 'eq1',
            'state' => CourseRequirement::StateActive,
        ]);

        // Target entry 3: remaining course (need to take)
        $targetRemainingSpec = CourseSpecification::factory()->create([
            'title' => 'Web Systems Basics',
            'credit_units' => 3.00,
            'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
            'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
        ]);
        $targetRemainingEntry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $targetCurriculum->id,
            'course_specification_id' => $targetRemainingSpec->id,
            'year_level' => '1',
            'term_label' => 'Second Semester',
        ]);

        return compact(
            'student',
            'currentTerm',
            'currentEnrollment',
            'sourceEvent',
            'futureTerm',
            'targetProgram',
            'targetCurriculum',
            'targetIdenticalEntry',
            'targetCreditedEntry',
            'targetRemainingEntry',
        );
    }

    private function addPassedCourse(Enrollment $enrollment, Term $term, CurriculumEntry $entry, string $grade): GradeOutcomeEvent
    {
        $offering = TermOffering::factory()->create([
            'term_id' => $term->id,
            'curriculum_entry_id' => $entry->id,
            'state' => TermOffering::StateScheduled,
        ]);
        $section = Section::factory()->create([
            'term_offering_id' => $offering->id,
            'state' => Section::StateOpen,
        ]);
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
            'units_snapshot' => $entry->courseSpecification->credit_units,
            'added_at' => now(),
        ]);
        $roster = GradeRoster::factory()->create([
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'state' => GradeRoster::StateReleased,
            'released_at' => now(),
        ]);
        $row = GradeRosterRow::factory()->create([
            'grade_roster_id' => $roster->id,
            'course_enrollment_id' => $courseEnrollment->id,
            'current_outcome_code' => $grade,
            'current_outcome_category' => GradeRosterRow::CategoryPassing,
            'released_at' => now(),
        ]);

        return GradeOutcomeEvent::factory()->create([
            'grade_roster_row_id' => $row->id,
            'result_code' => $grade,
            'new_value' => $grade,
            'released_at' => now(),
        ]);
    }

    private function registrar(): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole(User::StaffRoleRegistrar);

        return $user;
    }
}
