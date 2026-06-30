<?php

namespace Tests\Feature;

use App\Actions\Enrollment\SubjectSuggestionService;
use App\Actions\Grades\GenerateGradeRoster;
use App\Actions\Grades\GradePolicyService;
use App\Actions\Grades\PostAndReleaseGradeRoster;
use App\Actions\Grades\RecordApprovedGradeCorrection;
use App\Actions\Grades\RecordIncResolution;
use App\Actions\Grades\SaveGradeRosterPeriodEquivalent;
use App\Actions\Grades\SubmitGradeRoster;
use App\Models\CalendarEvent;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\StudentScheduleBinding;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL72GradesMvpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['registrar', 'faculty', 'academic-head', 'student'] as $role) {
            Role::create(['name' => $role]);
        }
    }

    #[Test]
    public function servitech_policy_computes_average_and_numeric_outcome(): void
    {
        $policy = app(GradePolicyService::class);

        $average = $policy->computedAverage(90, 91, 94);

        $this->assertSame(91.9, $average);
        $this->assertSame([
            'code' => '1.75',
            'category' => GradeRosterRow::CategoryPassing,
            'value' => 1.75,
        ], $policy->outcomeForAverage($average));
        $this->assertSame('5.00', $policy->outcomeForAverage(74.9)['code']);
    }

    #[Test]
    public function registrar_generates_one_grade_row_per_official_course_enrollment_even_with_linked_meetings(): void
    {
        $fixture = $this->gradeFixture(linkedMeetings: true);

        $roster = app(GenerateGradeRoster::class)->execute($fixture['termOffering'], $fixture['section'], $fixture['faculty']);
        $sameRoster = app(GenerateGradeRoster::class)->execute($fixture['termOffering'], $fixture['section'], $fixture['faculty']);

        $this->assertTrue($roster->is($sameRoster));
        $this->assertSame(1, $roster->rows()->count());
        $this->assertDatabaseHas('grade_roster_rows', [
            'grade_roster_id' => $roster->id,
            'course_enrollment_id' => $fixture['courseEnrollment']->id,
        ]);
    }

    #[Test]
    public function faculty_period_equivalent_entry_requires_matching_grade_window(): void
    {
        $fixture = $this->gradeFixture();
        $roster = app(GenerateGradeRoster::class)->execute($fixture['termOffering'], $fixture['section'], $fixture['faculty']);
        $row = $roster->rows()->firstOrFail();

        app(SaveGradeRosterPeriodEquivalent::class)->execute($row, 'prelim', 90, $fixture['faculty']);

        $this->assertSame('90.0000', $row->fresh()->prelim_equivalent);

        CalendarEvent::query()->delete();

        $this->expectException(RuntimeException::class);

        app(SaveGradeRosterPeriodEquivalent::class)->execute($row->fresh(), 'midterm', 91, $fixture['faculty']);
    }

    #[Test]
    public function submitted_roster_is_posted_and_released_with_append_only_initial_events(): void
    {
        $fixture = $this->gradeFixture();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $roster = app(GenerateGradeRoster::class)->execute($fixture['termOffering'], $fixture['section'], $fixture['faculty']);
        $row = $roster->rows()->firstOrFail();

        app(SaveGradeRosterPeriodEquivalent::class)->execute($row, 'prelim', 90, $fixture['faculty']);
        app(SaveGradeRosterPeriodEquivalent::class)->execute($row->fresh(), 'midterm', 91, $fixture['faculty']);
        app(SaveGradeRosterPeriodEquivalent::class)->execute($row->fresh(), 'final', 94, $fixture['faculty']);

        app(SubmitGradeRoster::class)->execute($roster->fresh(), $fixture['faculty']);
        app(PostAndReleaseGradeRoster::class)->execute($roster->fresh(), $registrar);

        $row = $row->fresh();
        $this->assertSame(GradeRoster::StateReleased, $roster->fresh()->state);
        $this->assertSame('1.75', $row->current_outcome_code);
        $this->assertNotNull($row->released_at);
        $this->assertDatabaseHas('grade_outcome_events', [
            'grade_roster_row_id' => $row->id,
            'event_type' => GradeOutcomeEvent::TypeInitialRelease,
            'new_category' => GradeRosterRow::CategoryPassing,
        ]);
    }

    #[Test]
    public function registrar_records_inc_resolution_and_approved_correction_as_events(): void
    {
        $fixture = $this->gradeFixture();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $roster = app(GenerateGradeRoster::class)->execute($fixture['termOffering'], $fixture['section'], $fixture['faculty']);
        $row = $roster->rows()->firstOrFail();
        $row->update([
            'current_outcome_code' => 'INC',
            'current_outcome_category' => GradeRosterRow::CategoryIncomplete,
        ]);

        app(SubmitGradeRoster::class)->execute($roster, $fixture['faculty']);
        app(PostAndReleaseGradeRoster::class)->execute($roster->fresh(), $registrar);

        app(RecordIncResolution::class)->execute($row->fresh(), '3.00', 'Registrar approved removal', 'Completed removal exam.', 'INC-001', $registrar);
        app(RecordApprovedGradeCorrection::class)->execute($row->fresh(), '2.75', 'Approved correction form', 'Physical correction approved.', 'CORR-001', $registrar);

        $this->assertSame('2.75', $row->fresh()->current_outcome_code);
        $this->assertDatabaseHas('grade_outcome_events', ['event_type' => GradeOutcomeEvent::TypeIncResolution, 'evidence_reference' => 'INC-001']);
        $this->assertDatabaseHas('grade_outcome_events', ['event_type' => GradeOutcomeEvent::TypePostedCorrection, 'evidence_reference' => 'CORR-001']);
    }

    #[Test]
    public function subject_suggestions_use_released_grade_roster_rows_and_block_temporary_outcomes(): void
    {
        $fixture = $this->subjectSuggestionFixture();
        $passed = $this->curriculumEntry($fixture['profile'], 'TAL-PASS', sequence: 1);
        $failed = $this->curriculumEntry($fixture['profile'], 'TAL-FAIL', sequence: 2);
        $inc = $this->curriculumEntry($fixture['profile'], 'TAL-INC', sequence: 3);
        $pending = $this->curriculumEntry($fixture['profile'], 'TAL-PEND', sequence: 4);
        $unreleased = $this->curriculumEntry($fixture['profile'], 'TAL-UNREL', sequence: 5);
        $needsTemporaryPrerequisites = $this->curriculumEntry($fixture['profile'], 'TAL-TARGET', sequence: 6);
        $this->prerequisite($needsTemporaryPrerequisites, $inc, sequence: 1);
        $this->prerequisite($needsTemporaryPrerequisites, $pending, sequence: 2);

        foreach ([$passed, $failed, $inc, $pending, $unreleased, $needsTemporaryPrerequisites] as $entry) {
            TermOffering::factory()->create([
                'term_id' => $fixture['enrollment']->term_id,
                'curriculum_entry_id' => $entry->id,
                'state' => TermOffering::StateScheduled,
            ]);
        }

        $passedRow = $this->releasedGradeRow($fixture['profile'], $passed->courseSpecification->course, '3.00', GradeRosterRow::CategoryPassing);
        $this->releasedGradeRow($fixture['profile'], $failed->courseSpecification->course, '5.00', GradeRosterRow::CategoryFailed);
        $this->releasedGradeRow($fixture['profile'], $inc->courseSpecification->course, 'INC', GradeRosterRow::CategoryIncomplete);
        $this->releasedGradeRow($fixture['profile'], $pending->courseSpecification->course, 'P', GradeRosterRow::CategoryPending);
        $this->releasedGradeRow($fixture['profile'], $unreleased->courseSpecification->course, '1.00', GradeRosterRow::CategoryPassing, released: false);

        $result = app(SubjectSuggestionService::class)->suggestForEnrollment($fixture['enrollment']);

        $alreadyPassed = collect($result['already_passed'])->firstWhere('code', 'TAL-PASS');
        $this->assertIsArray($alreadyPassed);
        $this->assertSame($passedRow->id, $alreadyPassed['latest_grade']['grade_roster_row_id']);
        $this->assertSame('3.00', $alreadyPassed['latest_grade']['grade']);

        $this->assertNotNull(collect($result['back_subjects'])->firstWhere('code', 'TAL-FAIL'));
        $this->assertSame(
            SubjectSuggestionService::BlockerActiveInc,
            collect(collect($result['blocked'])->firstWhere('code', 'TAL-INC')['blockers'])->first()['reason'],
        );
        $this->assertSame(
            SubjectSuggestionService::BlockerPendingGrade,
            collect(collect($result['blocked'])->firstWhere('code', 'TAL-PEND')['blockers'])->first()['reason'],
        );

        $temporaryPrerequisiteBlockers = collect(collect($result['blocked'])->firstWhere('code', 'TAL-TARGET')['blockers'])
            ->pluck('reason')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            SubjectSuggestionService::BlockerActiveInc,
            SubjectSuggestionService::BlockerPendingGrade,
        ], $temporaryPrerequisiteBlockers);
        $this->assertNotNull(collect($result['suggested'])->firstWhere('code', 'TAL-UNREL'));
    }

    /**
     * @return array{term:Term,termOffering:TermOffering,section:Section,faculty:User,student:User,profile:StudentProfile,courseEnrollment:CourseEnrollment}
     */
    private function gradeFixture(bool $linkedMeetings = false): array
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $student = User::factory()->create(['status' => User::StatusActive]);
        $student->assignRole('student');
        $profile = StudentProfile::factory()->create(['user_id' => $student->id]);
        $term = Term::factory()->create();
        $courseSpecification = CourseSpecification::factory()->create([
            'grading_profile_key' => 'servitech_v1',
            'grading_profile_version' => 1,
            'state' => CourseSpecification::StateActive,
        ]);
        $curriculumEntry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $profile->curriculum_version_id,
            'course_specification_id' => $courseSpecification->id,
        ]);
        $termOffering = TermOffering::factory()->create([
            'term_id' => $term->id,
            'curriculum_entry_id' => $curriculumEntry->id,
            'state' => TermOffering::StateScheduled,
        ]);
        $section = Section::factory()->create(['term_offering_id' => $termOffering->id]);
        $deliveryGroup = SectionDeliveryGroup::factory()->create(['section_id' => $section->id]);
        $component = CourseComponent::factory()->create(['course_specification_id' => $courseSpecification->id]);
        $demand = SchedulingDemand::query()->create([
            'term_offering_id' => $termOffering->id,
            'course_component_id' => $component->id,
            'section_delivery_group_id' => $deliveryGroup->id,
            'demand_key' => fake()->unique()->uuid(),
            'required_duration_minutes' => 180,
            'meeting_count' => 1,
            'modality' => TermOffering::ModalityOnline,
            'validation_state' => SchedulingDemand::ValidationReadyForReview,
        ]);
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'input_snapshot' => [],
            'input_hash' => hash('sha256', fake()->uuid()),
            'solver_version' => 'test',
            'published_at' => now(),
        ]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => now(),
        ]);
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $termOffering->id,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => 3,
            'added_at' => now(),
        ]);
        $meetings = $linkedMeetings ? [1, 2] : [1];

        foreach ($meetings as $sequence) {
            $meeting = SectionMeeting::query()->create([
                'schedule_run_id' => $run->id,
                'scheduling_demand_id' => $demand->id,
                'meeting_sequence' => $sequence,
                'faculty_user_id' => $faculty->id,
                'room_id' => null,
                'day_of_week' => $sequence,
                'starts_at' => '08:00',
                'ends_at' => '09:00',
                'modality' => TermOffering::ModalityOnline,
                'state' => SectionMeeting::StateActive,
                'published_at' => now(),
            ]);
            StudentScheduleBinding::query()->create([
                'course_enrollment_id' => $courseEnrollment->id,
                'section_meeting_id' => $meeting->id,
                'is_active' => true,
                'effective_from' => now()->toDateString(),
                'source' => StudentScheduleBinding::SourceRegistrarPlacement,
                'released_at' => now(),
            ]);
        }

        foreach (['prelim', 'midterm', 'final'] as $period) {
            CalendarEvent::factory()->create([
                'term_id' => $term->id,
                'event_type' => CalendarEvent::TypeWindow,
                'scope_type' => CalendarEvent::ScopeInstitution,
                'process_key' => "grade_encoding_$period",
                'start_at' => now()->subDay(),
                'end_at' => now()->addDay(),
                'state' => CalendarEvent::StateActive,
            ]);
        }

        return compact('term', 'termOffering', 'section', 'faculty', 'student', 'profile', 'courseEnrollment');
    }

    /**
     * @return array{enrollment:Enrollment,profile:StudentProfile}
     */
    private function subjectSuggestionFixture(): array
    {
        $student = User::factory()->create(['status' => User::StatusActive]);
        $student->assignRole('student');
        $profile = StudentProfile::factory()->create(['user_id' => $student->id]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => Term::factory()->create()->id,
        ]);
        $enrollment->setAttribute('year_level', 'First Year');

        return compact('enrollment', 'profile');
    }

    private function curriculumEntry(StudentProfile $profile, string $code, int $sequence): CurriculumEntry
    {
        $course = Course::factory()->create([
            'code' => $code,
        ]);
        $courseSpecification = CourseSpecification::factory()->create([
            'course_id' => $course->id,
            'title' => "$code subject",
            'grading_profile_key' => 'servitech_v1',
            'grading_profile_version' => 1,
            'state' => CourseSpecification::StateActive,
        ]);

        return CurriculumEntry::factory()->create([
            'curriculum_version_id' => $profile->curriculum_version_id,
            'course_specification_id' => $courseSpecification->id,
            'year_level' => 'First Year',
            'term_label' => 'First Semester',
            'term_type' => Term::TypeFirstSemester,
            'sequence' => $sequence,
        ])->load('courseSpecification.course');
    }

    private function prerequisite(CurriculumEntry $entry, CurriculumEntry $prerequisite, int $sequence): CourseRequirement
    {
        return CourseRequirement::factory()->create([
            'course_specification_id' => $entry->course_specification_id,
            'related_course_id' => $prerequisite->courseSpecification->course_id,
            'rule_type' => CourseRequirement::TypePrerequisite,
            'state' => CourseRequirement::StateActive,
            'sequence' => $sequence,
        ]);
    }

    private function releasedGradeRow(
        StudentProfile $profile,
        Course $course,
        string $outcomeCode,
        string $outcomeCategory,
        bool $released = true,
    ): GradeRosterRow {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $term = Term::factory()->create();
        $courseSpecification = CourseSpecification::factory()->create([
            'course_id' => $course->id,
            'grading_profile_key' => 'servitech_v1',
            'grading_profile_version' => 1,
            'state' => CourseSpecification::StateActive,
        ]);
        $curriculumEntry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $profile->curriculum_version_id,
            'course_specification_id' => $courseSpecification->id,
        ]);
        $termOffering = TermOffering::factory()->create([
            'term_id' => $term->id,
            'curriculum_entry_id' => $curriculumEntry->id,
            'state' => TermOffering::StateScheduled,
        ]);
        $section = Section::factory()->create(['term_offering_id' => $termOffering->id]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => now(),
        ]);
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $termOffering->id,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => 3,
            'added_at' => now(),
        ]);
        $roster = GradeRoster::factory()->create([
            'term_offering_id' => $termOffering->id,
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
            'state' => $released ? GradeRoster::StateReleased : GradeRoster::StateDraft,
            'released_at' => $released ? now() : null,
        ]);

        return GradeRosterRow::query()->create([
            'grade_roster_id' => $roster->id,
            'course_enrollment_id' => $courseEnrollment->id,
            'current_outcome_code' => $outcomeCode,
            'current_outcome_category' => $outcomeCategory,
            'released_at' => $released ? now() : null,
        ]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
