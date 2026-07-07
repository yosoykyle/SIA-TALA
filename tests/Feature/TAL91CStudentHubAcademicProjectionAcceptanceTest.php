<?php

namespace Tests\Feature;

use App\Actions\StudentHub\StudentHubPriorityResolver;
use App\Filament\Student\Pages\ScheduleView;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\EnrollmentGateResult;
use App\Models\Program;
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
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TAL-91C: Academic Outputs Projection Acceptance (third sub-slice of TAL-91).
 *
 * Owning contract: PRD `00_Project_Documents/prd_modules/12_student_hub.md`
 * §12.2 (Student Hub Display Priority tier 4 "Capacity Pending" and rule 8
 * "Pending Review" gate-reason surfacing), §12.3 rule 3 (schedules derive
 * from active published schedule). Also acceptance-covers the `ScheduleView`
 * published+active `sectionMeeting` filter fix (regression for the bug where
 * candidate/unpublished or superseded meetings could leak onto the
 * standalone Schedule page).
 *
 * This sub-slice reuses already-persisted `EnrollmentGateResult` rows via
 * `EnrollmentGateReviewSummary`; it does not add any new gate-evaluation
 * logic. COR is acceptance-only here and already covered by TAL-70/TAL-88A.
 */
final class TAL91CStudentHubAcademicProjectionAcceptanceTest extends TestCase
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
    public function schedule_view_shows_only_the_acting_students_own_bindings(): void
    {
        $fixtureA = $this->publishedScheduleFixture();
        $fixtureB = $this->publishedScheduleFixture();

        Livewire::actingAs($fixtureA['student'])
            ->test(ScheduleView::class)
            ->assertCanSeeTableRecords([$fixtureA['binding']])
            ->assertCanNotSeeTableRecords([$fixtureB['binding']]);
    }

    #[Test]
    public function schedule_view_excludes_candidate_unpublished_schedule_runs(): void
    {
        $fixture = $this->publishedScheduleFixture();
        $unpublishedBinding = $this->scheduleBindingWithRunStatus(
            $fixture['student'],
            $fixture['program'],
            $fixture['term'],
            ScheduleGenerationRun::StatusUnderReview,
            $fixture['enrollment'],
        );

        Livewire::actingAs($fixture['student'])
            ->test(ScheduleView::class)
            ->assertCanSeeTableRecords([$fixture['binding']])
            ->assertCanNotSeeTableRecords([$unpublishedBinding]);
    }

    #[Test]
    public function schedule_view_excludes_meetings_whose_state_is_not_active(): void
    {
        // `SectionMeeting` defines only `StateActive`; any other state value (e.g. a
        // superseded meeting record replaced by a later solver run) must not leak.
        $fixture = $this->publishedScheduleFixture();
        $superseded = $this->scheduleBindingWithMeetingState(
            $fixture['student'],
            $fixture['program'],
            $fixture['term'],
            'superseded',
            $fixture['enrollment'],
        );

        Livewire::actingAs($fixture['student'])
            ->test(ScheduleView::class)
            ->assertCanSeeTableRecords([$fixture['binding']])
            ->assertCanNotSeeTableRecords([$superseded]);
    }

    #[Test]
    public function capacity_pending_tier_outranks_informational_notices(): void
    {
        $student = $this->studentUser();
        $profile = StudentProfile::factory()->create(['user_id' => $student->id]);
        $term = Term::factory()->create(['label' => 'First Semester 2026-2027']);
        Enrollment::factory()->for($profile)->for($term)->create([
            'status' => 'capacity_pending',
            'registered_at' => now()->subDay(),
        ]);

        $result = app(StudentHubPriorityResolver::class)->resolve($profile);

        $this->assertNotNull($result);
        $this->assertSame('Capacity Pending', $result['tier']);
        $this->assertSame('Registrar Office', $result['office_to_contact']);
        $this->assertStringContainsString('placement', $result['student_reason']);
        $this->assertStringContainsString('Registrar', $result['student_reason']);
    }

    #[Test]
    public function pending_review_tier_surfaces_the_highest_priority_unresolved_gate(): void
    {
        $student = $this->studentUser();
        $profile = StudentProfile::factory()->create(['user_id' => $student->id]);
        $term = Term::factory()->create(['label' => 'First Semester 2026-2027']);
        $enrollment = Enrollment::factory()->for($profile)->for($term)->create([
            'status' => 'pending_review',
            'registered_at' => now()->subDay(),
        ]);

        // Higher-sequence (lower priority) failed gate.
        EnrollmentGateResult::query()->create([
            'enrollment_id' => $enrollment->id,
            'gate_type' => EnrollmentGateResult::GateFinance,
            'sequence' => 4,
            'result' => EnrollmentGateResult::ResultFailed,
            'responsible_office' => EnrollmentGateResult::ResponsibleOfficeAccounting,
            'blocker_code' => 'finance_not_ready',
            'blocker_message' => 'Finance gate requires posted ledger payment or active approved accommodation.',
            'checked_at' => now(),
            'rule_version' => EnrollmentGateResult::RuleVersionTal87C,
        ]);

        // Lower-sequence (higher priority) failed gate — should win.
        EnrollmentGateResult::query()->create([
            'enrollment_id' => $enrollment->id,
            'gate_type' => EnrollmentGateResult::GateDocument,
            'sequence' => 3,
            'result' => EnrollmentGateResult::ResultFailed,
            'responsible_office' => EnrollmentGateResult::ResponsibleOfficeRegistrar,
            'blocker_code' => 'blocking_document_unresolved',
            'blocker_message' => 'A blocking enrollment document remains unresolved: Certificate of Good Moral Character.',
            'checked_at' => now(),
            'rule_version' => EnrollmentGateResult::RuleVersionTal87C,
        ]);

        $result = app(StudentHubPriorityResolver::class)->resolve($profile);

        $this->assertNotNull($result);
        $this->assertSame('Pending Review', $result['tier']);
        $this->assertSame(
            'A blocking enrollment document remains unresolved: Certificate of Good Moral Character.',
            $result['student_reason'],
        );
        $this->assertSame('Registrar Office', $result['office_to_contact']);
        $this->assertStringNotContainsString('finance_not_ready', (string) $result['student_reason']);
        $this->assertStringNotContainsString('blocking_document_unresolved', (string) $result['student_reason']);
    }

    /**
     * @return array{student:User, profile:StudentProfile, program:Program, term:Term, enrollment:Enrollment, binding:StudentScheduleBinding}
     */
    private function publishedScheduleFixture(): array
    {
        $student = $this->studentUser();
        $program = Program::factory()->create(['code' => fake()->unique()->bothify('BSIT####')]);
        $profile = StudentProfile::factory()->for($student)->for($program)->create([
            'student_number' => 'SIA-2026-'.fake()->unique()->numerify('####'),
        ]);
        $term = Term::factory()->create(['label' => 'First Semester 2026-2027']);
        $enrollment = Enrollment::factory()->for($profile)->for($term)->create([
            'status' => 'officially_enrolled',
            'registered_at' => now()->subDay(),
            'officially_enrolled_at' => now(),
        ]);

        $binding = $this->scheduleBindingWithRunStatus($student, $program, $term, ScheduleGenerationRun::StatusPublished, $enrollment);

        return [
            'student' => $student,
            'profile' => $profile,
            'program' => $program,
            'term' => $term,
            'enrollment' => $enrollment,
            'binding' => $binding,
        ];
    }

    private function scheduleBindingWithRunStatus(
        User $student,
        Program $program,
        Term $term,
        string $runStatus,
        ?Enrollment $enrollment = null,
    ): StudentScheduleBinding {
        return $this->scheduleBinding($student, $program, $term, $runStatus, SectionMeeting::StateActive, $enrollment);
    }

    private function scheduleBindingWithMeetingState(
        User $student,
        Program $program,
        Term $term,
        string $meetingState,
        ?Enrollment $enrollment = null,
    ): StudentScheduleBinding {
        return $this->scheduleBinding($student, $program, $term, ScheduleGenerationRun::StatusPublished, $meetingState, $enrollment);
    }

    private function scheduleBinding(
        User $student,
        Program $program,
        Term $term,
        string $runStatus,
        string $meetingState,
        ?Enrollment $enrollment = null,
    ): StudentScheduleBinding {
        if (! $enrollment instanceof Enrollment) {
            $profile = StudentProfile::factory()->for($student)->for($program)->create([
                'student_number' => 'SIA-2026-'.fake()->unique()->numerify('####'),
            ]);
            $enrollment = Enrollment::factory()->for($profile)->for($term)->create([
                'status' => 'officially_enrolled',
                'registered_at' => now()->subDay(),
                'officially_enrolled_at' => now(),
            ]);
        }

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
            'input_hash' => hash('sha256', uniqid('tal91c', true)),
            'solver_version' => 'tal91c-test',
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

        return StudentScheduleBinding::query()->create([
            'course_enrollment_id' => $courseEnrollment->id,
            'section_meeting_id' => $meeting->id,
            'is_active' => true,
            'effective_from' => now()->toDateString(),
            'source' => StudentScheduleBinding::SourceRegistrarPlacement,
        ]);
    }

    private function studentUser(): User
    {
        $user = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $user->assignRole('student');

        return $user;
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);

        return $user;
    }
}
