<?php

namespace Tests\Feature;

use App\Actions\StudentHub\StudentHubPriorityResolver;
use App\Models\Assessment;
use App\Models\ChecklistItem;
use App\Models\CorVersion;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\Hold;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationProposalVersion;
use App\Models\Room;
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
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TAL-91E: Student Hub Display Priority Completion (final sub-slice of parent
 * TAL-91). Implements the last 5 of 11 ranked notice tiers in
 * `StudentHubPriorityResolver` (PRD `12_student_hub.md` §12.2 ranks 6-10),
 * deferred out of TAL-91D by explicit user decision.
 *
 * Owning contract: PRD `00_Project_Documents/prd_modules/12_student_hub.md`
 * §12.2 (Student Hub Display Priority, the full 11-rank list and rules 1-16).
 *
 * All 5 tiers reuse already-existing, already-student-exposed data sources
 * from prior slices (TAL-91A/89D/91C/91D): `ChecklistItem::isResolved()`,
 * `StudentProfile::$academic_standing`, the exact published+active schedule
 * filter shape from `StudentDashboardService::scheduleFor()`,
 * `BuildCorOutput::forStudent()` directly, and the released-grade existence
 * filter shape from `GradesView::releasedGradesQuery()`.
 */
final class TAL91EStudentHubDisplayPriorityCompletionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_test_codex', DB::connection()->getDatabaseName());

        foreach (['student', User::StaffRoleRegistrar] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function missing_requirements_tier_fires_for_an_unresolved_own_checklist_item(): void
    {
        [, $profile] = $this->studentWithProfile();

        ChecklistItem::factory()->create([
            'owner_type' => ChecklistItem::OwnerStudent,
            'student_profile_id' => $profile->id,
            'applicant_intake_id' => null,
            'requirement_type' => 'Certificate of Good Moral Character',
            'status' => ChecklistItem::StatusPending,
            'blocking_level' => ChecklistItem::BlockingAdvisoryOnly,
            'verification_status' => ChecklistItem::VerificationNotReviewed,
        ]);

        $result = app(StudentHubPriorityResolver::class)->resolve($profile);

        $this->assertNotNull($result);
        $this->assertSame('Missing Requirements', $result['tier']);
        $this->assertStringContainsString('Certificate of Good Moral Character', $result['student_reason']);
        $this->assertSame('Registrar Office', $result['office_to_contact']);
    }

    #[Test]
    public function active_academic_deficiency_tier_fires_for_a_deficient_standing(): void
    {
        [, $profile] = $this->studentWithProfile([
            'academic_standing' => StudentProfile::StandingProbationary,
        ]);

        $result = app(StudentHubPriorityResolver::class)->resolve($profile);

        $this->assertNotNull($result);
        $this->assertSame('Active Academic Deficiency', $result['tier']);
        $this->assertStringContainsString(StudentProfile::StandingProbationary, $result['student_reason']);
        $this->assertSame('Academic Head Office', $result['office_to_contact']);
    }

    #[Test]
    public function schedule_available_tier_fires_for_a_published_active_binding(): void
    {
        $fixture = $this->publishedScheduleFixture();

        $result = app(StudentHubPriorityResolver::class)->resolve($fixture['profile']);

        $this->assertNotNull($result);
        $this->assertSame('Schedule Available', $result['tier']);
        $this->assertNull($result['required_action']);
        $this->assertNull($result['office_to_contact']);
    }

    #[Test]
    public function cor_available_tier_fires_for_a_current_official_enrollment(): void
    {
        $fixture = $this->officiallyEnrolledFixture();

        $result = app(StudentHubPriorityResolver::class)->resolve($fixture['profile']);

        $this->assertNotNull($result);
        $this->assertSame('COR Available', $result['tier']);
        $this->assertNull($result['required_action']);
        $this->assertNull($result['office_to_contact']);
    }

    #[Test]
    public function grades_released_tier_fires_for_a_released_own_grade_roster_row(): void
    {
        $fixture = $this->releasedGradeFixture();

        $result = app(StudentHubPriorityResolver::class)->resolve($fixture['profile']);

        $this->assertNotNull($result);
        $this->assertSame('Grades Released', $result['tier']);
        $this->assertNull($result['required_action']);
        $this->assertNull($result['office_to_contact']);
    }

    #[Test]
    public function enrollment_blocking_hold_outranks_all_five_new_tiers(): void
    {
        $fixture = $this->releasedGradeFixture();
        $profile = $fixture['profile'];

        // Stack every new-tier signal alongside a higher-priority enrollment-blocking hold.
        $this->attachUnresolvedChecklistItem($profile);
        $profile->forceFill(['academic_standing' => StudentProfile::StandingDeficient])->save();

        Hold::factory()->create([
            'student_profile_id' => $profile->id,
            'status' => Hold::StatusActive,
            'hold_type' => Hold::TypeDocumentary,
            'blocking_level' => Hold::BlockingEnrollment,
            'student_message' => 'Your enrollment is on hold pending clearance.',
        ]);

        $result = app(StudentHubPriorityResolver::class)->resolve($profile);

        $this->assertNotNull($result);
        $this->assertSame('Enrollment Blocked', $result['tier']);
    }

    #[Test]
    public function tier_6_missing_requirements_outranks_tier_7_academic_deficiency(): void
    {
        [, $profile] = $this->studentWithProfile([
            'academic_standing' => StudentProfile::StandingDeficient,
        ]);
        $this->attachUnresolvedChecklistItem($profile);

        $result = app(StudentHubPriorityResolver::class)->resolve($profile);

        $this->assertNotNull($result);
        $this->assertSame('Missing Requirements', $result['tier']);
    }

    #[Test]
    public function tier_7_academic_deficiency_outranks_tier_8_schedule_available(): void
    {
        $fixture = $this->publishedScheduleFixture();
        $fixture['profile']->forceFill(['academic_standing' => StudentProfile::StandingMustRepeatYear])->save();

        $result = app(StudentHubPriorityResolver::class)->resolve($fixture['profile']);

        $this->assertNotNull($result);
        $this->assertSame('Active Academic Deficiency', $result['tier']);
    }

    #[Test]
    public function tier_8_schedule_available_outranks_tier_9_cor_available(): void
    {
        $fixture = $this->officiallyEnrolledFixture();

        $this->scheduleBindingFor(
            $fixture['student'],
            $fixture['program'],
            $fixture['term'],
            $fixture['enrollment'],
            ScheduleGenerationRun::StatusPublished,
            SectionMeeting::StateActive,
        );

        $result = app(StudentHubPriorityResolver::class)->resolve($fixture['profile']);

        $this->assertNotNull($result);
        $this->assertSame('Schedule Available', $result['tier']);
    }

    #[Test]
    public function tier_9_cor_available_outranks_tier_10_grades_released(): void
    {
        $fixture = $this->officiallyEnrolledFixture();

        $this->releasedGradeRowFor($fixture['enrollment']);

        $result = app(StudentHubPriorityResolver::class)->resolve($fixture['profile']);

        $this->assertNotNull($result);
        $this->assertSame('COR Available', $result['tier']);
    }

    #[Test]
    public function informational_notice_does_not_outrank_any_of_the_five_new_tiers(): void
    {
        [$student, $profile] = $this->studentWithProfile();
        $this->createReadNotification($student);
        $this->attachUnresolvedChecklistItem($profile);

        $result = app(StudentHubPriorityResolver::class)->resolve($profile);

        $this->assertNotNull($result);
        $this->assertSame('Missing Requirements', $result['tier']);
        $this->assertNotSame('Informational Notice', $result['tier']);
    }

    #[Test]
    public function own_records_isolation_prevents_cross_student_new_tier_leakage(): void
    {
        [, $profileA] = $this->studentWithProfile();

        [, $profileB] = $this->studentWithProfile([
            'academic_standing' => StudentProfile::StandingDeficient,
        ]);
        $this->attachUnresolvedChecklistItem($profileB);

        $otherSchedule = $this->publishedScheduleFixture();
        $otherCor = $this->officiallyEnrolledFixture();
        $this->releasedGradeRowFor($otherCor['enrollment']);

        $result = app(StudentHubPriorityResolver::class)->resolve($profileA);

        $this->assertNull($result);
    }

    #[Test]
    public function irregular_standing_alone_does_not_trigger_academic_deficiency_tier(): void
    {
        [, $profile] = $this->studentWithProfile([
            'academic_standing' => StudentProfile::StandingIrregular,
        ]);

        $result = app(StudentHubPriorityResolver::class)->resolve($profile);

        $this->assertNull($result);
    }

    /**
     * @param  array<string,mixed>  $profileAttributes
     * @return array{0: User, 1: StudentProfile}
     */
    private function studentWithProfile(array $profileAttributes = []): array
    {
        $student = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $student->assignRole('student');

        $profile = StudentProfile::factory()->create(array_merge([
            'user_id' => $student->id,
        ], $profileAttributes));

        return [$student, $profile];
    }

    private function attachUnresolvedChecklistItem(StudentProfile $profile): ChecklistItem
    {
        return ChecklistItem::factory()->create([
            'owner_type' => ChecklistItem::OwnerStudent,
            'student_profile_id' => $profile->id,
            'applicant_intake_id' => null,
            'requirement_type' => 'Certificate of Good Moral Character',
            'status' => ChecklistItem::StatusPending,
            'blocking_level' => ChecklistItem::BlockingAdvisoryOnly,
            'verification_status' => ChecklistItem::VerificationNotReviewed,
        ]);
    }

    private function createReadNotification(User $user, string $title = 'Notice', string $body = 'You have a notice.'): void
    {
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\TestNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['title' => $title, 'body' => $body], JSON_THROW_ON_ERROR),
            'read_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{student:User, profile:StudentProfile, program:Program, term:Term, enrollment:Enrollment}
     */
    private function officiallyEnrolledFixture(): array
    {
        [$student, $profile] = $this->studentWithProfile();
        $program = Program::factory()->create(['code' => fake()->unique()->bothify('BSIT####')]);
        $profile->forceFill(['program_id' => $program->id])->save();
        $term = Term::factory()->create([
            'label' => 'First Semester 2026-2027',
            'state' => Term::StateActive,
        ]);
        $enrollment = Enrollment::factory()->for($profile)->for($term)->create([
            'status' => 'officially_enrolled',
            'registered_at' => now()->subDay(),
            'officially_enrolled_at' => now(),
        ]);
        $timetable = PublishedTimetableVersion::factory()->for($term)->create();
        $assessment = Assessment::factory()->create([
            'enrollment_id' => $enrollment->id,
            'term_account_id' => null,
        ]);
        $proposal = RegistrationProposalVersion::factory()->create([
            'enrollment_id' => $enrollment->id,
            'state' => RegistrationProposalVersion::StateConfirmed,
            'published_timetable_version_id' => $timetable->id,
            'curriculum_version_id' => $profile->curriculum_version_id,
        ]);
        $snapshot = [
            'student_number' => $profile->student_number,
            'student_name' => collect([$profile->first_name, $profile->last_name])->filter()->implode(' '),
            'program_id' => $program->id,
            'program_code' => $program->code,
            'curriculum_version_id' => $profile->curriculum_version_id,
            'term_label' => $term->label,
            'published_timetable_version_id' => $timetable->id,
            'courses' => [],
            'fees' => [],
        ];
        $cor = CorVersion::query()->create([
            'enrollment_id' => $enrollment->id,
            'version' => 1,
            'registration_proposal_version_id' => $proposal->id,
            'assessment_id' => $assessment->id,
            'published_timetable_version_id' => $timetable->id,
            'snapshot' => $snapshot,
            'content_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'issued_by' => $this->staff(User::StaffRoleRegistrar)->id,
            'issued_at' => now(),
        ]);
        $enrollment->update([
            'credential_user_id' => $student->id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'current_proposal_version_id' => $proposal->id,
            'current_cor_version_id' => $cor->id,
        ]);

        return [
            'student' => $student,
            'profile' => $profile,
            'program' => $program,
            'term' => $term,
            'enrollment' => $enrollment,
        ];
    }

    /**
     * @return array{student:User, profile:StudentProfile, program:Program, term:Term, enrollment:Enrollment, binding:StudentScheduleBinding}
     */
    private function publishedScheduleFixture(): array
    {
        $fixture = $this->officiallyEnrolledFixture();

        $binding = $this->scheduleBindingFor(
            $fixture['student'],
            $fixture['program'],
            $fixture['term'],
            $fixture['enrollment'],
            ScheduleGenerationRun::StatusPublished,
            SectionMeeting::StateActive,
        );

        return [...$fixture, 'binding' => $binding];
    }

    private function scheduleBindingFor(
        User $student,
        Program $program,
        Term $term,
        Enrollment $enrollment,
        string $runStatus,
        string $meetingState,
    ): StudentScheduleBinding {
        $curriculumEntry = CurriculumEntry::factory()
            ->for($enrollment->studentProfile->curriculumVersion ?? CurriculumVersion::factory()->for($program))
            ->create([
                'year_level' => '1',
                'term_label' => 'First Semester',
            ]);
        $offering = TermOffering::factory()->for($term)->for($curriculumEntry)->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => TermOffering::StateScheduled,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => fake()->unique()->bothify('BSIT-1?'),
            'state' => Section::StateOpen,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'Regular Block',
            'modality' => TermOffering::ModalityFaceToFace,
        ]);
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => $runStatus,
            'input_snapshot' => [],
            'input_hash' => hash('sha256', uniqid('tal91e', true)),
            'solver_version' => 'tal91e-test',
            'published_by' => $this->staff(User::StaffRoleRegistrar)->id,
            'published_at' => now(),
            'publication_version' => 1,
        ]);
        $demand = SchedulingDemand::factory()
            ->for($offering)
            ->for(CourseComponent::factory()->for($curriculumEntry->courseSpecification))
            ->for($group)
            ->create(['modality' => TermOffering::ModalityFaceToFace]);
        $faculty = User::factory()->create(['name' => 'Teacher One', 'status' => User::StatusActive]);
        $room = Room::factory()->create(['code' => fake()->unique()->bothify('R###')]);
        $meeting = SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => $meetingState,
            'published_at' => now(),
        ]);
        $timetable = PublishedTimetableVersion::query()
            ->where('term_id', $term->id)
            ->where('state', PublishedTimetableVersion::StatePublished)
            ->latest('version')
            ->firstOrFail();
        $courseEnrollment->update([
            'section_id' => $section->id,
            'published_timetable_version_id' => $timetable->id,
            'is_current' => true,
        ]);
        if ($runStatus === ScheduleGenerationRun::StatusPublished && $meetingState === SectionMeeting::StateActive) {
            PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
                'section_id' => $section->id,
                'faculty_user_id' => $faculty->id,
                'room_id' => $room->id,
                'day_of_week' => 1,
                'starts_at' => '08:00:00',
                'ends_at' => '10:00:00',
                'modality' => TermOffering::ModalityFaceToFace,
            ]);
        }

        return StudentScheduleBinding::query()->create([
            'course_enrollment_id' => $courseEnrollment->id,
            'section_meeting_id' => $meeting->id,
            'is_active' => true,
            'effective_from' => now()->toDateString(),
            'source' => StudentScheduleBinding::SourceRegistrarPlacement,
        ]);
    }

    /**
     * @return array{student:User, profile:StudentProfile, program:Program, term:Term, enrollment:Enrollment}
     */
    private function releasedGradeFixture(): array
    {
        [$student, $profile] = $this->studentWithProfile();
        $program = Program::factory()->create(['code' => fake()->unique()->bothify('BSIT####')]);
        $profile->forceFill(['program_id' => $program->id])->save();
        $term = Term::factory()->create(['label' => 'First Semester 2026-2027']);
        // Deliberately not "officially_enrolled" so the COR Available tier (9) does not
        // also fire and outrank the Grades Released tier (10) under test here.
        $enrollment = Enrollment::factory()->for($profile)->for($term)->create([
            'status' => 'completed',
            'registered_at' => now()->subYear(),
            'officially_enrolled_at' => now()->subYear(),
        ]);

        $this->releasedGradeRowFor($enrollment);

        return [
            'student' => $student,
            'profile' => $profile,
            'program' => $program,
            'term' => $term,
            'enrollment' => $enrollment,
        ];
    }

    private function releasedGradeRowFor(Enrollment $enrollment): GradeRosterRow
    {
        $curriculumEntry = CurriculumEntry::factory()
            ->for($enrollment->studentProfile->curriculumVersion ?? CurriculumVersion::factory()->for($enrollment->studentProfile->program))
            ->create([
                'year_level' => '1',
                'term_label' => 'First Semester',
            ]);
        $offering = TermOffering::factory()->for($enrollment->term)->for($curriculumEntry)->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => TermOffering::StateScheduled,
        ]);
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);
        $roster = GradeRoster::factory()->for($offering)->create();

        return GradeRosterRow::query()->create([
            'grade_roster_id' => $roster->id,
            'course_enrollment_id' => $courseEnrollment->id,
            'current_outcome_code' => '1.75',
            'current_outcome_category' => 'FINAL',
            'released_at' => now(),
        ]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);

        return $user;
    }
}
