<?php

namespace Tests\Feature;

use App\Actions\Scheduling\SectionMeetingAssignmentService;
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
            'subject_id' => Subject::factory()->create()->id,
            'faculty_id' => $faculty->id,
            'room' => 'RUT 201',
            'day_of_week' => 2,
            'starts_at' => '09:00',
            'ends_at' => '11:00',
            'modality' => 'on_site',
        ], $registrar);
    }

    public function test_prepare_for_schedule_change_ignores_the_current_meeting_but_rejects_other_conflicts(): void
    {
        [$term, $section, $subject, $registrar, $faculty] = $this->scheduleFixtures();
        $otherFaculty = User::factory()->create();

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

        return [$term, $section, $subject, $registrar, $faculty];
    }
}
