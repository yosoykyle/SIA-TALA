<?php

namespace Tests\Feature;

use App\Actions\Cor\BuildCorOutput;
use App\Actions\Scheduling\PublishedScheduleRevisionService;
use App\Actions\Scheduling\ResolveTimetableRevisionRegistrationImpact;
use App\Actions\StudentHub\StudentDashboardService;
use App\Filament\Pages\FacultySchedule;
use App\Filament\Student\Pages\ScheduleView;
use App\Models\CorVersion;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\FacultyQualification;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationCaseEvent;
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
use App\Models\TimetableRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
            ->assertSee($owned['course']->code)
            ->assertSee($owned['section']->code)
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
            ->assertSee($owned['course']->code)
            ->assertSee($owned['section']->code)
            ->assertSee($owned['faculty']->name)
            ->assertSee('Monday')
            ->assertSee('08:00-11:00')
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
            ->assertSee('No current official schedule is available');

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

        $events = $this->publishPreparedRevision(
            $fixture['run'],
            $registrar,
            ScheduleRevisionEvent::ChangeRoom,
            [[
                'section_meeting_id' => $fixture['meeting']->id,
                'room_id' => $replacementRoom->id,
            ]],
            'Room replacement approved for cross-role acceptance.',
        );
        $currentMeeting = SectionMeeting::query()->findOrFail($events->sole()->section_meeting_id);
        $currentRun = ScheduleGenerationRun::query()->findOrFail($currentMeeting->schedule_run_id);

        $events = $this->publishPreparedRevision(
            $currentRun,
            $registrar,
            ScheduleRevisionEvent::ChangeTime,
            [[
                'section_meeting_id' => $currentMeeting->id,
                'day_of_week' => 2,
                'starts_at' => '09:00:00',
                'ends_at' => '12:00:00',
            ]],
            'Time replacement approved for cross-role acceptance.',
        );
        $currentMeeting = SectionMeeting::query()->findOrFail($events->sole()->section_meeting_id);
        $currentRun = ScheduleGenerationRun::query()->findOrFail($currentMeeting->schedule_run_id);

        $events = $this->publishPreparedRevision(
            $currentRun,
            $registrar,
            ScheduleRevisionEvent::ChangeFacultyReassignment,
            [[
                'section_meeting_id' => $currentMeeting->id,
                'faculty_user_id' => $replacementFaculty->id,
            ]],
            'Faculty replacement approved for cross-role acceptance.',
        );
        $currentMeeting = SectionMeeting::query()->findOrFail($events->sole()->section_meeting_id);
        Livewire::actingAs($fixture['faculty'])
            ->test(FacultySchedule::class)
            ->assertCanNotSeeTableRecords([$fixture['meeting']]);

        Livewire::actingAs($replacementFaculty)
            ->test(FacultySchedule::class)
            ->assertCanSeeTableRecords([$currentMeeting])
            ->assertSee('Tuesday')
            ->assertSee('9:00 AM - 12:00 PM')
            ->assertSee($replacementRoom->code);

        Livewire::actingAs($fixture['student'])
            ->test(ScheduleView::class)
            ->assertSee($replacementFaculty->name)
            ->assertSee('Tuesday')
            ->assertSee('09:00-12:00')
            ->assertSee($replacementRoom->code);

        $cor = app(BuildCorOutput::class)->forStudent($fixture['student']);

        $this->assertTrue($cor['available']);
        $this->assertSame('Monday', $cor['subjects'][0]['day']);
        $this->assertSame('08:00 - 11:00', $cor['subjects'][0]['time']);
        $this->assertSame($fixture['room']->code, $cor['subjects'][0]['room']);
        $this->assertSame($fixture['faculty']->name, $cor['subjects'][0]['instructor']);
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
            ->assertSee($fixture['course']->code);

        $fixture['binding']->forceFill([
            'is_active' => false,
            'effective_until' => now()->toDateString(),
            'released_by' => $registrar->id,
            'released_at' => now(),
            'release_reason' => 'Placement released before section cancellation.',
        ])->save();
        $fixture['courseEnrollment']->forceFill([
            'status' => CourseEnrollment::StatusDropped,
            'is_current' => false,
            'effective_until' => now(),
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
            ->assertDontSee($fixture['course']->code);

        $cor = app(BuildCorOutput::class)->forStudent($fixture['student']);

        $this->assertTrue($cor['available']);
        $this->assertSame('Monday', $cor['subjects'][0]['day']);
        $this->assertSame('08:00 - 11:00', $cor['subjects'][0]['time']);
        $this->assertSame($fixture['room']->code, $cor['subjects'][0]['room']);
        $this->assertSame($fixture['faculty']->name, $cor['subjects'][0]['instructor']);
    }

    #[Test]
    public function student_schedule_and_access_log_use_only_the_current_official_enrollment(): void
    {
        $historical = $this->scheduleFixture();
        $historical['term']->update(['state' => Term::StateClosed]);
        $current = $this->scheduleFixture(student: $historical['student']);

        Livewire::actingAs($historical['student'])
            ->test(ScheduleView::class)
            ->assertSee($current['course']->code)
            ->assertDontSee($historical['course']->code);

        $this->assertDatabaseHas('output_access_logs', [
            'output_type' => 'STUDENT_SCHEDULE',
            'source_record_type' => Enrollment::class,
            'source_record_id' => $current['enrollment']->id,
            'student_profile_id' => $current['profile']->id,
            'row_count' => 1,
        ]);
        $this->assertDatabaseMissing('output_access_logs', [
            'output_type' => 'STUDENT_SCHEDULE',
            'source_record_id' => $historical['enrollment']->id,
        ]);

        $dashboard = app(StudentDashboardService::class)->forStudent($current['profile']);

        $this->assertCount(1, $dashboard['schedule']['current']);
        $this->assertSame($current['course']->code, $dashboard['schedule']['current'][0]['subject_code']);
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
                'credential_user_id' => $studentContext['student']->id,
                'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
                'status' => 'officially_enrolled',
                'registered_at' => now()->subDay(),
                'officially_enrolled_at' => now(),
            ]);
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'is_current' => true,
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
        $timetableVersion = PublishedTimetableVersion::query()->create([
            'term_id' => $term->id,
            'schedule_run_id' => $run->id,
            'version' => 1,
            'state' => PublishedTimetableVersion::StatePublished,
            'authority_reference' => 'D3C-AUTH-'.$this->fixtureCounter,
            'publication_reason' => 'Canonical projection fixture.',
            'source_versions' => [],
            'impact_summary' => [],
            'content_hash' => hash('sha256', 'd3c-'.$this->fixtureCounter),
            'published_by' => $registrar->id,
            'published_at' => now()->subDay(),
        ]);
        PublishedTimetableMeeting::query()->create([
            'published_timetable_version_id' => $timetableVersion->id,
            'section_id' => $section->id,
            'scheduling_demand_id' => $demand->id,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'meeting_sequence' => 1,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '11:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'location_label' => $room->code,
        ]);
        $cor = CorVersion::factory()->create([
            'enrollment_id' => $enrollment->id,
            'published_timetable_version_id' => $timetableVersion->id,
            'issued_by' => $registrar->id,
            'snapshot' => [
                'student_number' => $studentContext['profile']->student_number,
                'student_name' => $studentContext['student']->name,
                'program_id' => $studentContext['program']->id,
                'program_code' => $studentContext['program']->code,
                'curriculum_version_id' => $curriculum->id,
                'term_label' => $term->label,
                'published_timetable_version_id' => $timetableVersion->id,
                'assessment_total' => '0.00',
                'fees' => [],
                'courses' => [[
                    'course_enrollment_id' => $courseEnrollment->id,
                    'section_id' => $section->id,
                    'section_code' => $section->code,
                    'course_code' => $course->code,
                    'course_title' => $specification->title,
                    'units' => '3.00',
                    'meetings' => [[
                        'day_of_week' => 1,
                        'starts_at' => '08:00:00',
                        'ends_at' => '11:00:00',
                        'room_label' => $room->code,
                        'faculty_name' => $faculty->name,
                        'modality' => TermOffering::ModalityFaceToFace,
                    ]],
                ]],
            ],
        ]);
        $enrollment->update(['current_cor_version_id' => $cor->id]);
        $courseEnrollment->update(['published_timetable_version_id' => $timetableVersion->id]);
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
        $existingProfile = $student->studentProfile()->with('program')->first();

        if ($existingProfile instanceof StudentProfile && $existingProfile->program instanceof Program) {
            return [
                'student' => $student,
                'profile' => $existingProfile,
                'program' => $existingProfile->program,
            ];
        }

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

    /**
     * @param  list<array<string, mixed>>  $changes
     * @return EloquentCollection<int, ScheduleRevisionEvent>
     */
    private function publishPreparedRevision(
        ScheduleGenerationRun $run,
        User $registrar,
        string $changeType,
        array $changes,
        string $reason,
    ): EloquentCollection {
        try {
            return app(PublishedScheduleRevisionService::class)->revise($run, $registrar, $changeType, $changes, $reason);
        } catch (ValidationException $exception) {
            if (! array_key_exists('revision', $exception->errors())) {
                throw $exception;
            }
        }

        $revision = TimetableRevision::query()
            ->where('state', TimetableRevision::StateDraft)
            ->whereHas('sourceVersion', fn ($query) => $query->where('schedule_run_id', $run->id))
            ->latest('id')
            ->firstOrFail();
        $sourceReference = 'timetable-revision:'.$revision->id;

        foreach ($revision->impact_snapshot['affected_registration_case_ids'] ?? [] as $caseId) {
            $case = Enrollment::query()->findOrFail($caseId);
            $opened = RegistrationCaseEvent::query()
                ->where('enrollment_id', $case->id)
                ->where('event_type', 'TimetableRevisionImpactReviewOpened')
                ->where('authority_reference', $sourceReference)
                ->sole();
            app(ResolveTimetableRevisionRegistrationImpact::class)->execute(
                $revision,
                $case,
                $opened,
                $registrar,
                ResolveTimetableRevisionRegistrationImpact::OutcomeRetainedWithAcknowledgement,
                'TAL94D3C-ACK-'.$case->id,
            );
        }

        return app(PublishedScheduleRevisionService::class)->revise($run, $registrar, $changeType, $changes, $reason);
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
