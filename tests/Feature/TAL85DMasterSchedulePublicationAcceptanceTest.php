<?php

namespace Tests\Feature;

use App\Actions\Scheduling\SchedulePublishService;
use App\Actions\StudentHub\StudentDashboardService;
use App\Filament\Resources\ScheduleGenerationRuns\Pages\ViewScheduleGenerationRun;
use App\Filament\Resources\SectionMeetings\Pages\ListSectionMeetings;
use App\Filament\Resources\SectionMeetings\SectionMeetingResource;
use App\Models\CandidateScheduleRow;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\FacultyQualification;
use App\Models\Program;
use App\Models\PublishedTimetableVersion;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL85DMasterSchedulePublicationAcceptanceTest extends TestCase
{
    use DatabaseTransactions;

    private SchedulePublishService $publisher;

    private User $faculty;

    private Room $room;

    private int $sourceCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotContains(DB::connection()->getDatabaseName(), [
            'tala_db',
            'tala_test_codex',
        ]);

        foreach ([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
            User::StaffRoleSystemSuperAdmin,
            User::StaffRoleFaculty,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->publisher = app(SchedulePublishService::class);
        $this->faculty = $this->staff(User::StaffRoleFaculty);
        $this->room = Room::factory()->create([
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);
    }

    public function test_publishable_candidate_rows_create_official_meetings_and_non_publishable_rows_do_not_write(): void
    {
        $publishedAt = Carbon::parse('2026-07-05 09:00:00');
        Carbon::setTestNow($publishedAt);

        try {
            $registrar = $this->staff(User::StaffRoleRegistrar);
            $source = $this->sourceGraph();
            $run = $this->scheduleRun($source['term']);
            $candidate = $this->candidate($run, $source['demand']);

            $published = $this->publisher->publish($run, $registrar, '  TAL-85D accepted publication.  ');
            $meeting = SectionMeeting::query()
                ->where('schedule_run_id', $run->id)
                ->sole();

            $this->assertSame(ScheduleGenerationRun::StatusPublished, $published->status);
            $this->assertSame($registrar->id, $published->published_by);
            $this->assertTrue($this->carbonAttribute($published, 'published_at')->equalTo($publishedAt));
            $this->assertSame(1, $published->publication_version);
            $this->assertSame('TAL-85D accepted publication.', $published->publication_note);
            $this->assertSame($run->id, $meeting->schedule_run_id);
            $this->assertSame($source['demand']->id, $meeting->scheduling_demand_id);
            $this->assertSame($candidate->meeting_sequence, $meeting->meeting_sequence);
            $this->assertSame($candidate->faculty_user_id, $meeting->faculty_user_id);
            $this->assertSame($candidate->room_id, $meeting->room_id);
            $this->assertSame(TermOffering::ModalityFaceToFace, $meeting->modality);
            $this->assertSame(SectionMeeting::StateActive, $meeting->state);
            $this->assertTrue($this->carbonAttribute($meeting, 'published_at')->equalTo($publishedAt));

            $blockedRun = $this->scheduleRun($source['term']);
            $this->candidate(
                $blockedRun,
                $source['demand'],
                CandidateScheduleRow::StatusConflict,
                violations: [['key' => 'room_overlap', 'message' => 'Blocking overlap.']],
            );

            try {
                $this->publisher->publish($blockedRun, $registrar);
                $this->fail('Non-publishable candidate rows were published.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('candidate_schedule_rows', $exception->errors());
            }

            $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $blockedRun->fresh()->status);
            $this->assertSame(1, SectionMeeting::query()
                ->whereIn('schedule_run_id', [$run->id, $blockedRun->id])
                ->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_superseded_history_remains_while_resource_lists_only_current_read_only_official_meetings(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $faculty = $this->faculty;
        $source = $this->sourceGraph();
        $priorRun = $this->scheduleRun($source['term'], ScheduleGenerationRun::StatusPublished, [
            'published_by' => $registrar->id,
            'published_at' => now()->subDay(),
            'publication_version' => 1,
        ]);
        $historicalMeeting = $this->officialMeeting($priorRun, $source['demand'], now()->subDay());
        $newRun = $this->scheduleRun($source['term']);
        $this->candidate($newRun, $source['demand'], startsAt: '10:00:00', endsAt: '12:00:00');

        $published = $this->publisher->publish($newRun, $registrar);
        $currentMeeting = $published->sectionMeetings()->firstOrFail();

        $this->assertSame(ScheduleGenerationRun::StatusSuperseded, $priorRun->fresh()->status);
        $this->assertSame(2, $published->publication_version);
        $this->assertModelExists($historicalMeeting);
        $this->assertSame(
            [$currentMeeting->id],
            SectionMeeting::query()
                ->activeOfficial()
                ->whereHas('scheduleRun', fn ($query) => $query->where('term_id', $source['term']->id))
                ->pluck('id')
                ->values()
                ->all(),
        );

        $this->assertTrue(Route::has('filament.admin.resources.section-meetings.index'));
        $this->assertTrue(Route::has('filament.admin.resources.section-meetings.view'));
        $this->assertFalse(Route::has('filament.admin.resources.section-meetings.create'));
        $this->assertFalse(Route::has('filament.admin.resources.section-meetings.edit'));

        $this->assertTrue(Gate::forUser($registrar)->allows('publish', $newRun));
        $this->assertFalse(Gate::forUser($academicHead)->allows('publish', $newRun));
        $this->assertFalse(Gate::forUser($faculty)->allows('publish', $newRun));
        $this->assertTrue(Gate::forUser($registrar)->allows('viewAny', SectionMeeting::class));
        $this->assertTrue(Gate::forUser($academicHead)->allows('viewAny', SectionMeeting::class));
        $this->assertFalse(Gate::forUser($faculty)->allows('viewAny', SectionMeeting::class));

        Livewire::actingAs($registrar)
            ->test(ListSectionMeetings::class)
            ->assertCanSeeTableRecords([$currentMeeting])
            ->assertCanNotSeeTableRecords([$historicalMeeting])
            ->assertActionDoesNotExist('create')
            ->assertActionDoesNotExist('edit')
            ->assertActionDoesNotExist('delete');

        $this->actingAs($registrar)
            ->get(SectionMeetingResource::getUrl())
            ->assertOk();
    }

    public function test_publication_authorization_and_downstream_student_hub_boundary_use_active_official_meetings_only(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $systemSuperAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $source = $this->sourceGraph(TermOffering::ModalityOnline, room: null);
        $publishableRun = $this->scheduleRun($source['term']);
        $this->candidate($publishableRun, $source['demand'], roomId: null);

        $this->assertTrue(Gate::forUser($registrar)->allows('publish', $publishableRun));
        $this->assertFalse(Gate::forUser($systemSuperAdmin)->allows('publish', $publishableRun));
        $this->assertFalse(Gate::forUser($academicHead)->allows('publish', $publishableRun));

        Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $publishableRun->getRouteKey()])
            ->assertActionVisible('publishSchedule');

        Livewire::actingAs($academicHead)
            ->test(ViewScheduleGenerationRun::class, ['record' => $publishableRun->getRouteKey()])
            ->assertActionHidden('publishSchedule');

        try {
            $this->publisher->publish($publishableRun, $academicHead);
            $this->fail('Academic Head publication was not blocked.');
        } catch (AuthorizationException) {
            $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $publishableRun->fresh()->status);
            $this->assertSame(0, SectionMeeting::query()
                ->where('schedule_run_id', $publishableRun->id)
                ->count());
        }

        try {
            $this->publisher->publish($publishableRun, $systemSuperAdmin);
            $this->fail('System Super Admin publication was not blocked.');
        } catch (AuthorizationException) {
            $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $publishableRun->fresh()->status);
            $this->assertSame(0, SectionMeeting::query()
                ->where('schedule_run_id', $publishableRun->id)
                ->count());
        }

        $published = $this->publisher->publish($publishableRun, $registrar);
        $currentMeeting = $published->sectionMeetings()->firstOrFail();
        $historicalRun = $this->scheduleRun($source['term'], ScheduleGenerationRun::StatusSuperseded, [
            'published_by' => $registrar->id,
            'published_at' => now()->subDay(),
            'publication_version' => 1,
        ]);
        $historicalMeeting = $this->officialMeeting($historicalRun, $source['demand'], now()->subDay());
        $candidateOnlyRun = $this->scheduleRun($source['term']);
        $this->candidate($candidateOnlyRun, $source['demand'], roomId: null, startsAt: '13:00:00', endsAt: '15:00:00');

        $student = StudentProfile::factory()->create(['program_id' => $source['program']->id]);
        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($source['term'])
            ->create([
                'credential_user_id' => $student->user_id,
                'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
                'status' => 'officially_enrolled',
            ]);
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $source['offering']->id,
            'section_id' => $source['section']->id,
            'is_current' => true,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);

        foreach ([$currentMeeting, $historicalMeeting] as $meeting) {
            StudentScheduleBinding::query()->create([
                'course_enrollment_id' => $courseEnrollment->id,
                'section_meeting_id' => $meeting->id,
                'is_active' => true,
                'effective_from' => now()->toDateString(),
                'source' => StudentScheduleBinding::SourceRegistrarPlacement,
            ]);
        }

        $schedule = $this->studentHubScheduleFor($enrollment);
        $currentPublishedMeetingId = PublishedTimetableVersion::query()
            ->where('schedule_run_id', $published->id)
            ->firstOrFail()
            ->meetings()
            ->where('section_id', $source['section']->id)
            ->value('id');

        $this->assertCount(1, $schedule);
        $this->assertSame($currentPublishedMeetingId, $schedule[0]['section_meeting_id']);
        $this->assertSame($source['section']->id, $schedule[0]['section_id']);
        $this->assertNull($schedule[0]['section_delivery_group_id']);
        $this->assertSame($source['course']->code, $schedule[0]['subject_code']);
        $this->assertSame('Online', $schedule[0]['modality_label']);
        $this->assertSame(
            [$published->id],
            SectionMeeting::query()
                ->activeOfficial()
                ->whereHas('scheduleRun', fn ($query) => $query->where('term_id', $source['term']->id))
                ->pluck('schedule_run_id')
                ->unique()
                ->values()
                ->all(),
        );
        $this->assertSame(1, CandidateScheduleRow::query()->where('schedule_run_id', $candidateOnlyRun->id)->count());
    }

    /**
     * @return array{
     *     term: Term,
     *     program: Program,
     *     course: Course,
     *     specification: CourseSpecification,
     *     component: CourseComponent,
     *     offering: TermOffering,
     *     section: Section,
     *     group: SectionDeliveryGroup,
     *     demand: SchedulingDemand
     * }
     */
    private function sourceGraph(string $modality = TermOffering::ModalityFaceToFace, ?Room $room = null): array
    {
        $this->sourceCounter++;

        $term = Term::factory()->create([
            'label' => 'TAL-85D Term '.$this->sourceCounter,
            'state' => Term::StateActive,
        ]);
        $program = Program::factory()->create(['code' => 'T85D'.str_pad((string) $this->sourceCounter, 2, '0', STR_PAD_LEFT)]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['code' => 'T85D'.$this->sourceCounter]);
        $specification = CourseSpecification::factory()->for($course)->create([
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace, TermOffering::ModalityOnline],
            'credit_units' => 3.00,
        ]);
        $component = CourseComponent::factory()->for($specification)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 2.00,
            'room_type_default' => Room::TypeLectureRoom,
            'sequence' => 1,
        ]);
        $entry = CurriculumEntry::factory()->for($curriculum)->for($specification, 'courseSpecification')->create([
            'term_type' => $term->type,
            'term_label' => $term->label,
            'sequence' => 1,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'modality' => $modality,
            'state' => TermOffering::StateScheduled,
            'expected_count' => 30,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'T85D-'.$this->sourceCounter,
            'capacity' => 30,
            'state' => Section::StateOpen,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'Regular Cohort '.$this->sourceCounter,
            'expected_count' => 30,
            'modality' => $modality,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $demand = SchedulingDemand::factory()
            ->for($offering)
            ->for($component)
            ->for($group)
            ->create([
                'modality' => $modality,
                'fixed_room_id' => $room?->id,
                'required_duration_minutes' => 120,
                'validation_state' => SchedulingDemand::ValidationReadyForReview,
            ]);

        FacultyQualification::factory()
            ->for($this->faculty, 'faculty')
            ->for($course)
            ->create();

        return [
            'term' => $term,
            'program' => $program,
            'course' => $course,
            'specification' => $specification,
            'component' => $component,
            'offering' => $offering,
            'section' => $section,
            'group' => $group,
            'demand' => $demand,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function scheduleRun(
        Term $term,
        string $status = ScheduleGenerationRun::StatusUnderReview,
        array $overrides = [],
    ): ScheduleGenerationRun {
        return ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => $status,
            'requested_by' => null,
            'input_snapshot' => [],
            'input_hash' => hash('sha256', (string) Str::uuid()),
            'solver_version' => 'tal85d-test-solver',
            ...$overrides,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $warnings
     * @param  list<array<string, mixed>>  $violations
     */
    private function candidate(
        ScheduleGenerationRun $run,
        SchedulingDemand $demand,
        string $status = CandidateScheduleRow::StatusOk,
        int|false|null $roomId = false,
        array $warnings = [],
        array $violations = [],
        int $meetingSequence = 1,
        string $startsAt = '08:00:00',
        string $endsAt = '10:00:00',
    ): CandidateScheduleRow {
        return CandidateScheduleRow::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => $meetingSequence,
            'faculty_user_id' => $this->faculty->id,
            'room_id' => $roomId === false ? $this->room->id : $roomId,
            'day_of_week' => 1,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'time_block_key' => 'D1-'.str_replace(':', '', mb_substr($startsAt, 0, 5)),
            'status' => $status,
            'scores' => [],
            'warnings' => $warnings,
            'violations' => $violations,
        ]);
    }

    private function officialMeeting(
        ScheduleGenerationRun $run,
        SchedulingDemand $demand,
        Carbon $publishedAt,
    ): SectionMeeting {
        return SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $this->faculty->id,
            'room_id' => $demand->modality === TermOffering::ModalityFaceToFace ? $this->room->id : null,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
            'modality' => $demand->modality,
            'state' => SectionMeeting::StateActive,
            'published_at' => $publishedAt,
        ]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function studentHubScheduleFor(Enrollment $enrollment): array
    {
        $method = new \ReflectionMethod(StudentDashboardService::class, 'scheduleFor');
        $method->setAccessible(true);
        $schedule = $method->invoke(app(StudentDashboardService::class), $enrollment);

        $this->assertIsArray($schedule);

        return $schedule;
    }

    private function carbonAttribute(Model $model, string $key): Carbon
    {
        $value = $model->getAttribute($key);

        $this->assertInstanceOf(Carbon::class, $value);

        return $value;
    }
}
