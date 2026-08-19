<?php

namespace Tests\Feature;

use App\Actions\Scheduling\PublishedScheduleRevisionService;
use App\Actions\Scheduling\ScheduleRevisionNotificationService;
use App\Filament\Resources\OperationalEvents\Pages\ListOperationalEvents;
use App\Filament\Resources\OperationalEvents\Pages\ViewOperationalEvent;
use App\Mail\ScheduleRevisionMail;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\FacultyQualification;
use App\Models\OperationalEvent;
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
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL94D3bScheduleRevisionNotificationTest extends TestCase
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
            'student',
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_committed_revision_queues_once_per_unique_affected_user_and_records_pending_evidence(): void
    {
        Mail::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $unrelatedFaculty = $this->staff(User::StaffRoleFaculty);
        $context = $this->context(meetingCount: 2);
        $firstStudent = $this->activeBinding($context, $context['meetings']);
        $secondStudent = $this->activeBinding($context, [$context['meetings'][0]]);
        $inactiveStudent = $this->activeBinding($context, [$context['meetings'][1]], active: false);
        $reason = 'The original rooms are unavailable due to confidential maintenance details.';

        $events = app(PublishedScheduleRevisionService::class)->revise(
            $context['run'],
            $registrar,
            ScheduleRevisionEvent::ChangeRoom,
            collect($context['meetings'])->map(fn (SectionMeeting $meeting): array => [
                'section_meeting_id' => $meeting->id,
                'room_id' => $context['replacementRoom']->id,
            ])->all(),
            $reason,
        );

        Mail::assertQueuedCount(3);
        foreach ([$context['faculty'], $firstStudent, $secondStudent] as $recipient) {
            Mail::assertQueued(
                ScheduleRevisionMail::class,
                fn (ScheduleRevisionMail $mail): bool => $mail->hasTo($recipient->email),
            );
        }
        foreach ([$registrar, $academicHead, $superAdmin, $unrelatedFaculty, $inactiveStudent] as $nonRecipient) {
            Mail::assertNotQueued(
                ScheduleRevisionMail::class,
                fn (ScheduleRevisionMail $mail): bool => $mail->hasTo($nonRecipient->email),
            );
        }

        $deliveryEvents = OperationalEvent::query()->where('event_type', 'schedule_revision_email')->get();
        $this->assertCount(3, $deliveryEvents);
        $this->assertSame(
            collect([$context['faculty']->id, $firstStudent->id, $secondStudent->id])->sort()->values()->all(),
            $deliveryEvents->pluck('user_id')->sort()->values()->all(),
        );
        $this->assertSame(['PENDING'], $deliveryEvents->pluck('status')->unique()->values()->all());
        $this->assertSame(3, $deliveryEvents->pluck('external_id')->unique()->count());
        $this->assertStringNotContainsString($reason, $deliveryEvents->toJson());
        $this->assertStringNotContainsString($academicHead->email, $deliveryEvents->toJson());

        app(ScheduleRevisionNotificationService::class)->recordAndQueue($events);

        Mail::assertQueuedCount(3);
        $this->assertSame(3, OperationalEvent::query()->where('event_type', 'schedule_revision_email')->count());
    }

    public function test_faculty_reassignment_notifies_previous_and_replacement_faculty_once(): void
    {
        Mail::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $context = $this->context();
        $replacementFaculty = $this->staff(User::StaffRoleFaculty);
        FacultyQualification::factory()
            ->for($replacementFaculty, 'faculty')
            ->for($context['course'])
            ->create(['is_active' => true]);

        app(PublishedScheduleRevisionService::class)->revise(
            $context['run'],
            $registrar,
            ScheduleRevisionEvent::ChangeFacultyReassignment,
            [[
                'section_meeting_id' => $context['meetings'][0]->id,
                'faculty_user_id' => $replacementFaculty->id,
            ]],
            'Approved faculty reassignment.',
        );

        Mail::assertQueuedCount(2);
        Mail::assertQueued(ScheduleRevisionMail::class, fn (ScheduleRevisionMail $mail): bool => $mail->hasTo($context['faculty']->email));
        Mail::assertQueued(ScheduleRevisionMail::class, fn (ScheduleRevisionMail $mail): bool => $mail->hasTo($replacementFaculty->email));
    }

    public function test_time_and_modality_changes_notify_the_affected_faculty(): void
    {
        Mail::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
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
            'Approved meeting-time change.',
        );

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
            'Approved modality correction.',
        );

        Mail::assertQueuedCount(2);
        Mail::assertQueued(ScheduleRevisionMail::class, fn (ScheduleRevisionMail $mail): bool => $mail->hasTo($timeContext['faculty']->email));
        Mail::assertQueued(ScheduleRevisionMail::class, fn (ScheduleRevisionMail $mail): bool => $mail->hasTo($modalityContext['faculty']->email));
    }

    public function test_failed_revision_creates_no_delivery_evidence_or_mail(): void
    {
        Mail::fake();

        $context = $this->context();

        try {
            app(PublishedScheduleRevisionService::class)->revise(
                $context['run'],
                $this->staff(User::StaffRoleRegistrar),
                ScheduleRevisionEvent::ChangeRoom,
                [[
                    'section_meeting_id' => $context['meetings'][0]->id,
                    'room_id' => $context['room']->id,
                ]],
                'This no-op change must fail validation.',
            );
            $this->fail('Expected the invalid revision to be rejected.');
        } catch (ValidationException) {
            $this->assertSame(0, ScheduleRevisionEvent::query()->count());
            $this->assertSame(0, OperationalEvent::query()->where('event_type', 'schedule_revision_email')->count());
            Mail::assertNothingQueued();
        }
    }

    public function test_mailable_contains_only_the_recipient_schedule_changes(): void
    {
        Mail::fake();

        $context = $this->context(meetingCount: 2);
        $student = $this->activeBinding($context, [$context['meetings'][0]]);
        $otherStudent = $this->activeBinding($context, [$context['meetings'][1]]);
        $reason = 'Private operational justification that must not leave the audit boundary.';

        app(PublishedScheduleRevisionService::class)->revise(
            $context['run'],
            $this->staff(User::StaffRoleRegistrar),
            ScheduleRevisionEvent::ChangeRoom,
            collect($context['meetings'])->map(fn (SectionMeeting $meeting): array => [
                'section_meeting_id' => $meeting->id,
                'room_id' => $context['replacementRoom']->id,
            ])->all(),
            $reason,
        );

        Mail::assertQueued(ScheduleRevisionMail::class, function (ScheduleRevisionMail $mail) use ($student, $otherStudent, $reason, $context): bool {
            if (! $mail->hasTo($student->email)) {
                return false;
            }

            $this->assertCount(1, $mail->scheduleChanges);
            $successorMeeting = SectionMeeting::query()
                ->whereKey($mail->scheduleChanges[0]['section_meeting_id'])
                ->firstOrFail();
            $this->assertNotSame($context['meetings'][0]->id, $successorMeeting->id);
            $this->assertSame(
                $context['meetings'][0]->scheduling_demand_id,
                $successorMeeting->scheduling_demand_id,
            );
            $mail->assertSeeInHtml($context['course']->code)
                ->assertSeeInHtml($context['section']->code)
                ->assertDontSeeInHtml($reason)
                ->assertDontSeeInHtml($otherStudent->email);

            return true;
        });
    }

    public function test_message_sent_and_failed_hooks_update_only_the_correlated_event(): void
    {
        $recipient = User::factory()->create();
        $processed = OperationalEvent::factory()->forUser($recipient)->create([
            'event_type' => 'schedule_revision_email',
            'status' => 'PENDING',
            'processed_at' => null,
            'sent_at' => null,
        ]);
        $failed = OperationalEvent::factory()->forUser($recipient)->create([
            'event_type' => 'schedule_revision_email',
            'external_id' => 'schedule-revision-failure-'.Str::uuid(),
            'status' => 'PENDING',
            'processed_at' => null,
            'sent_at' => null,
        ]);
        $untouched = OperationalEvent::factory()->forUser($recipient)->create([
            'event_type' => 'schedule_revision_email',
            'external_id' => 'schedule-revision-untouched-'.Str::uuid(),
            'status' => 'PENDING',
            'processed_at' => null,
            'sent_at' => null,
        ]);

        Mail::mailer('array')->to($recipient->email)->sendNow(
            new ScheduleRevisionMail($processed->id, $recipient->name, $this->mailPayload()),
        );

        $this->assertSame('PROCESSED', $processed->fresh()->status);
        $this->assertNotNull($processed->fresh()->processed_at);
        $this->assertNotNull($processed->fresh()->sent_at);

        $mailable = new ScheduleRevisionMail($failed->id, $recipient->name, $this->mailPayload());
        $mailable->failed(new RuntimeException('smtp_password=must-not-be-persisted'));

        $this->assertSame('FAILED', $failed->fresh()->status);
        $this->assertNotNull($failed->fresh()->processed_at);
        $this->assertNotNull($failed->fresh()->failed_at);
        $this->assertStringNotContainsString(
            'smtp_password',
            json_encode($failed->fresh()->diagnostics, JSON_THROW_ON_ERROR),
        );
        $this->assertSame('PENDING', $untouched->fresh()->status);
    }

    public function test_operational_events_monitor_uses_related_user_and_filters_all_delivery_states(): void
    {
        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $pending = OperationalEvent::factory()->forUser(User::factory()->create())->create(['status' => 'PENDING']);
        $processed = OperationalEvent::factory()->forUser(User::factory()->create())->create(['status' => 'PROCESSED']);
        $failed = OperationalEvent::factory()->failed()->forUser(User::factory()->create())->create();

        foreach ([
            'PENDING' => $pending,
            'PROCESSED' => $processed,
            'FAILED' => $failed,
        ] as $status => $expected) {
            $component = Livewire::test(ListOperationalEvents::class);
            $component->assertOk();
            $component->assertSee('Related User');
            $component->filterTable('status', $status)
                ->assertCanSeeTableRecords([$expected]);
        }

        Livewire::test(ViewOperationalEvent::class, ['record' => $pending->getRouteKey()])
            ->assertOk()
            ->assertSee('Related User');
    }

    /** @return array<string, mixed> */
    private function context(int $meetingCount = 1): array
    {
        $this->contextCounter++;
        $term = Term::factory()->create([
            'label' => 'TAL-94D3b Term '.$this->contextCounter,
            'state' => Term::StateActive,
            'default_max_units' => 21.00,
        ]);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $room = Room::factory()->create([
            'code' => 'D3B-R'.$this->contextCounter.'A',
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);
        $replacementRoom = Room::factory()->create([
            'code' => 'D3B-R'.$this->contextCounter.'B',
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);
        $program = Program::factory()->create(['code' => 'D3B'.$this->contextCounter]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['code' => 'D3BC'.$this->contextCounter]);
        $specification = CourseSpecification::factory()->for($course)->create([
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace, TermOffering::ModalityOnline],
            'credit_units' => 3.00,
            'same_faculty_default' => true,
        ]);
        $component = CourseComponent::factory()->for($specification)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 3.00,
            'meeting_pattern' => $meetingCount === 2 ? '2x90' : '1x180',
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
            'code' => 'D3B-S'.$this->contextCounter,
            'capacity' => 30,
            'state' => Section::StateOpen,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'TAL-94D3b Group '.$this->contextCounter,
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
                'required_duration_minutes' => $meetingCount === 2 ? 90 : 180,
                'meeting_count' => $meetingCount,
                'modality' => TermOffering::ModalityFaceToFace,
                'validation_state' => SchedulingDemand::ValidationReadyForReview,
            ]);
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'requested_by' => null,
            'input_snapshot' => ['scheduling_demands' => [['scheduling_demand_id' => $demand->id]]],
            'input_hash' => hash('sha256', (string) Str::uuid()),
            'solver_version' => 'tal94d3b-test-solver',
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
                'ends_at' => $meetingCount === 2 ? '09:30:00' : '11:00:00',
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
     * @param  list<SectionMeeting>  $meetings
     */
    private function activeBinding(array $context, array $meetings, bool $active = true): User
    {
        $user = $this->staff('student');
        $student = StudentProfile::factory()->for($user)->create(['program_id' => $context['program']->id]);
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

        foreach ($meetings as $meeting) {
            StudentScheduleBinding::query()->create([
                'course_enrollment_id' => $courseEnrollment->id,
                'section_meeting_id' => $meeting->id,
                'is_active' => $active,
                'effective_from' => now()->toDateString(),
                'source' => StudentScheduleBinding::SourceRegistrarPlacement,
            ]);
        }

        return $user;
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, mixed> */
    private function mailPayload(): array
    {
        return [
            'revision_event_ids' => [1],
            'change_types' => [ScheduleRevisionEvent::ChangeRoom],
            'effective_date' => now()->toDateString(),
            'changes' => [[
                'revision_event_id' => 1,
                'section_meeting_id' => 1,
                'meeting_sequence' => 1,
                'change_type' => ScheduleRevisionEvent::ChangeRoom,
                'change_label' => 'Room Change',
                'course' => 'TEST101',
                'section' => 'TEST-A',
                'before' => [
                    'faculty' => 'Previous Faculty',
                    'room' => 'ROOM-A',
                    'day' => 'Monday',
                    'starts_at' => '08:00',
                    'ends_at' => '11:00',
                    'modality' => TermOffering::ModalityFaceToFace,
                    'state' => SectionMeeting::StateActive,
                ],
                'after' => [
                    'faculty' => 'Previous Faculty',
                    'room' => 'ROOM-B',
                    'day' => 'Monday',
                    'starts_at' => '08:00',
                    'ends_at' => '11:00',
                    'modality' => TermOffering::ModalityFaceToFace,
                    'state' => SectionMeeting::StateActive,
                ],
            ]],
        ];
    }
}
