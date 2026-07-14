<?php

namespace Tests\Feature;

use App\Actions\Enrollment\FinalizeOfficialEnrollment;
use App\Actions\Scheduling\SchedulePublishService;
use App\Actions\Scheduling\ScheduleReleaseNotificationService;
use App\Mail\ScheduleReleasedMail;
use App\Mail\ScheduleRevisionMail;
use App\Models\Assessment;
use App\Models\CandidateScheduleRow;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\FacultyQualification;
use App\Models\LedgerEntry;
use App\Models\OperationalEvent;
use App\Models\Payment;
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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL94E2bScheduleReleasedNotificationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

        foreach ([User::StaffRoleRegistrar, User::StaffRoleFaculty, 'student'] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_publication_queues_one_release_email_for_each_distinct_assigned_faculty_and_records_pending_evidence(): void
    {
        Mail::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $assignedFaculty = $this->staff(User::StaffRoleFaculty);
        $unrelatedFaculty = $this->staff(User::StaffRoleFaculty);
        $unrelatedStudent = $this->staff('student');
        $context = $this->publicationContext($assignedFaculty);

        $published = app(SchedulePublishService::class)->publish($context['run'], $registrar);

        Mail::assertQueuedCount(1);
        Mail::assertQueued(
            ScheduleReleasedMail::class,
            function (ScheduleReleasedMail $mail) use ($assignedFaculty, $context): bool {
                $this->assertTrue($mail->hasTo($assignedFaculty->email));
                $this->assertSame($context['term']->label, $mail->termLabel);
                $this->assertSame(route('filament.admin.pages.faculty-schedule'), $mail->scheduleUrl);
                $mail->assertSeeInHtml('View Schedule')
                    ->assertDontSeeInHtml('publication note')
                    ->assertDontSeeInHtml('08:00');

                return true;
            },
        );
        foreach ([$registrar, $unrelatedFaculty, $unrelatedStudent] as $nonRecipient) {
            Mail::assertNotQueued(
                ScheduleReleasedMail::class,
                fn (ScheduleReleasedMail $mail): bool => $mail->hasTo($nonRecipient->email),
            );
        }

        $deliveryEvent = OperationalEvent::query()
            ->where('event_type', 'schedule_released_email')
            ->sole();

        $this->assertSame('PENDING', $deliveryEvent->status);
        $this->assertSame($assignedFaculty->id, $deliveryEvent->user_id);
        $this->assertSame(ScheduleGenerationRun::class, $deliveryEvent->related_record_type);
        $this->assertSame($published->id, $deliveryEvent->related_record_id);
        $this->assertSame(
            "schedule-release:published-run:{$published->id}:user:{$assignedFaculty->id}",
            $deliveryEvent->external_id,
        );

        app(ScheduleReleaseNotificationService::class)->recordPublishedRun($published);

        Mail::assertQueuedCount(1);
        $this->assertSame(1, OperationalEvent::query()->where('event_type', 'schedule_released_email')->count());
    }

    public function test_official_enrollment_queues_one_release_email_for_its_student_and_repeat_finalization_is_idempotent(): void
    {
        Mail::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $fixture = $this->officialEnrollmentContext();

        $result = app(FinalizeOfficialEnrollment::class)->execute(
            $fixture['enrollment'],
            $registrar,
            recordedAt: CarbonImmutable::parse('2026-07-14 10:00:00'),
        );

        Mail::assertQueuedCount(1);
        Mail::assertQueued(
            ScheduleReleasedMail::class,
            function (ScheduleReleasedMail $mail) use ($fixture): bool {
                $this->assertTrue($mail->hasTo($fixture['student']->email));
                $this->assertSame($fixture['term']->label, $mail->termLabel);
                $this->assertSame(route('filament.student.pages.schedule-view'), $mail->scheduleUrl);

                return true;
            },
        );

        $deliveryEvent = OperationalEvent::query()
            ->where('event_type', 'schedule_released_email')
            ->sole();

        $this->assertSame('PENDING', $deliveryEvent->status);
        $this->assertSame($fixture['student']->id, $deliveryEvent->user_id);
        $this->assertSame(Enrollment::class, $deliveryEvent->related_record_type);
        $this->assertSame($result->id, $deliveryEvent->related_record_id);
        $this->assertSame(
            "schedule-release:official-enrollment:{$result->id}:user:{$fixture['student']->id}",
            $deliveryEvent->external_id,
        );

        app(FinalizeOfficialEnrollment::class)->execute(
            $result->fresh(),
            $registrar,
            recordedAt: CarbonImmutable::parse('2026-07-14 11:00:00'),
        );

        Mail::assertQueuedCount(1);
        $this->assertSame(1, OperationalEvent::query()->where('event_type', 'schedule_released_email')->count());
    }

    public function test_missing_and_invalid_faculty_emails_record_failed_evidence_without_queueing_mail_or_reversing_publication(): void
    {
        Mail::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);

        foreach (['', 'not-an-email-address'] as $email) {
            $faculty = $this->staff(User::StaffRoleFaculty, ['email' => $email]);
            $context = $this->publicationContext($faculty);
            $published = app(SchedulePublishService::class)->publish($context['run'], $registrar);

            $this->assertSame(ScheduleGenerationRun::StatusPublished, $published->status);
        }

        Mail::assertNothingQueued();
        $deliveryEvents = OperationalEvent::query()
            ->where('event_type', 'schedule_released_email')
            ->get();

        $this->assertCount(2, $deliveryEvents);
        foreach ($deliveryEvents as $deliveryEvent) {
            $this->assertSame('FAILED', $deliveryEvent->status);
            $this->assertNotNull($deliveryEvent->processed_at);
            $this->assertNotNull($deliveryEvent->failed_at);
            $diagnostics = $deliveryEvent->diagnostics;
            $this->assertIsArray($diagnostics);
            $this->assertArrayHasKey('reason', $diagnostics);
            $this->assertSame('Recipient email is missing or invalid.', $diagnostics['reason']);
        }
    }

    public function test_queue_setup_failure_records_redacted_failed_evidence_without_reversing_publication(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $context = $this->publicationContext($faculty);

        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new RuntimeException('smtp_password=must-not-be-persisted'));

        $published = app(SchedulePublishService::class)->publish($context['run'], $registrar);

        $this->assertSame(ScheduleGenerationRun::StatusPublished, $published->status);
        $this->assertSame(2, $published->sectionMeetings()->count());

        $deliveryEvent = OperationalEvent::query()
            ->where('event_type', 'schedule_released_email')
            ->sole();

        $this->assertSame('FAILED', $deliveryEvent->status);
        $this->assertNotNull($deliveryEvent->processed_at);
        $this->assertNotNull($deliveryEvent->failed_at);
        $diagnostics = $deliveryEvent->diagnostics;
        $this->assertIsArray($diagnostics);
        $this->assertArrayHasKey('exception_type', $diagnostics);
        $this->assertSame(class_basename(RuntimeException::class), $diagnostics['exception_type']);
        $this->assertStringNotContainsString(
            'smtp_password',
            json_encode($diagnostics, JSON_THROW_ON_ERROR),
        );
    }

    public function test_tracked_mail_sent_and_failed_hooks_record_transport_evidence_for_release_and_revision_mail(): void
    {
        $recipient = User::factory()->create();
        $releaseProcessed = OperationalEvent::factory()->forUser($recipient)->create([
            'event_type' => 'schedule_released_email',
            'status' => 'PENDING',
            'processed_at' => null,
            'sent_at' => null,
            'payload' => ['trigger' => 'published_run'],
        ]);
        $revisionProcessed = OperationalEvent::factory()->forUser($recipient)->create([
            'event_type' => 'schedule_revision_email',
            'external_id' => 'schedule-revision-processed-'.Str::uuid(),
            'status' => 'PENDING',
            'processed_at' => null,
            'sent_at' => null,
            'payload' => ['revision_event_ids' => [1], 'changes' => []],
        ]);
        $releaseFailed = OperationalEvent::factory()->forUser($recipient)->create([
            'event_type' => 'schedule_released_email',
            'external_id' => 'schedule-release-failed-'.Str::uuid(),
            'status' => 'PENDING',
            'processed_at' => null,
            'sent_at' => null,
            'payload' => ['trigger' => 'official_enrollment'],
        ]);

        Mail::mailer('array')->to($recipient->email)->sendNow(new ScheduleReleasedMail(
            operationalEventId: (int) $releaseProcessed->id,
            recipientName: (string) $recipient->name,
            termLabel: 'Tracked Mail Term',
            scheduleUrl: route('filament.student.pages.schedule-view'),
        ));
        Mail::mailer('array')->to($recipient->email)->sendNow(new ScheduleRevisionMail(
            operationalEventId: (int) $revisionProcessed->id,
            recipientName: (string) $recipient->name,
            revisionPayload: ['changes' => []],
        ));

        foreach ([$releaseProcessed, $revisionProcessed] as $processedEvent) {
            $processedEvent->refresh();
            $this->assertSame('PROCESSED', $processedEvent->status);
            $this->assertNotNull($processedEvent->processed_at);
            $this->assertNotNull($processedEvent->sent_at);
            $payload = $processedEvent->payload;
            $this->assertIsArray($payload);
            $this->assertArrayHasKey('delivery', $payload);
            $this->assertIsArray($payload['delivery']);
            $this->assertArrayHasKey('transport_message_id', $payload['delivery']);
            $this->assertArrayHasKey('accepted_at', $payload['delivery']);
            $this->assertNotEmpty($payload['delivery']['transport_message_id']);
            $this->assertNotEmpty($payload['delivery']['accepted_at']);
        }

        $mailable = new ScheduleReleasedMail(
            operationalEventId: (int) $releaseFailed->id,
            recipientName: (string) $recipient->name,
            termLabel: 'Tracked Mail Term',
            scheduleUrl: route('filament.student.pages.schedule-view'),
        );
        $mailable->failed(new RuntimeException('smtp_password=must-not-be-persisted'));

        $releaseFailed->refresh();
        $this->assertSame('FAILED', $releaseFailed->status);
        $this->assertNotNull($releaseFailed->processed_at);
        $this->assertNotNull($releaseFailed->failed_at);
        $this->assertStringNotContainsString(
            'smtp_password',
            json_encode($releaseFailed->diagnostics, JSON_THROW_ON_ERROR),
        );
    }

    public function test_publication_transaction_rollback_creates_no_release_mail_or_delivery_evidence(): void
    {
        Mail::fake();

        $faculty = $this->staff(User::StaffRoleFaculty);
        $context = $this->publicationContext($faculty);
        $eventName = 'eloquent.created: '.SectionMeeting::class;

        Event::listen($eventName, function (SectionMeeting $meeting): void {
            if ($meeting->meeting_sequence === 2) {
                throw new RuntimeException('Forced publication rollback.');
            }
        });

        try {
            app(SchedulePublishService::class)->publish(
                $context['run'],
                $this->staff(User::StaffRoleRegistrar),
            );
            $this->fail('Expected the publication transaction to roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced publication rollback.', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $context['run']->fresh()->status);
        $this->assertSame(0, $context['run']->sectionMeetings()->count());
        $this->assertSame(0, OperationalEvent::query()->where('event_type', 'schedule_released_email')->count());
        Mail::assertNothingQueued();
    }

    public function test_failed_official_enrollment_creates_no_release_mail_or_delivery_evidence(): void
    {
        Mail::fake();

        $fixture = $this->officialEnrollmentContext(withPostedPayment: false);

        try {
            app(FinalizeOfficialEnrollment::class)->execute(
                $fixture['enrollment'],
                $this->staff(User::StaffRoleRegistrar),
            );
            $this->fail('Expected the unresolved Finance Gate to block official enrollment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('gates', $exception->errors());
        }

        $this->assertNotSame('officially_enrolled', $fixture['enrollment']->fresh()->status);
        $this->assertSame(0, OperationalEvent::query()->where('event_type', 'schedule_released_email')->count());
        Mail::assertNothingQueued();
    }

    /**
     * @return array{term:Term,run:ScheduleGenerationRun}
     */
    private function publicationContext(User $faculty): array
    {
        $term = Term::factory()->create(['label' => 'TAL-94E2b Publication Term']);
        $room = Room::factory()->create([
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);
        $offering = TermOffering::factory()
            ->for($term)
            ->create(['modality' => TermOffering::ModalityFaceToFace]);
        $specification = CourseSpecification::factory()->create();
        $component = CourseComponent::factory()
            ->for($specification)
            ->create(['weekly_contact_hours' => 2.00]);
        $section = Section::factory()
            ->for($offering, 'termOffering')
            ->create(['capacity' => 30]);
        $group = SectionDeliveryGroup::factory()
            ->for($section)
            ->create([
                'expected_count' => 30,
                'modality' => TermOffering::ModalityFaceToFace,
            ]);

        FacultyQualification::factory()
            ->for($faculty, 'faculty')
            ->for($specification->course)
            ->create(['is_active' => true]);

        $demand = SchedulingDemand::factory()
            ->for($offering)
            ->for($component)
            ->for($group)
            ->create([
                'modality' => TermOffering::ModalityFaceToFace,
                'meeting_count' => 2,
                'required_duration_minutes' => 120,
            ]);
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'requested_by' => null,
            'input_snapshot' => [],
            'input_hash' => hash('sha256', (string) Str::uuid()),
            'solver_version' => 'tal94e2b-test-solver',
        ]);

        foreach ([
            [1, 1, '08:00:00', '10:00:00'],
            [2, 2, '10:00:00', '12:00:00'],
        ] as [$sequence, $day, $startsAt, $endsAt]) {
            CandidateScheduleRow::query()->create([
                'schedule_run_id' => $run->id,
                'scheduling_demand_id' => $demand->id,
                'meeting_sequence' => $sequence,
                'faculty_user_id' => $faculty->id,
                'room_id' => $room->id,
                'day_of_week' => $day,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'time_block_key' => "D{$day}-".str_replace(':', '', mb_substr($startsAt, 0, 5)),
                'status' => CandidateScheduleRow::StatusOk,
                'scores' => [],
                'warnings' => [],
                'violations' => [],
            ]);
        }

        return compact('term', 'run');
    }

    /**
     * @return array{student:User,term:Term,enrollment:Enrollment}
     */
    private function officialEnrollmentContext(bool $withPostedPayment = true): array
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $profile = StudentProfile::factory()->create();
        $profile->user->forceFill(['status' => User::StatusActive])->save();
        $profile->user->assignRole('student');
        $student = $profile->user->fresh();
        $term = Term::factory()->create([
            'label' => 'TAL-94E2b Enrollment Term',
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-10-31',
        ]);
        $enrollment = Enrollment::factory()
            ->for($profile)
            ->for($term)
            ->create(['status' => 'pending_review']);
        $assessment = Assessment::query()->create([
            'enrollment_id' => $enrollment->id,
            'version' => 1,
            'state' => Assessment::StateActive,
            'currency' => 'PHP',
            'subtotal' => '5800.00',
            'discount_total' => '0.00',
            'total' => '5800.00',
            'required_downpayment' => '1500.00',
            'activated_at' => now()->subDay(),
        ]);

        LedgerEntry::query()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'direction' => LedgerEntry::DirectionCharge,
            'category' => 'assessment',
            'amount' => '5800.00',
            'source_type' => Assessment::class,
            'source_id' => $assessment->id,
            'description' => 'Active assessment charge',
            'posted_at' => now()->subHours(2),
            'state' => 'posted',
        ]);
        if ($withPostedPayment) {
            $payment = Payment::factory()
                ->for($profile)
                ->for($term)
                ->create([
                    'amount' => '1500.00',
                    'evidence_status' => 'verified',
                    'verified_at' => now()->subHour(),
                    'or_number' => null,
                    'provider_reference' => 'tal94e2b-payment-'.Str::uuid(),
                ]);
            LedgerEntry::query()->create([
                'student_profile_id' => $profile->id,
                'term_id' => $term->id,
                'enrollment_id' => $enrollment->id,
                'direction' => LedgerEntry::DirectionPayment,
                'category' => 'payment',
                'amount' => $payment->amount,
                'source_type' => Payment::class,
                'source_id' => $payment->id,
                'payment_id' => $payment->id,
                'description' => 'Verified posted payment',
                'posted_at' => now()->subMinutes(45),
                'state' => 'posted',
            ]);
        }

        $specification = CourseSpecification::factory()->create([
            'credit_units' => '3.00',
            'state' => CourseSpecification::StateActive,
        ]);
        $entry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $profile->curriculum_version_id,
            'course_specification_id' => $specification->id,
            'term_type' => Term::TypeFirstSemester,
        ]);
        $offering = TermOffering::factory()
            ->for($term)
            ->for($entry, 'curriculumEntry')
            ->create([
                'modality' => TermOffering::ModalityOnline,
                'state' => TermOffering::StateScheduled,
            ]);
        $section = Section::factory()
            ->for($offering, 'termOffering')
            ->create([
                'capacity' => 30,
                'state' => Section::StateOpen,
            ]);
        $group = SectionDeliveryGroup::factory()
            ->for($section)
            ->create([
                'modality' => TermOffering::ModalityOnline,
                'state' => SectionDeliveryGroup::StateReady,
            ]);
        $component = CourseComponent::factory()
            ->for($specification, 'courseSpecification')
            ->create();
        $demand = SchedulingDemand::factory()
            ->for($offering)
            ->for($component)
            ->for($group)
            ->create([
                'modality' => TermOffering::ModalityOnline,
                'meeting_count' => 1,
            ]);
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'input_snapshot' => [],
            'input_hash' => hash('sha256', (string) Str::uuid()),
            'solver_version' => 'tal94e2b-enrollment-test',
            'published_by' => $faculty->id,
            'published_at' => now(),
            'publication_version' => 1,
        ]);
        $meeting = SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $faculty->id,
            'room_id' => null,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
            'modality' => TermOffering::ModalityOnline,
            'state' => SectionMeeting::StateActive,
            'published_at' => now(),
        ]);
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);
        EnrollmentSeatReservation::query()->create([
            'enrollment_id' => $enrollment->id,
            'course_enrollment_id' => $courseEnrollment->id,
            'section_id' => $section->id,
            'status' => EnrollmentSeatReservation::StatusPending,
            'reserved_at' => now(),
            'registrar_user_id' => $faculty->id,
        ]);
        StudentScheduleBinding::query()->create([
            'course_enrollment_id' => $courseEnrollment->id,
            'section_meeting_id' => $meeting->id,
            'is_active' => true,
            'effective_from' => now()->toDateString(),
            'source' => StudentScheduleBinding::SourceRegistrarPlacement,
        ]);

        return compact('student', 'term', 'enrollment');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function staff(string $role, array $attributes = []): User
    {
        $user = User::factory()->create([
            'status' => User::StatusActive,
            ...$attributes,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
