<?php

namespace Tests\Feature;

use App\Actions\Scheduling\SectionMeetingAssignmentService;
use App\Models\FacultyAvailabilityPeriod;
use App\Models\FacultyAvailabilitySubmission;
use App\Models\FacultyAvailabilityWindow;
use App\Models\FacultySubjectEligibility;
use App\Models\Program;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SectionMeetingAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_prepare_for_create_sets_commit_metadata_and_normalizes_typed_schedule_fields(): void
    {
        [$term, $section, $subject, $registrar, $faculty, $deliveryGroup] = $this->scheduleFixtures();
        $committedAt = CarbonImmutable::parse('2026-06-03 08:15:00', config('app.timezone'));

        $payload = app(SectionMeetingAssignmentService::class)->prepareForCreate([
            'term_id' => (string) $term->id,
            'section_id' => (string) $section->id,
            'section_delivery_group_id' => (string) $deliveryGroup->id,
            'subject_id' => (string) $subject->id,
            'faculty_id' => (string) $faculty->id,
            'room' => ' RUT 201 ',
            'day_of_week' => '2',
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
            'modality' => 'on_site',
        ], $registrar, $committedAt);

        $this->assertSame($term->id, $payload['term_id']);
        $this->assertSame($section->id, $payload['section_id']);
        $this->assertSame($deliveryGroup->id, $payload['section_delivery_group_id']);
        $this->assertSame($subject->id, $payload['subject_id']);
        $this->assertSame($faculty->id, $payload['faculty_id']);
        $this->assertSame('RUT 201', $payload['room']);
        $this->assertSame(2, $payload['day_of_week']);
        $this->assertSame('08:00', $payload['starts_at']);
        $this->assertSame('10:00', $payload['ends_at']);
        $this->assertSame('on_site', $payload['modality']);
        $this->assertNull($payload['schedule_generation_run_id']);
        $this->assertSame($registrar->id, $payload['committed_by']);
        $this->assertSame($committedAt, $payload['committed_at']);
        $this->assertArrayNotHasKey('availability_override_reason', $payload);
    }

    public function test_prepare_for_create_rejects_overlapping_faculty_or_room_assignments(): void
    {
        [$term, $section, $subject, $registrar, $faculty, $deliveryGroup] = $this->scheduleFixtures();
        $otherSubject = Subject::factory()->create();
        FacultySubjectEligibility::factory()->create([
            'faculty_id' => $faculty->id,
            'subject_id' => $otherSubject->id,
            'term_id' => null,
        ]);

        SectionMeeting::query()->create([
            'term_id' => $term->id,
            'section_id' => $section->id,
            'section_delivery_group_id' => $deliveryGroup->id,
            'subject_id' => $subject->id,
            'faculty_id' => $faculty->id,
            'room' => 'RUT 201',
            'day_of_week' => 2,
            'starts_at' => '08:00',
            'ends_at' => '10:00',
            'modality' => 'on_site',
            'committed_by' => $registrar->id,
            'committed_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        $otherSection = Section::factory()->for($term)->for(Program::factory())->create();
        $otherDeliveryGroup = $this->deliveryGroupFor($otherSection, room: 'RUT 201');

        app(SectionMeetingAssignmentService::class)->prepareForCreate([
            'term_id' => $term->id,
            'section_id' => $otherSection->id,
            'section_delivery_group_id' => $otherDeliveryGroup->id,
            'subject_id' => $otherSubject->id,
            'faculty_id' => $faculty->id,
            'room' => 'RUT 201',
            'day_of_week' => 2,
            'starts_at' => '09:00',
            'ends_at' => '11:00',
            'modality' => 'on_site',
        ], $registrar);
    }

    public function test_prepare_for_create_rejects_manual_assignments_after_term_schedule_is_published(): void
    {
        [$term, $section, $subject, $registrar, $faculty, $deliveryGroup] = $this->scheduleFixtures();

        ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'requested_by' => $registrar->id,
            'generated_at' => now(),
            'committed_by' => $registrar->id,
            'committed_at' => now(),
            'published_by' => $registrar->id,
            'published_at' => now(),
            'constraint_summary' => [],
        ]);

        $this->expectException(ValidationException::class);

        app(SectionMeetingAssignmentService::class)->prepareForCreate([
            'term_id' => $term->id,
            'section_id' => $section->id,
            'section_delivery_group_id' => $deliveryGroup->id,
            'subject_id' => $subject->id,
            'faculty_id' => $faculty->id,
            'room' => 'RUT 201',
            'day_of_week' => 2,
            'starts_at' => '08:00',
            'ends_at' => '10:00',
            'modality' => 'on_site',
        ], $registrar);
    }

    public function test_prepare_for_create_rejects_delivery_group_overlap_as_hard_conflict_even_with_availability_override_reason(): void
    {
        [$term, $section, $subject, $registrar, $faculty, $deliveryGroup] = $this->scheduleFixtures();
        $otherFaculty = User::factory()->create();
        FacultySubjectEligibility::factory()->create([
            'faculty_id' => $otherFaculty->id,
            'subject_id' => $subject->id,
            'term_id' => null,
        ]);
        $this->createFacultyAvailability($term, $otherFaculty, dayOfWeek: 2, startsAt: '08:00:00', endsAt: '12:00:00');

        SectionMeeting::query()->create([
            'term_id' => $term->id,
            'section_id' => $section->id,
            'section_delivery_group_id' => $deliveryGroup->id,
            'subject_id' => $subject->id,
            'faculty_id' => $faculty->id,
            'room' => 'RUT 201',
            'day_of_week' => 2,
            'starts_at' => '08:00',
            'ends_at' => '10:00',
            'modality' => 'on_site',
            'committed_by' => $registrar->id,
            'committed_at' => now(),
        ]);

        try {
            app(SectionMeetingAssignmentService::class)->prepareForCreate([
                'term_id' => $term->id,
                'section_id' => $section->id,
                'section_delivery_group_id' => $deliveryGroup->id,
                'subject_id' => $subject->id,
                'faculty_id' => $otherFaculty->id,
                'room' => 'RUT 201',
                'day_of_week' => 2,
                'starts_at' => '09:00',
                'ends_at' => '11:00',
                'modality' => 'on_site',
                'availability_override_reason' => 'Faculty agreed to teach outside submitted availability.',
            ], $registrar);

            $this->fail('Expected delivery group overlap to remain a hard conflict.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('section_delivery_group_id', $exception->errors());
        }
    }

    public function test_prepare_for_create_rejects_faculty_without_active_subject_eligibility(): void
    {
        $term = Term::factory()->create();
        $program = Program::factory()->create();
        $section = Section::factory()->for($term)->for($program)->create();
        $subject = Subject::factory()->create();
        $registrar = User::factory()->create();
        $faculty = User::factory()->create();

        $this->expectException(ValidationException::class);

        $deliveryGroup = $this->deliveryGroupFor($section);

        app(SectionMeetingAssignmentService::class)->prepareForCreate([
            'term_id' => $term->id,
            'section_id' => $section->id,
            'section_delivery_group_id' => $deliveryGroup->id,
            'subject_id' => $subject->id,
            'faculty_id' => $faculty->id,
            'room' => 'RUT 201',
            'day_of_week' => 2,
            'starts_at' => '08:00',
            'ends_at' => '10:00',
            'modality' => 'on_site',
        ], $registrar);
    }

    public function test_prepare_for_create_requires_faculty_for_all_modalities(): void
    {
        [$term, $section, $subject, $registrar] = $this->scheduleFixtures();

        foreach (['on_site', 'online', 'modular'] as $modality) {
            $deliveryGroup = $this->deliveryGroupFor(
                $section,
                modality: $modality,
                room: $modality === 'on_site' ? 'RUT 201' : null,
            );

            try {
                app(SectionMeetingAssignmentService::class)->prepareForCreate([
                    'term_id' => $term->id,
                    'section_id' => $section->id,
                    'section_delivery_group_id' => $deliveryGroup->id,
                    'subject_id' => $subject->id,
                    'faculty_id' => null,
                    'room' => $modality === 'on_site' ? 'RUT 201' : null,
                    'day_of_week' => 2,
                    'starts_at' => '08:00',
                    'ends_at' => '10:00',
                    'modality' => $modality,
                ], $registrar);

                $this->fail("Expected missing faculty to be rejected for {$modality} schedules.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('faculty_id', $exception->errors());
            }
        }
    }

    public function test_prepare_for_create_accepts_modular_without_room_but_with_eligible_faculty(): void
    {
        [$term, $section, $subject, $registrar, $faculty] = $this->scheduleFixtures();
        $deliveryGroup = $this->deliveryGroupFor($section, modality: 'modular', room: null);

        $payload = app(SectionMeetingAssignmentService::class)->prepareForCreate([
            'term_id' => $term->id,
            'section_id' => $section->id,
            'section_delivery_group_id' => $deliveryGroup->id,
            'subject_id' => $subject->id,
            'faculty_id' => $faculty->id,
            'room' => null,
            'day_of_week' => 2,
            'starts_at' => '08:00',
            'ends_at' => '10:00',
            'modality' => 'modular',
        ], $registrar);

        $this->assertSame($faculty->id, $payload['faculty_id']);
        $this->assertNull($payload['room']);
        $this->assertSame('modular', $payload['modality']);
    }

    public function test_prepare_for_create_accepts_term_specific_or_global_active_subject_eligibility(): void
    {
        $term = Term::factory()->create();
        $program = Program::factory()->create();
        $section = Section::factory()->for($term)->for($program)->create();
        $deliveryGroup = $this->deliveryGroupFor($section);
        $subject = Subject::factory()->create();
        $registrar = User::factory()->create();
        $faculty = User::factory()->create();

        FacultySubjectEligibility::factory()->create([
            'faculty_id' => $faculty->id,
            'subject_id' => $subject->id,
            'term_id' => null,
        ]);
        $this->createFacultyAvailability($term, $faculty, dayOfWeek: 2, startsAt: '08:00:00', endsAt: '12:00:00');

        $payload = app(SectionMeetingAssignmentService::class)->prepareForCreate([
            'term_id' => $term->id,
            'section_id' => $section->id,
            'section_delivery_group_id' => $deliveryGroup->id,
            'subject_id' => $subject->id,
            'faculty_id' => $faculty->id,
            'room' => 'RUT 201',
            'day_of_week' => 2,
            'starts_at' => '08:00',
            'ends_at' => '10:00',
            'modality' => 'on_site',
        ], $registrar);

        $this->assertSame($faculty->id, $payload['faculty_id']);
    }

    public function test_prepare_for_create_rejects_faculty_without_submitted_or_locked_availability(): void
    {
        [$term, $section, $subject, $registrar, $faculty, $deliveryGroup] = $this->scheduleFixtures(createAvailability: false);

        try {
            app(SectionMeetingAssignmentService::class)->prepareForCreate([
                'term_id' => $term->id,
                'section_id' => $section->id,
                'section_delivery_group_id' => $deliveryGroup->id,
                'subject_id' => $subject->id,
                'faculty_id' => $faculty->id,
                'room' => 'RUT 201',
                'day_of_week' => 2,
                'starts_at' => '08:00',
                'ends_at' => '10:00',
                'modality' => 'on_site',
            ], $registrar);

            $this->fail('Expected missing faculty availability to require an override reason.');
        } catch (ValidationException $exception) {
            $this->assertSame([
                'The selected faculty has no submitted or locked availability for this term.',
            ], $exception->errors()['faculty_id']);
        }
    }

    public function test_prepare_for_create_rejects_meeting_outside_faculty_availability_even_with_review_reason(): void
    {
        [$term, $section, $subject, $registrar, $faculty, $deliveryGroup] = $this->scheduleFixtures(
            availabilityStartsAt: '08:00:00',
            availabilityEndsAt: '09:00:00',
        );
        $committedAt = CarbonImmutable::parse('2026-06-15 09:00:00', config('app.timezone'));

        try {
            app(SectionMeetingAssignmentService::class)->prepareForCreate([
                'term_id' => $term->id,
                'section_id' => $section->id,
                'section_delivery_group_id' => $deliveryGroup->id,
                'subject_id' => $subject->id,
                'faculty_id' => $faculty->id,
                'room' => 'RUT 201',
                'day_of_week' => 2,
                'starts_at' => '10:00',
                'ends_at' => '11:00',
                'modality' => 'on_site',
                'availability_override_reason' => 'Faculty confirmed availability by signed message after the period closed.',
            ], $registrar, $committedAt);

            $this->fail('Expected outside faculty availability to remain a hard block.');
        } catch (ValidationException $exception) {
            $this->assertSame([
                'The proposed meeting is outside the selected faculty availability. Review notes do not override this hard scheduling constraint.',
            ], $exception->errors()['faculty_id']);
        }
    }

    /**
     * @return array{Term, Section, Subject, User, User, SectionDeliveryGroup}
     */
    private function scheduleFixtures(
        bool $createAvailability = true,
        string $availabilityStartsAt = '08:00:00',
        string $availabilityEndsAt = '12:00:00',
    ): array {
        $term = Term::factory()->create();
        $program = Program::factory()->create();
        $section = Section::factory()->for($term)->for($program)->create();
        $deliveryGroup = $this->deliveryGroupFor($section);
        $subject = Subject::factory()->create();
        $registrar = User::factory()->create();
        $faculty = User::factory()->create();
        FacultySubjectEligibility::factory()->create([
            'faculty_id' => $faculty->id,
            'subject_id' => $subject->id,
            'term_id' => null,
        ]);

        if ($createAvailability) {
            $this->createFacultyAvailability(
                $term,
                $faculty,
                dayOfWeek: 2,
                startsAt: $availabilityStartsAt,
                endsAt: $availabilityEndsAt,
            );
        }

        return [$term, $section, $subject, $registrar, $faculty, $deliveryGroup];
    }

    private function deliveryGroupFor(
        Section $section,
        string $modality = 'on_site',
        ?string $room = 'RUT 201',
    ): SectionDeliveryGroup {
        return SectionDeliveryGroup::factory()->create([
            'section_id' => $section->id,
            'modality' => $modality,
            'capacity' => 30,
            'assigned_count' => 25,
            'room_required' => in_array($modality, ['on_site', 'blended'], true),
            'room' => in_array($modality, ['on_site', 'blended'], true) ? $room : null,
            'status' => SectionDeliveryGroup::StatusActive,
        ]);
    }

    private function createFacultyAvailability(
        Term $term,
        User $faculty,
        int $dayOfWeek,
        string $startsAt,
        string $endsAt,
    ): FacultyAvailabilitySubmission {
        $period = FacultyAvailabilityPeriod::query()->firstOrCreate(
            ['term_id' => $term->id],
            [
                'opens_at' => now()->subDay(),
                'closes_at' => now()->addDays(7),
                'status' => 'open',
                'created_by' => User::factory()->create()->id,
                'locked_at' => null,
            ],
        );

        $submission = FacultyAvailabilitySubmission::factory()->create([
            'term_id' => $term->id,
            'availability_period_id' => $period->id,
            'faculty_id' => $faculty->id,
            'status' => FacultyAvailabilitySubmission::StatusLocked,
            'version' => 1,
            'locked_at' => now(),
        ]);

        FacultyAvailabilityWindow::factory()->create([
            'submission_id' => $submission->id,
            'day_of_week' => $dayOfWeek,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        return $submission;
    }
}
