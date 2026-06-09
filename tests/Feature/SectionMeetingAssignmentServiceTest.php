<?php

namespace Tests\Feature;

use App\Actions\Scheduling\SectionMeetingAssignmentService;
use App\Models\FacultySubjectEligibility;
use App\Models\Program;
use App\Models\Section;
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
        [$term, $section, $subject, $registrar, $faculty] = $this->scheduleFixtures();
        $committedAt = CarbonImmutable::parse('2026-06-03 08:15:00', config('app.timezone'));

        $payload = app(SectionMeetingAssignmentService::class)->prepareForCreate([
            'term_id' => (string) $term->id,
            'section_id' => (string) $section->id,
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
    }

    public function test_prepare_for_create_rejects_overlapping_faculty_or_room_assignments(): void
    {
        [$term, $section, $subject, $registrar, $faculty] = $this->scheduleFixtures();
        $otherSubject = Subject::factory()->create();
        FacultySubjectEligibility::factory()->create([
            'faculty_id' => $faculty->id,
            'subject_id' => $otherSubject->id,
            'term_id' => null,
        ]);

        SectionMeeting::query()->create([
            'term_id' => $term->id,
            'section_id' => $section->id,
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

        app(SectionMeetingAssignmentService::class)->prepareForCreate([
            'term_id' => $term->id,
            'section_id' => Section::factory()->for($term)->for(Program::factory())->create()->id,
            'subject_id' => $otherSubject->id,
            'faculty_id' => $faculty->id,
            'room' => 'RUT 201',
            'day_of_week' => 2,
            'starts_at' => '09:00',
            'ends_at' => '11:00',
            'modality' => 'on_site',
        ], $registrar);
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

        app(SectionMeetingAssignmentService::class)->prepareForCreate([
            'term_id' => $term->id,
            'section_id' => $section->id,
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
            try {
                app(SectionMeetingAssignmentService::class)->prepareForCreate([
                    'term_id' => $term->id,
                    'section_id' => $section->id,
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

        $payload = app(SectionMeetingAssignmentService::class)->prepareForCreate([
            'term_id' => $term->id,
            'section_id' => $section->id,
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
        $subject = Subject::factory()->create();
        $registrar = User::factory()->create();
        $faculty = User::factory()->create();

        FacultySubjectEligibility::factory()->create([
            'faculty_id' => $faculty->id,
            'subject_id' => $subject->id,
            'term_id' => null,
        ]);

        $payload = app(SectionMeetingAssignmentService::class)->prepareForCreate([
            'term_id' => $term->id,
            'section_id' => $section->id,
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

    public function test_prepare_for_schedule_change_ignores_the_current_meeting_but_rejects_other_conflicts(): void
    {
        [$term, $section, $subject, $registrar, $faculty] = $this->scheduleFixtures();
        $otherFaculty = User::factory()->create();
        FacultySubjectEligibility::factory()->create([
            'faculty_id' => $otherFaculty->id,
            'subject_id' => $subject->id,
            'term_id' => null,
        ]);

        $meeting = SectionMeeting::query()->create([
            'term_id' => $term->id,
            'section_id' => $section->id,
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

        $payload = app(SectionMeetingAssignmentService::class)->prepareForScheduleChange($meeting, [
            'faculty_id' => $faculty->id,
            'room' => 'RUT 201',
            'day_of_week' => 2,
            'starts_at' => '08:30',
            'ends_at' => '10:30',
            'modality' => 'on_site',
        ]);

        $this->assertSame('08:30', $payload['starts_at']);
        $this->assertSame('10:30', $payload['ends_at']);

        SectionMeeting::query()->create([
            'term_id' => $term->id,
            'section_id' => Section::factory()->for($term)->for(Program::factory())->create()->id,
            'subject_id' => Subject::factory()->create()->id,
            'faculty_id' => $otherFaculty->id,
            'room' => 'RUT 301',
            'day_of_week' => 2,
            'starts_at' => '10:00',
            'ends_at' => '12:00',
            'modality' => 'on_site',
            'committed_by' => $registrar->id,
            'committed_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        app(SectionMeetingAssignmentService::class)->prepareForScheduleChange($meeting, [
            'faculty_id' => $otherFaculty->id,
            'room' => 'RUT 302',
            'day_of_week' => 2,
            'starts_at' => '10:30',
            'ends_at' => '11:30',
            'modality' => 'on_site',
        ]);
    }

    /**
     * @return array{Term, Section, Subject, User, User}
     */
    private function scheduleFixtures(): array
    {
        $term = Term::factory()->create();
        $program = Program::factory()->create();
        $section = Section::factory()->for($term)->for($program)->create();
        $subject = Subject::factory()->create();
        $registrar = User::factory()->create();
        $faculty = User::factory()->create();
        FacultySubjectEligibility::factory()->create([
            'faculty_id' => $faculty->id,
            'subject_id' => $subject->id,
            'term_id' => null,
        ]);

        return [$term, $section, $subject, $registrar, $faculty];
    }
}
