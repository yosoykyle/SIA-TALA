<?php

namespace Tests\Feature;

use App\Actions\Academics\AcademicEnrollmentEffect;
use App\Actions\Academics\IrregularStudentReasons;
use App\Actions\Enrollment\RegistrationClassChoices;
use App\Actions\Enrollment\SubmitIrregularEnrollmentRequest;
use App\Actions\Grades\PostAndReleaseGradeRoster;
use App\Filament\Resources\StudentProfiles\Pages\ListStudentProfiles;
use App\Filament\Resources\StudentProfiles\Pages\ViewStudentProfile;
use App\Filament\Student\Pages\Enrollment as StudentEnrollmentPage;
use App\Models\AcademicDecision;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\GradeRosterVersion;
use App\Models\GradeRosterVersionRow;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\BuildsIrregularStudents;
use Tests\TestCase;

class Issue93IrregularPickerAndDeficiencyAdvisoryTest extends TestCase
{
    use BuildsIrregularStudents;
    use DatabaseTransactions;

    private User $registrar;

    private User $faculty;

    private CurriculumVersion $curriculum;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['student', User::StaffRoleRegistrar, User::StaffRoleFaculty] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->registrar = $this->staff(User::StaffRoleRegistrar);
        $this->faculty = $this->staff(User::StaffRoleFaculty);
        $this->curriculum = CurriculumVersion::factory()->create([
            'program_id' => Program::factory()->create()->id,
            'state' => CurriculumVersion::StateActive,
        ]);
    }

    public function test_picker_shows_exact_prerequisite_codes_and_unlocks_mutual_corequisite_pair(): void
    {
        $student = $this->student();
        $pastTerm = $this->term(now()->subMonths(6), now()->subMonths(2));
        $currentTerm = $this->term(now()->subDays(3), now()->addMonths(3), Term::TypeSecondSemester, Term::StateActive);
        $timetable = PublishedTimetableVersion::factory()->for($currentTerm)->create([
            'state' => PublishedTimetableVersion::StatePublished,
        ]);

        $intro = $this->recurringEntry('IT-101', 'Introduction to Computing', 3.0, 1, 'First Semester', Term::TypeFirstSemester);
        $adv = $this->recurringEntry('IT-201', 'Advanced Computing', 3.0, 1, 'Second Semester', Term::TypeSecondSemester);
        $lec = $this->recurringEntry('CS-201', 'Programming Lecture', 2.0, 1, 'Second Semester', Term::TypeSecondSemester);
        $lab = $this->recurringEntry('CS-201L', 'Programming Laboratory', 1.0, 1, 'Second Semester', Term::TypeSecondSemester);
        $unofferedCoreqCourse = Course::factory()->create(['code' => 'CS-999']);
        $blockedByUnoffered = $this->recurringEntry('CS-202', 'Special Project', 3.0, 1, 'Second Semester', Term::TypeSecondSemester);

        CourseRequirement::factory()->create([
            'course_specification_id' => $adv->course_specification_id,
            'related_course_id' => $intro->courseSpecification->course_id,
            'rule_type' => CourseRequirement::TypePrerequisite,
            'group_key' => 'prereq-101',
            'state' => CourseRequirement::StateActive,
        ]);

        // Mutual corequisite pair: CS-201 <-> CS-201L
        CourseRequirement::factory()->create([
            'course_specification_id' => $lec->course_specification_id,
            'related_course_id' => $lab->courseSpecification->course_id,
            'rule_type' => CourseRequirement::TypeCorequisite,
            'group_key' => 'coreq-lab',
            'state' => CourseRequirement::StateActive,
        ]);
        CourseRequirement::factory()->create([
            'course_specification_id' => $lab->course_specification_id,
            'related_course_id' => $lec->courseSpecification->course_id,
            'rule_type' => CourseRequirement::TypeCorequisite,
            'group_key' => 'coreq-lec',
            'state' => CourseRequirement::StateActive,
        ]);

        // Corequisite to a course not offered this term stays locked
        CourseRequirement::factory()->create([
            'course_specification_id' => $blockedByUnoffered->course_specification_id,
            'related_course_id' => $unofferedCoreqCourse->id,
            'rule_type' => CourseRequirement::TypeCorequisite,
            'group_key' => 'coreq-unoffered',
            'state' => CourseRequirement::StateActive,
        ]);

        // Fail IT-101 so student is irregular and IT-201 is prereq-locked
        $this->attempt($student, $pastTerm, $intro, '5.00', GradeRosterRow::CategoryFailed);

        $introSection = $this->publishedSection($currentTerm, $timetable, $intro, 'IT-101-A', 1, '08:00:00', '09:30:00');
        $advSection = $this->publishedSection($currentTerm, $timetable, $adv, 'IT-201-A', 2, '08:00:00', '09:30:00');
        $lecSection = $this->publishedSection($currentTerm, $timetable, $lec, 'CS-201-A', 3, '08:00:00', '09:30:00');
        $labSection = $this->publishedSection($currentTerm, $timetable, $lab, 'CS-201L-A', 4, '08:00:00', '09:30:00');
        $unofferedCoreqSection = $this->publishedSection($currentTerm, $timetable, $blockedByUnoffered, 'CS-202-A', 5, '08:00:00', '09:30:00');

        $case = Enrollment::factory()->create([
            'credential_user_id' => $student->user_id,
            'student_profile_id' => $student->id,
            'term_id' => $currentTerm->id,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
            'officially_enrolled_at' => null,
            'current_proposal_version_id' => null,
        ]);

        $choices = app(RegistrationClassChoices::class);
        $initialOptions = $choices->sectionOptions($case, []);

        $this->assertStringContainsString('Prereq unmet (Requires IT-101)', $initialOptions[$advSection->id]);
        $this->assertStringContainsString('Coreq unmet (Requires CS-201L)', $initialOptions[$lecSection->id]);
        $this->assertStringContainsString('Coreq unmet (Requires CS-201)', $initialOptions[$labSection->id]);
        $this->assertStringContainsString('Coreq unmet (Requires CS-999)', $initialOptions[$unofferedCoreqSection->id]);

        // Mutual corequisite pair does NOT lock either checkbox out initially
        $this->assertTrue($choices->isSectionLocked($case, $advSection, []));
        $this->assertFalse($choices->isSectionLocked($case, $lecSection, []));
        $this->assertFalse($choices->isSectionLocked($case, $labSection, []));
        $this->assertTrue($choices->isSectionLocked($case, $unofferedCoreqSection, []));

        // Selecting CS-201 clears Coreq unmet on CS-201L dynamically
        $withLecSelected = $choices->sectionOptions($case, [$lecSection->id]);
        $this->assertStringNotContainsString('Coreq unmet', $withLecSelected[$labSection->id]);
        $this->assertStringContainsString('Coreq unmet (Requires CS-201L)', $withLecSelected[$lecSection->id]);

        // Submitting only one half of the mutual corequisite pair fails server validation; submitting both passes
        $this->expectValidationMessage(
            fn () => app(SubmitIrregularEnrollmentRequest::class)->execute($case, $student->user, [$introSection->id, $lecSection->id], null),
            'Coreq unmet (Requires CS-201L)',
        );

        $request = app(SubmitIrregularEnrollmentRequest::class)->execute(
            $case,
            $student->user,
            [$introSection->id, $lecSection->id, $labSection->id],
            null,
        );
        $this->assertSame([$introSection->id, $lecSection->id, $labSection->id], $request->sectionIds());
    }

    public function test_unoffered_mandatory_retakes_render_visible_locked_list_and_live_unit_clash_warnings(): void
    {
        $student = $this->student();
        $pastTerm = $this->term(now()->subMonths(6), now()->subMonths(2));
        $currentTerm = $this->term(now()->subDays(3), now()->addMonths(3), Term::TypeSecondSemester, Term::StateActive);
        $this->openEnrollmentWindow($currentTerm);

        $timetable = PublishedTimetableVersion::factory()->for($currentTerm)->create([
            'state' => PublishedTimetableVersion::StatePublished,
        ]);

        // Year 1 Second Semester normal curriculum load = 6.0 units (GE-102 + IT-102).
        $unofferedFailed = $this->recurringEntry('MATH-101', 'College Algebra', 3.0, 1, 'First Semester', Term::TypeFirstSemester);
        $offeredA = $this->recurringEntry('GE-102', 'Purposive Communication', 3.0, 1, 'Second Semester', Term::TypeSecondSemester);
        $offeredB = $this->recurringEntry('IT-102', 'Discrete Mathematics', 3.0, 1, 'Second Semester', Term::TypeSecondSemester);
        $extraFirstSem = $this->recurringEntry('ENG-101', 'Technical Writing', 3.0, 1, 'First Semester', Term::TypeFirstSemester);

        // Fail MATH-101 (NOT offered this term) -> must appear in Step 2 read-only locked list.
        $this->attempt($student, $pastTerm, $unofferedFailed, '5.00', GradeRosterRow::CategoryFailed);

        // Two offered classes clash on Monday 8:00-9:30 AM, plus third class pushes load to 9 > 6 max units.
        $secA = $this->publishedSection($currentTerm, $timetable, $offeredA, 'GE-102-A', 1, '08:00:00', '09:30:00');
        $secB = $this->publishedSection($currentTerm, $timetable, $offeredB, 'IT-102-A', 1, '08:30:00', '10:00:00');
        $secExtra = $this->publishedSection($currentTerm, $timetable, $extraFirstSem, 'ENG-101-A', 3, '13:00:00', '14:30:00');

        $case = Enrollment::factory()->create([
            'credential_user_id' => $student->user_id,
            'student_profile_id' => $student->id,
            'term_id' => $currentTerm->id,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
            'officially_enrolled_at' => null,
            'current_proposal_version_id' => null,
        ]);

        // Step 2 UI shows unoffered mandatory retake + live "Selected: X / Y max units" + overload + clash warning
        Filament::setCurrentPanel(Filament::getPanel('student'));
        Livewire::actingAs($student->user)
            ->test(StudentEnrollmentPage::class)
            ->mountAction('applyAsIrregular')
            ->assertMountedActionModalSee('MATH-101 — College Algebra · Mandatory retake · Not offered this term')
            ->set('mountedActions.0.data.section_ids', [$secA->id, $secB->id, $secExtra->id])
            ->assertMountedActionModalSee('Selected: 9 / 6 max units')
            ->assertMountedActionModalSee('Warning: exceeds maximum allowed load of 6 units')
            ->assertMountedActionModalSee('Warning: schedule clash');

        // Server-side validation rejects schedule clash and unit overload independently
        $this->expectValidationMessage(
            fn () => app(SubmitIrregularEnrollmentRequest::class)->execute($case, $student->user, [$secA->id, $secB->id], null),
            'Schedule clash detected:',
        );
        $secBNoClash = $this->publishedSection($currentTerm, $timetable, $offeredB, 'IT-102-B', 2, '10:00:00', '11:30:00');
        $this->expectValidationMessage(
            fn () => app(SubmitIrregularEnrollmentRequest::class)->execute($case, $student->user, [$secA->id, $secBNoClash->id, $secExtra->id], null),
            'exceed your maximum allowed load of 6 units',
        );

        // Special Term uses SummerRetakeUnitCeiling (9.0 units) when no explicit special-term curriculum entries exist
        $summerTerm = $this->term(now()->addMonths(4), now()->addMonths(5), Term::TypeSpecialTerm, Term::StateActive);
        $summerTimetable = PublishedTimetableVersion::factory()->for($summerTerm)->create([
            'state' => PublishedTimetableVersion::StatePublished,
        ]);
        $summerSec1 = $this->publishedSection($summerTerm, $summerTimetable, $this->recurringEntry('SUM-101', 'Summer Course 1', 3.0, 1, 'First Semester', Term::TypeFirstSemester), 'SUM-101-A', 1, '08:00:00', '09:30:00');
        $summerSec2 = $this->publishedSection($summerTerm, $summerTimetable, $this->recurringEntry('SUM-102', 'Summer Course 2', 3.0, 1, 'First Semester', Term::TypeFirstSemester), 'SUM-102-A', 2, '08:00:00', '09:30:00');
        $summerSec3 = $this->publishedSection($summerTerm, $summerTimetable, $this->recurringEntry('SUM-103', 'Summer Course 3', 3.0, 1, 'First Semester', Term::TypeFirstSemester), 'SUM-103-A', 3, '08:00:00', '09:30:00');
        $summerSec4 = $this->publishedSection($summerTerm, $summerTimetable, $this->recurringEntry('SUM-104', 'Summer Course 4', 3.0, 1, 'First Semester', Term::TypeFirstSemester), 'SUM-104-A', 4, '08:00:00', '09:30:00');

        $summerCase = Enrollment::factory()->create([
            'credential_user_id' => $student->user_id,
            'student_profile_id' => $student->id,
            'term_id' => $summerTerm->id,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
            'officially_enrolled_at' => null,
            'current_proposal_version_id' => null,
        ]);

        $summerEval = app(RegistrationClassChoices::class)->evaluateSelection(
            $summerCase,
            [$summerSec1->id, $summerSec2->id, $summerSec3->id, $summerSec4->id],
        );
        $this->assertSame(9.0, $summerEval['max_units']);
        $this->assertTrue($summerEval['over_unit_limit']);
    }

    public function test_grade_release_clears_review_cache_and_three_deficiencies_only_shows_staff_advisory(): void
    {
        $student = $this->student();
        $earlierFirstSem = $this->term(now()->subMonths(12), now()->subMonths(8), Term::TypeFirstSemester, Term::StateClosed);
        $earlierSummer = $this->term(now()->subMonths(8), now()->subMonths(7), Term::TypeSpecialTerm, Term::StateClosed);
        $secondSem = $this->term(now()->subMonths(4), now()->subDay(), Term::TypeSecondSemester, Term::StateActive);
        $upcomingFirstSem = $this->term(now()->addMonth(), now()->addMonths(5), Term::TypeFirstSemester, Term::StateDraft);

        $c1 = $this->recurringEntry('DEF-101', 'Course One', 3.0, 1, 'First Semester', Term::TypeFirstSemester);
        $c2 = $this->recurringEntry('DEF-102', 'Course Two', 3.0, 1, 'First Semester', Term::TypeFirstSemester);
        $c3 = $this->recurringEntry('DEF-103', 'Course Three', 3.0, 1, 'First Semester', Term::TypeFirstSemester);

        // Two existing unresolved deficiencies
        $this->attempt($student, $earlierFirstSem, $c1, '5.00', GradeRosterRow::CategoryFailed);
        $this->attempt($student, $earlierSummer, $c2, 'INC', GradeRosterRow::CategoryIncomplete);

        // Prime cache before releasing the 3rd failing grade via PostAndReleaseGradeRoster
        Cache::put(IrregularStudentReasons::ReviewCountCacheKey, 999, now()->addMinutes(5));

        $roster = $this->submittedRoster($student, $secondSem, $c3, '5.00');
        app(PostAndReleaseGradeRoster::class)->execute($roster, $this->registrar);
        $secondSem->update(['state' => Term::StateClosed]);

        $this->assertFalse(Cache::has(IrregularStudentReasons::ReviewCountCacheKey));

        // 3 unresolved deficiencies: advisory ONLY; PRD 05 §246 guarantees no auto-block or probation/repeat assignment
        $review = app(IrregularStudentReasons::class)->forStudent($student);
        $effect = app(AcademicEnrollmentEffect::class)->forStudent($student);

        $this->assertSame(3, $review['unresolved_deficiency_count']);
        $this->assertTrue($review['has_high_deficiency_count']);
        $this->assertSame(AcademicDecision::EffectAdvisingRequired, $effect['effect']);
        $this->assertNotSame(AcademicDecision::EffectBlocked, $effect['effect']);
        $this->assertNotSame(StudentProfile::StandingMustRepeatYear, $student->fresh()->academic_standing);
        $this->assertNotSame(StudentProfile::StandingProbationary, $student->fresh()->academic_standing);

        // Student audience does NOT receive staff advisory pill; staff audience DOES receive it
        $studentHtml = view('components.irregular-reasons', ['review' => $review, 'audience' => 'student'])->render();
        $staffHtml = view('components.irregular-reasons', ['review' => $review, 'audience' => 'staff'])->render();
        $this->assertStringNotContainsString('Review advisory', $studentHtml);
        $this->assertStringContainsString('3 unresolved deficiencies · Review advisory', $staffHtml);

        // StudentProfileResource Standing description and Record enrollment decision modal show advisory,
        // and Record enrollment decision defaults term_id cleanly to the upcoming term rather than closed 2nd Sem.
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($this->registrar)
            ->test(ListStudentProfiles::class)
            ->searchTable($student->student_number)
            ->assertSee('3 unresolved deficiencies (advisory)');

        Livewire::actingAs($this->registrar)
            ->withQueryParams(['recordTab' => 'academic'])
            ->test(ViewStudentProfile::class, ['record' => $student->id])
            ->assertSee('3 unresolved deficiencies · Review advisory')
            ->mountAction('recordAcademicDecision')
            ->assertActionDataSet(['term_id' => (string) $upcomingFirstSem->id])
            ->assertMountedActionModalSee('Advisory: 3 unresolved deficiencies');
    }

    private function recurringEntry(
        string $code,
        string $title,
        float $units,
        int $yearLevel,
        string $termLabel,
        string $termType,
    ): CurriculumEntry {
        $specification = CourseSpecification::factory()->create([
            'course_id' => Course::factory()->create(['code' => $code])->id,
            'title' => $title,
            'credit_units' => $units,
            'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
            'state' => CourseSpecification::StateActive,
        ]);

        return CurriculumEntry::factory()->create([
            'curriculum_version_id' => $this->curriculum->id,
            'course_specification_id' => $specification->id,
            'year_level' => (string) $yearLevel,
            'term_label' => $termLabel,
            'term_type' => $termType,
        ])->load('courseSpecification.course');
    }

    private function publishedSection(
        Term $term,
        PublishedTimetableVersion $timetable,
        CurriculumEntry $entry,
        string $sectionCode,
        int $dayOfWeek,
        string $startsAt,
        string $endsAt,
    ): Section {
        $offering = TermOffering::query()
            ->where('term_id', $term->id)
            ->where('curriculum_entry_id', $entry->id)
            ->first()
            ?? TermOffering::factory()->create([
                'term_id' => $term->id,
                'curriculum_entry_id' => $entry->id,
                'state' => TermOffering::StateScheduled,
                'category' => TermOffering::CategoryRegular,
            ]);

        $section = Section::factory()->create([
            'term_offering_id' => $offering->id,
            'code' => $sectionCode,
            'state' => Section::StateOpen,
            'capacity' => 30,
        ])->load('termOffering.curriculumEntry.courseSpecification.course');

        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $section->id,
            'day_of_week' => $dayOfWeek,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        return $section;
    }

    private function openEnrollmentWindow(Term $term): void
    {
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);

        TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addWeek()->toDateString(),
            'cutoff_at' => '23:59:59',
        ]);
    }

    private function submittedRoster(StudentProfile $student, Term $term, CurriculumEntry $entry, string $resultCode): GradeRoster
    {
        $offering = TermOffering::factory()->create([
            'term_id' => $term->id,
            'curriculum_entry_id' => $entry->id,
            'state' => TermOffering::StateScheduled,
        ]);
        $section = Section::factory()->create(['term_offering_id' => $offering->id]);
        $enrollment = Enrollment::factory()->create([
            'credential_user_id' => $student->user_id,
            'student_profile_id' => $student->id,
            'term_id' => $term->id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'officially_enrolled_at' => now(),
        ]);
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
            'units_snapshot' => 3.00,
            'added_at' => now(),
        ]);
        $assignment = ClassOfferingTeachingAssignment::query()->create([
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'faculty_user_id' => $this->faculty->id,
            'role' => ClassOfferingTeachingAssignment::RoleDesignated,
            'state' => ClassOfferingTeachingAssignment::StateActive,
            'authority_reference' => 'SUBMIT-'.$courseEnrollment->id,
            'assigned_by' => $this->registrar->id,
            'effective_at' => now(),
        ]);
        $roster = GradeRoster::factory()->create([
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'teaching_assignment_id' => $assignment->id,
            'faculty_user_id' => $this->faculty->id,
            'state' => GradeRoster::StateSubmitted,
            'current_version_number' => 1,
            'membership_signature' => 'sig-'.$courseEnrollment->id,
        ]);
        $row = GradeRosterRow::query()->create([
            'grade_roster_id' => $roster->id,
            'course_enrollment_id' => $courseEnrollment->id,
            'is_current_membership' => true,
            'final_result' => $resultCode,
            'row_revision' => 1,
        ]);
        $version = GradeRosterVersion::query()->create([
            'grade_roster_id' => $roster->id,
            'version_number' => 1,
            'state' => GradeRosterVersion::StateSubmitted,
            'teaching_assignment_id' => $assignment->id,
            'membership_signature' => 'sig-'.$courseEnrollment->id,
            'submitted_by' => $this->faculty->id,
            'submitted_at' => now(),
        ]);
        GradeRosterVersionRow::query()->create([
            'grade_roster_version_id' => $version->id,
            'grade_roster_row_id' => $row->id,
            'course_enrollment_id' => $courseEnrollment->id,
            'final_result' => $resultCode,
            'row_revision' => 1,
        ]);

        return $roster;
    }

    private function expectValidationMessage(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail('Expected validation error containing: '.$message);
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($message, collect($exception->errors())->flatten()->implode(' '));
        }
    }
}
