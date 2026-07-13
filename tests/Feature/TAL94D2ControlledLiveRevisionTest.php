<?php

namespace Tests\Feature;

use App\Actions\Scheduling\PublishedScheduleRevisionService;
use App\Actions\Scheduling\ScheduleRevisionImpactService;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL94D2ControlledLiveRevisionTest extends TestCase
{
    use DatabaseTransactions;

    private int $contextCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

        foreach ([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
            User::StaffRoleSystemSuperAdmin,
            User::StaffRoleFaculty,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_revision_event_schema_and_policy_match_the_controlled_domain(): void
    {
        $this->assertTrue(Schema::hasColumns('schedule_revision_events', [
            'old_snapshot_json',
            'new_snapshot_json',
            'affected_student_count',
            'affected_faculty_count',
        ]));
        $this->assertFalse(Schema::hasColumns('schedule_revision_events', [
            'old_snapshot',
            'new_snapshot',
            'affected_count',
        ]));

        $registrar = $this->staff(User::StaffRoleRegistrar);

        $this->assertTrue(Gate::forUser($registrar)->allows('revise', SectionMeeting::class));

        foreach ([
            User::StaffRoleAcademicHead,
            User::StaffRoleSystemSuperAdmin,
            User::StaffRoleFaculty,
        ] as $role) {
            $this->assertFalse(Gate::forUser($this->staff($role))->allows('revise', SectionMeeting::class));
        }
    }

    public function test_preview_is_read_only_and_room_revision_records_immutable_evidence(): void
    {
        $context = $this->context();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $binding = $this->activeBinding($context, $context['meetings'][0]);
        $changes = [[
            'section_meeting_id' => $context['meetings'][0]->id,
            'room_id' => $context['replacementRoom']->id,
        ]];

        $impact = app(ScheduleRevisionImpactService::class)->preview(
            $context['run'],
            ScheduleRevisionEvent::ChangeRoom,
            $changes,
        );

        $this->assertTrue($impact->passes());
        $this->assertSame(1, $impact->affectedStudents());
        $this->assertSame(1, $impact->affectedFaculty());
        $this->assertDatabaseCount('schedule_revision_events', 0);
        $this->assertSame($context['room']->id, $context['meetings'][0]->fresh()->room_id);

        $revisionStartedAt = now();
        $events = app(PublishedScheduleRevisionService::class)->revise(
            $context['run'],
            $registrar,
            ScheduleRevisionEvent::ChangeRoom,
            $changes,
            'The original room is unavailable.',
        );
        $event = $events->sole();
        $meeting = $context['meetings'][0]->fresh();

        $this->assertSame($context['replacementRoom']->id, $meeting->room_id);
        $this->assertSame($meeting->id, $binding['binding']->fresh()->section_meeting_id);
        $this->assertSame($context['room']->id, $event->old_snapshot_json['room_id']);
        $this->assertSame($context['replacementRoom']->id, $event->new_snapshot_json['room_id']);
        $this->assertSame(now()->toDateString(), $event->effective_date->toDateString());
        $this->assertSame($registrar->id, $event->changed_by);
        $this->assertSame(1, $event->affected_student_count);
        $this->assertSame(1, $event->affected_faculty_count);
        $this->assertTrue($event->created_at->betweenIncluded(
            $revisionStartedAt->copy()->startOfSecond(),
            now()->endOfSecond(),
        ));
        $this->assertSame(ScheduleGenerationRun::StatusPublished, $context['run']->fresh()->status);
        $this->assertSame(1, $context['run']->fresh()->publication_version);

        $activity = DB::table('activity_log')->where('event', 'published_schedule_revised')->sole();
        $properties = json_decode((string) $activity->properties, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([$event->id], $properties['schedule_revision_event_ids']);
        $this->assertSame(1, $properties['impact']['affected_students']);

        try {
            $event->forceFill(['reason' => 'Tampered'])->save();
            $this->fail('Revision events must reject updates.');
        } catch (LogicException $exception) {
            $this->assertSame('Schedule revision events are immutable.', $exception->getMessage());
        }

        try {
            $event->fresh()->delete();
            $this->fail('Revision events must reject deletion.');
        } catch (LogicException $exception) {
            $this->assertSame('Schedule revision events are immutable.', $exception->getMessage());
        }
    }

    public function test_supported_revision_types_update_only_their_owned_fields(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $facultyContext = $this->context();
        $replacementFaculty = $this->staff(User::StaffRoleFaculty);
        FacultyQualification::factory()
            ->for($replacementFaculty, 'faculty')
            ->for($facultyContext['course'])
            ->create(['is_active' => true]);

        app(PublishedScheduleRevisionService::class)->revise(
            $facultyContext['run'],
            $registrar,
            ScheduleRevisionEvent::ChangeFacultyReassignment,
            [[
                'section_meeting_id' => $facultyContext['meetings'][0]->id,
                'faculty_user_id' => $replacementFaculty->id,
            ]],
            'Faculty reassignment approved.',
        );
        $this->assertSame($replacementFaculty->id, $facultyContext['meetings'][0]->fresh()->faculty_user_id);

        $timeContext = $this->context();
        app(PublishedScheduleRevisionService::class)->revise(
            $timeContext['run'],
            $registrar,
            ScheduleRevisionEvent::ChangeTime,
            [[
                'section_meeting_id' => $timeContext['meetings'][0]->id,
                'day_of_week' => 2,
                'starts_at' => '09:00:00',
                'ends_at' => '12:00:00',
            ]],
            'Time change approved.',
        );
        $this->assertSame(2, $timeContext['meetings'][0]->fresh()->day_of_week);
        $this->assertSame('09:00:00', $timeContext['meetings'][0]->fresh()->starts_at);

        $modalityContext = $this->context();
        $modalityContext['meetings'][0]->forceFill([
            'modality' => TermOffering::ModalityOnline,
            'room_id' => null,
        ])->save();
        app(PublishedScheduleRevisionService::class)->revise(
            $modalityContext['run'],
            $registrar,
            ScheduleRevisionEvent::ChangeDeliveryModality,
            [[
                'section_meeting_id' => $modalityContext['meetings'][0]->id,
                'modality' => TermOffering::ModalityFaceToFace,
                'room_id' => $modalityContext['room']->id,
            ]],
            'Delivery modality corrected to the authoritative offering.',
        );
        $this->assertSame(TermOffering::ModalityFaceToFace, $modalityContext['meetings'][0]->fresh()->modality);
        $this->assertSame($modalityContext['room']->id, $modalityContext['meetings'][0]->fresh()->room_id);
    }

    public function test_multi_meeting_revision_is_atomic_on_success_and_failure(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $context = $this->context(meetingCount: 2);
        $changes = collect($context['meetings'])
            ->map(fn (SectionMeeting $meeting): array => [
                'section_meeting_id' => $meeting->id,
                'room_id' => $context['replacementRoom']->id,
            ])
            ->all();

        $events = app(PublishedScheduleRevisionService::class)->revise(
            $context['run'],
            $registrar,
            ScheduleRevisionEvent::ChangeRoom,
            $changes,
            'Both linked meetings require the replacement room.',
        );

        $this->assertCount(2, $events);
        $this->assertSame(2, SectionMeeting::query()
            ->whereIn('id', collect($context['meetings'])->pluck('id'))
            ->where('room_id', $context['replacementRoom']->id)
            ->count());

        $rollbackContext = $this->context(meetingCount: 2);
        $unsuitableRoom = Room::factory()->create([
            'code' => 'D2-R'.$this->contextCounter.'C',
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 1,
            'is_active' => true,
        ]);
        $rollbackChanges = [
            [
                'section_meeting_id' => $rollbackContext['meetings'][0]->id,
                'room_id' => $rollbackContext['replacementRoom']->id,
            ],
            [
                'section_meeting_id' => $rollbackContext['meetings'][1]->id,
                'room_id' => $unsuitableRoom->id,
            ],
        ];

        $preview = app(ScheduleRevisionImpactService::class)->preview(
            $rollbackContext['run'],
            ScheduleRevisionEvent::ChangeRoom,
            $rollbackChanges,
        );
        $this->assertFalse($preview->passes());
        $this->assertContains('room_not_suitable', collect($preview->findings())->pluck('code')->all());

        try {
            app(PublishedScheduleRevisionService::class)->revise(
                $rollbackContext['run'],
                $registrar,
                ScheduleRevisionEvent::ChangeRoom,
                $rollbackChanges,
                'This grouped revision must roll back.',
            );
            $this->fail('One invalid meeting must roll back the whole grouped revision.');
        } catch (ValidationException) {
            $this->assertSame(2, SectionMeeting::query()
                ->whereIn('id', collect($rollbackContext['meetings'])->pluck('id'))
                ->where('room_id', $rollbackContext['room']->id)
                ->count());
            $this->assertSame(0, ScheduleRevisionEvent::query()
                ->whereIn('section_meeting_id', collect($rollbackContext['meetings'])->pluck('id'))
                ->count());
        }
    }

    public function test_authorization_input_boundaries_and_current_source_drift_are_atomic(): void
    {
        $context = $this->context();
        $changes = [[
            'section_meeting_id' => $context['meetings'][0]->id,
            'room_id' => $context['replacementRoom']->id,
        ]];

        try {
            app(PublishedScheduleRevisionService::class)->revise(
                $context['run'],
                $this->staff(User::StaffRoleAcademicHead),
                ScheduleRevisionEvent::ChangeRoom,
                $changes,
                'Unauthorized attempt.',
            );
            $this->fail('Only the Registrar may revise a published schedule.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('schedule_revision_events', 0);
        }

        foreach ([
            [[
                'section_meeting_id' => $context['meetings'][0]->id,
                'room_id' => $context['room']->id,
            ]],
            [[
                'section_meeting_id' => $context['meetings'][0]->id,
                'room_id' => $context['replacementRoom']->id,
                'faculty_user_id' => $context['faculty']->id,
            ]],
        ] as $invalidChanges) {
            try {
                app(ScheduleRevisionImpactService::class)->preview(
                    $context['run'],
                    ScheduleRevisionEvent::ChangeRoom,
                    $invalidChanges,
                );
                $this->fail('Invalid revision fields must be rejected.');
            } catch (ValidationException) {
                $this->assertSame($context['room']->id, $context['meetings'][0]->fresh()->room_id);
            }
        }

        $foreignContext = $this->context();
        try {
            app(ScheduleRevisionImpactService::class)->preview(
                $context['run'],
                ScheduleRevisionEvent::ChangeRoom,
                [[
                    'section_meeting_id' => $foreignContext['meetings'][0]->id,
                    'room_id' => $context['replacementRoom']->id,
                ]],
            );
            $this->fail('Cross-run meetings must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('schedule_revision_events', 0);
        }

        $preview = app(ScheduleRevisionImpactService::class)->preview(
            $context['run'],
            ScheduleRevisionEvent::ChangeRoom,
            $changes,
        );
        $this->assertTrue($preview->passes());

        $context['replacementRoom']->forceFill(['is_active' => false])->save();

        $blockedPreview = app(ScheduleRevisionImpactService::class)->preview(
            $context['run'],
            ScheduleRevisionEvent::ChangeRoom,
            $changes,
        );
        $roomFinding = collect($blockedPreview->findings())->firstWhere('code', 'room_not_suitable');

        $this->assertFalse($blockedPreview->passes());
        $this->assertIsArray($roomFinding);
        $this->assertSame('room', $roomFinding['source_type']);
        $this->assertSame($context['replacementRoom']->id, $roomFinding['source_id']);

        try {
            app(PublishedScheduleRevisionService::class)->revise(
                $context['run'],
                $this->staff(User::StaffRoleRegistrar),
                ScheduleRevisionEvent::ChangeRoom,
                $changes,
                'This preview is now stale.',
            );
            $this->fail('Current source drift must block the revision.');
        } catch (ValidationException) {
            $this->assertSame($context['room']->id, $context['meetings'][0]->fresh()->room_id);
            $this->assertDatabaseCount('schedule_revision_events', 0);
            $this->assertSame(0, DB::table('activity_log')->where('event', 'published_schedule_revised')->count());
        }
    }

    public function test_whole_section_cancellation_blocks_active_placement_then_cancels_atomically(): void
    {
        $context = $this->context(meetingCount: 2);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $binding = $this->activeBinding($context, $context['meetings'][0]);
        $binding['courseEnrollment']->forceFill(['status' => CourseEnrollment::StatusDropped])->save();
        $reservation = EnrollmentSeatReservation::query()->create([
            'enrollment_id' => $binding['enrollment']->id,
            'course_enrollment_id' => $binding['courseEnrollment']->id,
            'section_id' => $context['section']->id,
            'status' => EnrollmentSeatReservation::StatusActive,
            'reserved_at' => now(),
            'registrar_user_id' => $registrar->id,
        ]);

        $blocked = app(ScheduleRevisionImpactService::class)->previewCancellation(
            $context['run'],
            $context['section'],
        );

        $this->assertFalse($blocked->passes());
        $this->assertSame(1, $blocked->activeBindings());
        $this->assertSame(1, $blocked->capacityHoldingReservations());

        try {
            app(PublishedScheduleRevisionService::class)->cancelSection(
                $context['run'],
                $context['section'],
                $registrar,
                'The section is no longer required.',
            );
            $this->fail('Cancellation must block active placement records.');
        } catch (ValidationException) {
            $this->assertSame(Section::StateOpen, $context['section']->fresh()->state);
            $this->assertSame(SectionDeliveryGroup::StateReady, $context['group']->fresh()->state);
            $this->assertSame(0, SectionMeeting::query()->where('state', SectionMeeting::StateCancelled)->count());
            $this->assertDatabaseCount('schedule_revision_events', 0);
        }

        $binding['binding']->forceFill([
            'is_active' => false,
            'effective_until' => now()->toDateString(),
            'released_by' => $registrar->id,
            'released_at' => now(),
            'release_reason' => 'Placement resolved before cancellation.',
        ])->save();
        $reservation->forceFill([
            'status' => EnrollmentSeatReservation::StatusReleased,
            'released_at' => now(),
        ])->save();

        $events = app(PublishedScheduleRevisionService::class)->cancelSection(
            $context['run'],
            $context['section'],
            $registrar,
            'The section is no longer required.',
        );

        $this->assertCount(2, $events);
        $this->assertSame(Section::StateCancelled, $context['section']->fresh()->state);
        $this->assertSame(SectionDeliveryGroup::StateCancelled, $context['group']->fresh()->state);
        $this->assertSame(2, SectionMeeting::query()
            ->whereIn('id', collect($context['meetings'])->pluck('id'))
            ->where('state', SectionMeeting::StateCancelled)
            ->count());
        $this->assertSame(2, ScheduleRevisionEvent::query()
            ->where('change_type', ScheduleRevisionEvent::ChangeSectionCancellation)
            ->count());
        $this->assertSame(ScheduleGenerationRun::StatusPublished, $context['run']->fresh()->status);
        $this->assertSame(1, $context['run']->fresh()->publication_version);
        $this->assertSame(1, DB::table('activity_log')->where('event', 'published_section_cancelled')->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function context(int $meetingCount = 1): array
    {
        $this->contextCounter++;
        $term = Term::factory()->create([
            'label' => 'TAL-94D2 Term '.$this->contextCounter,
            'state' => Term::StateActive,
            'default_max_units' => 21.00,
        ]);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $room = Room::factory()->create([
            'code' => 'D2-R'.$this->contextCounter.'A',
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);
        $replacementRoom = Room::factory()->create([
            'code' => 'D2-R'.$this->contextCounter.'B',
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);
        $program = Program::factory()->create(['code' => 'D2'.$this->contextCounter]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['code' => 'D2C'.$this->contextCounter]);
        $specification = CourseSpecification::factory()->for($course)->create([
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => [
                TermOffering::ModalityFaceToFace,
                TermOffering::ModalityOnline,
            ],
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
        $entry = CurriculumEntry::factory()->for($curriculum)->for($specification, 'courseSpecification')->create([
            'term_type' => $term->type,
            'term_label' => $term->label,
            'sequence' => 1,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => TermOffering::StateScheduled,
            'expected_count' => 30,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'D2-S'.$this->contextCounter,
            'capacity' => 30,
            'state' => Section::StateOpen,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'TAL-94D2 Group '.$this->contextCounter,
            'expected_count' => 30,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        FacultyQualification::factory()->for($faculty, 'faculty')->for($course)->create(['is_active' => true]);
        $demand = SchedulingDemand::factory()
            ->for($offering)
            ->for($component)
            ->for($group)
            ->create([
                'required_duration_minutes' => 180,
                'meeting_count' => $meetingCount,
                'modality' => TermOffering::ModalityFaceToFace,
                'validation_state' => SchedulingDemand::ValidationReadyForReview,
            ]);
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'requested_by' => null,
            'input_snapshot' => [
                'scheduling_demands' => [['scheduling_demand_id' => $demand->id]],
            ],
            'input_hash' => hash('sha256', (string) Str::uuid()),
            'solver_version' => 'tal94d2-test-solver',
            'published_at' => now()->subDay(),
            'publication_version' => 1,
        ]);
        $meetings = [];

        foreach (range(1, $meetingCount) as $sequence) {
            $meetings[] = SectionMeeting::query()->create([
                'schedule_run_id' => $run->id,
                'scheduling_demand_id' => $demand->id,
                'meeting_sequence' => $sequence,
                'faculty_user_id' => $faculty->id,
                'room_id' => $room->id,
                'day_of_week' => (($sequence - 1) * 2) + 1,
                'starts_at' => '08:00:00',
                'ends_at' => '11:00:00',
                'modality' => TermOffering::ModalityFaceToFace,
                'state' => SectionMeeting::StateActive,
                'published_at' => now()->subDay(),
            ]);
        }

        return compact(
            'term',
            'faculty',
            'room',
            'replacementRoom',
            'program',
            'course',
            'offering',
            'section',
            'group',
            'demand',
            'run',
            'meetings',
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{enrollment:Enrollment,courseEnrollment:CourseEnrollment,binding:StudentScheduleBinding}
     */
    private function activeBinding(array $context, SectionMeeting $meeting): array
    {
        $student = StudentProfile::factory()->create(['program_id' => $context['program']->id]);
        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($context['term'])
            ->create(['status' => 'pending_payment']);
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $context['offering']->id,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);
        $binding = StudentScheduleBinding::query()->create([
            'course_enrollment_id' => $courseEnrollment->id,
            'section_meeting_id' => $meeting->id,
            'is_active' => true,
            'effective_from' => now()->toDateString(),
            'source' => StudentScheduleBinding::SourceRegistrarPlacement,
        ]);

        return compact('enrollment', 'courseEnrollment', 'binding');
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
