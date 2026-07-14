<?php

namespace Tests\Feature;

use App\Actions\Cor\BuildCorOutput;
use App\Actions\Scheduling\PublishedScheduleRevisionService;
use App\Filament\Pages\FacultySchedule;
use App\Filament\Student\Pages\ScheduleView;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\FacultyQualification;
use App\Models\Program;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\ScheduleRevisionEvent;
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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL94D3cScheduleProjectionAcceptanceTest extends TestCase
{
    use DatabaseTransactions;

    private int $fixtureCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

        foreach ([
            'applicant',
            'student',
            User::StaffRoleRegistrar,
            User::StaffRoleAccounting,
            User::StaffRoleFaculty,
            User::StaffRoleAcademicHead,
            User::StaffRoleSystemSuperAdmin,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Mail::fake();
    }

    #[Test]
    public function faculty_schedule_shows_only_owned_active_published_assignments(): void
    {
        $owned = $this->scheduleFixture();
        $otherFaculty = $this->staff(User::StaffRoleFaculty, 'Other Faculty');
        $other = $this->scheduleFixture(faculty: $otherFaculty);
        $candidate = $this->scheduleFixture(
            faculty: $owned['faculty'],
            runStatus: ScheduleGenerationRun::StatusUnderReview,
        );
        $cancelled = $this->scheduleFixture(
            faculty: $owned['faculty'],
            meetingState: SectionMeeting::StateCancelled,
        );

        Livewire::actingAs($owned['faculty'])
            ->test(FacultySchedule::class)
            ->assertCanSeeTableRecords([$owned['meeting']])
            ->assertCanNotSeeTableRecords([
                $other['meeting'],
                $candidate['meeting'],
                $cancelled['meeting'],
            ])
            ->assertSee($owned['term']->label)
            ->assertSee($owned['course']->code)
            ->assertSee($owned['section']->code)
            ->assertSee('Lecture')
            ->assertSee('Monday')
            ->assertSee('8:00 AM - 11:00 AM')
            ->assertSee($owned['room']->code)
            ->assertSee('Face-to-Face');
    }

    #[Test]
    public function student_schedule_is_owner_scoped_and_logs_only_an_available_official_view(): void
    {
        $owned = $this->scheduleFixture();
        $other = $this->scheduleFixture();

        Livewire::actingAs($owned['student'])
            ->test(ScheduleView::class)
            ->assertCanSeeTableRecords([$owned['binding']])
            ->assertCanNotSeeTableRecords([$other['binding']])
            ->assertSee($owned['term']->label)
            ->assertSee($owned['course']->code)
            ->assertSee($owned['section']->code)
            ->assertSee($owned['faculty']->name)
            ->assertSee('Monday')
            ->assertSee('8:00 AM - 11:00 AM')
            ->assertSee($owned['room']->code)
            ->assertSee('Face-to-Face');

        $this->assertDatabaseHas('output_access_logs', [
            'output_type' => 'STUDENT_SCHEDULE',
            'source_record_type' => Enrollment::class,
            'source_record_id' => $owned['enrollment']->id,
            'student_profile_id' => $owned['profile']->id,
            'actor_user_id' => $owned['student']->id,
            'actor_role' => 'student',
            'action' => 'VIEW',
            'copy_context' => 'STUDENT_COPY',
            'schedule_version' => 1,
            'row_count' => 1,
            'sensitivity' => 'student_record',
            'status' => 'logged',
        ]);

        $emptyStudent = $this->studentWithProfile();
        $logCount = DB::table('output_access_logs')->count();

        Livewire::actingAs($emptyStudent['student'])
            ->test(ScheduleView::class)
            ->assertCountTableRecords(0);

        $this->assertSame($logCount, DB::table('output_access_logs')->count());
    }

    #[Test]
    public function role_boundaries_separate_official_schedule_access_from_faculty_projection_access(): void
    {
        $roles = [
            User::StaffRoleRegistrar => [true, false],
            User::StaffRoleAcademicHead => [true, false],
            User::StaffRoleFaculty => [false, true],
            User::StaffRoleAccounting => [false, false],
            User::StaffRoleSystemSuperAdmin => [false, false],
        ];

        foreach ($roles as $role => [$officialAccess, $facultyProjectionAccess]) {
            $user = $this->staff($role, Str::headline($role));
            $this->actingAs($user);

            $this->assertSame(
                $officialAccess,
                Gate::forUser($user)->allows('viewAny', SectionMeeting::class),
                "{$role} official schedule access is incorrect.",
            );
            $this->assertSame(
                $facultyProjectionAccess,
                FacultySchedule::canAccess(),
                "{$role} faculty projection access is incorrect.",
            );
        }
    }

    #[Test]
    public function live_revision_is_reflected_in_faculty_student_and_cor_projections(): void
    {
        $fixture = $this->scheduleFixture();
        $registrar = $this->staff(User::StaffRoleRegistrar, 'Revision Registrar');
        $replacementRoom = Room::factory()->create([
            'code' => 'D3C-R'.$this->fixtureCounter.'B',
            'name' => 'Replacement Room',
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);
        $replacementFaculty = $this->staff(User::StaffRoleFaculty, 'Replacement Faculty');
        FacultyQualification::factory()
            ->for($replacementFaculty, 'faculty')
            ->for($fixture['course'])
            ->create(['is_active' => true]);

        app(PublishedScheduleRevisionService::class)->revise(
            $fixture['run'],
            $registrar,
            ScheduleRevisionEvent::ChangeRoom,
            [[
                'section_meeting_id' => $fixture['meeting']->id,
                'room_id' => $replacementRoom->id,
            ]],
            'Room replacement approved for cross-role acceptance.',
        );
        app(PublishedScheduleRevisionService::class)->revise(
            $fixture['run'],
            $registrar,
            ScheduleRevisionEvent::ChangeTime,
            [[
                'section_meeting_id' => $fixture['meeting']->id,
                'day_of_week' => 2,
                'starts_at' => '09:00:00',
                'ends_at' => '12:00:00',
            ]],
            'Time replacement approved for cross-role acceptance.',
        );
        app(PublishedScheduleRevisionService::class)->revise(
            $fixture['run'],
            $registrar,
            ScheduleRevisionEvent::ChangeFacultyReassignment,
            [[
                'section_meeting_id' => $fixture['meeting']->id,
                'faculty_user_id' => $replacementFaculty->id,
            ]],
            'Faculty replacement approved for cross-role acceptance.',
        );

        Livewire::actingAs($fixture['faculty'])
            ->test(FacultySchedule::class)
            ->assertCanNotSeeTableRecords([$fixture['meeting']]);

        Livewire::actingAs($replacementFaculty)
            ->test(FacultySchedule::class)
            ->assertCanSeeTableRecords([$fixture['meeting']])
            ->assertSee('Tuesday')
            ->assertSee('9:00 AM - 12:00 PM')
            ->assertSee($replacementRoom->code);

        Livewire::actingAs($fixture['student'])
            ->test(ScheduleView::class)
            ->assertCanSeeTableRecords([$fixture['binding']])
            ->assertSee($replacementFaculty->name)
            ->assertSee('Tuesday')
            ->assertSee('9:00 AM - 12:00 PM')
            ->assertSee($replacementRoom->code);

        $cor = app(BuildCorOutput::class)->forStudent($fixture['student']);

        $this->assertTrue($cor['available']);
        $this->assertSame('Tuesday', $cor['subjects'][0]['day']);
        $this->assertSame('09:00-12:00', $cor['subjects'][0]['time']);
        $this->assertSame($replacementRoom->code, $cor['subjects'][0]['room']);
        $this->assertSame($replacementFaculty->name, $cor['subjects'][0]['instructor']);
    }

    #[Test]
    public function whole_section_cancellation_disappears_from_projections_and_cor_uses_current_live_state(): void
    {
        $fixture = $this->scheduleFixture();
        $registrar = $this->staff(User::StaffRoleRegistrar, 'Cancellation Registrar');

        Livewire::actingAs($fixture['faculty'])
            ->test(FacultySchedule::class)
            ->assertCanSeeTableRecords([$fixture['meeting']]);
        Livewire::actingAs($fixture['student'])
            ->test(ScheduleView::class)
            ->assertCanSeeTableRecords([$fixture['binding']]);

        $fixture['binding']->forceFill([
            'is_active' => false,
            'effective_until' => now()->toDateString(),
            'released_by' => $registrar->id,
            'released_at' => now(),
            'release_reason' => 'Placement released before section cancellation.',
        ])->save();

        app(PublishedScheduleRevisionService::class)->cancelSection(
            $fixture['run'],
            $fixture['section'],
            $registrar,
            'Section cancellation approved for cross-role acceptance.',
        );

        Livewire::actingAs($fixture['faculty'])
            ->test(FacultySchedule::class)
            ->assertCanNotSeeTableRecords([$fixture['meeting']]);
        Livewire::actingAs($fixture['student'])
            ->test(ScheduleView::class)
            ->assertCanNotSeeTableRecords([$fixture['binding']]);

        $cor = app(BuildCorOutput::class)->forStudent($fixture['student']);

        $this->assertTrue($cor['available']);
        $this->assertSame('Unscheduled', $cor['subjects'][0]['day']);
        $this->assertSame('Unscheduled', $cor['subjects'][0]['time']);
        $this->assertSame('TBA', $cor['subjects'][0]['room']);
        $this->assertSame('TBA', $cor['subjects'][0]['instructor']);
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleFixture(
        ?User $student = null,
        ?User $faculty = null,
        string $runStatus = ScheduleGenerationRun::StatusPublished,
        string $meetingState = SectionMeeting::StateActive,
    ): array {
        $this->fixtureCounter++;
        $studentContext = $student instanceof User
            ? $this->studentContextFor($student)
            : $this->studentWithProfile();
        $faculty ??= $this->staff(User::StaffRoleFaculty, 'Faculty '.$this->fixtureCounter);
        $registrar = $this->staff(User::StaffRoleRegistrar, 'Registrar '.$this->fixtureCounter);
        $term = Term::factory()->create([
            'label' => 'D3C Term '.$this->fixtureCounter,
            'state' => Term::StateActive,
            'default_max_units' => 21.00,
        ]);
        $curriculum = CurriculumVersion::factory()
            ->for($studentContext['program'])
            ->create(['state' => CurriculumVersion::StateActive]);
        $studentContext['profile']->forceFill(['curriculum_version_id' => $curriculum->id])->save();
        $course = Course::factory()->create(['code' => 'D3C'.$this->fixtureCounter]);
        $specification = CourseSpecification::factory()->for($course)->create([
            'title' => 'Schedule Projection '.$this->fixtureCounter,
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
            'credit_units' => 3.00,
            'same_faculty_default' => true,
        ]);
        $component = CourseComponent::factory()->for($specification)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 3.00,
            'room_type_default' => Room::TypeLectureRoom,
            'required_room_feature_keys' => [],
            'requires_consecutive_block' => false,
            'sequence' => 1,
        ]);
        $entry = CurriculumEntry::factory()
            ->for($curriculum)
            ->for($specification, 'courseSpecification')
            ->create([
                'term_type' => $term->type,
                'term_label' => $term->label,
                'sequence' => 1,
            ]);
        $offering = TermOffering::factory()
            ->for($term)
            ->for($entry, 'curriculumEntry')
            ->create([
                'modality' => TermOffering::ModalityFaceToFace,
                'state' => TermOffering::StateScheduled,
                'expected_count' => 30,
            ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'D3C-S'.$this->fixtureCounter,
            'capacity' => 30,
            'state' => Section::StateOpen,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'D3C Group '.$this->fixtureCounter,
            'expected_count' => 30,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $enrollment = Enrollment::factory()
            ->for($studentContext['profile'])
            ->for($term)
            ->create([
                'status' => 'officially_enrolled',
                'registered_at' => now()->subDay(),
                'officially_enrolled_at' => now(),
            ]);
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);
        FacultyQualification::factory()
            ->for($faculty, 'faculty')
            ->for($course)
            ->create(['is_active' => true]);
        $demand = SchedulingDemand::factory()
            ->for($offering)
            ->for($component)
            ->for($group)
            ->create([
                'required_duration_minutes' => 180,
                'meeting_count' => 1,
                'modality' => TermOffering::ModalityFaceToFace,
                'validation_state' => SchedulingDemand::ValidationReadyForReview,
            ]);
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => $runStatus,
            'requested_by' => $registrar->id,
            'input_snapshot' => [
                'scheduling_demands' => [['scheduling_demand_id' => $demand->id]],
            ],
            'input_hash' => hash('sha256', (string) Str::uuid()),
            'solver_version' => 'tal94d3c-test-solver',
            'published_by' => $runStatus === ScheduleGenerationRun::StatusPublished ? $registrar->id : null,
            'published_at' => $runStatus === ScheduleGenerationRun::StatusPublished ? now()->subDay() : null,
            'publication_version' => $runStatus === ScheduleGenerationRun::StatusPublished ? 1 : 0,
        ]);
        $room = Room::factory()->create([
            'code' => 'D3C-R'.$this->fixtureCounter.'A',
            'name' => 'Projection Room '.$this->fixtureCounter,
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);
        $meeting = SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '11:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => $meetingState,
            'published_at' => now()->subDay(),
        ]);
        $binding = StudentScheduleBinding::query()->create([
            'course_enrollment_id' => $courseEnrollment->id,
            'section_meeting_id' => $meeting->id,
            'is_active' => true,
            'effective_from' => now()->toDateString(),
            'source' => StudentScheduleBinding::SourceRegistrarPlacement,
        ]);

        return [
            ...$studentContext,
            'faculty' => $faculty,
            'registrar' => $registrar,
            'term' => $term,
            'course' => $course,
            'offering' => $offering,
            'section' => $section,
            'group' => $group,
            'enrollment' => $enrollment,
            'courseEnrollment' => $courseEnrollment,
            'demand' => $demand,
            'run' => $run,
            'room' => $room,
            'meeting' => $meeting,
            'binding' => $binding,
        ];
    }

    /**
     * @return array{student: User, profile: StudentProfile, program: Program}
     */
    private function studentWithProfile(): array
    {
        return $this->studentContextFor($this->userForRole('student', 'Student '.$this->fixtureCounter));
    }

    /**
     * @return array{student: User, profile: StudentProfile, program: Program}
     */
    private function studentContextFor(User $student): array
    {
        $program = Program::factory()->create(['code' => 'D3CP'.$student->id]);
        $profile = StudentProfile::factory()
            ->for($student)
            ->for($program)
            ->create([
                'student_number' => 'D3C-'.str_pad((string) $student->id, 6, '0', STR_PAD_LEFT),
                'lifecycle_status' => StudentProfile::LifecycleActive,
            ]);

        return compact('student', 'profile', 'program');
    }

    private function staff(string $role, string $name): User
    {
        return $this->userForRole($role, $name);
    }

    private function userForRole(string $role, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'status' => User::StatusActive,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
