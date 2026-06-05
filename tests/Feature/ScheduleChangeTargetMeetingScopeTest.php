<?php

namespace Tests\Feature;

use App\Models\ScheduleChange;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScheduleChangeTargetMeetingScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_change_options_are_limited_to_selected_term_with_descriptive_labels(): void
    {
        $selectedTerm = Term::factory()->create(['term_name' => 'AY 2026 First Term']);
        $otherTerm = Term::factory()->create(['term_name' => 'AY 2026 Second Term']);
        $selectedMeeting = $this->createSectionMeeting($selectedTerm, [
            'room' => 'Room 204',
            'day_of_week' => 2,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
        ]);
        $otherMeeting = $this->createSectionMeeting($otherTerm, [
            'room' => 'Room 301',
            'day_of_week' => 4,
        ]);

        $options = SectionMeeting::scheduleChangeOptionsFor((string) $selectedTerm->id);

        $this->assertArrayHasKey($selectedMeeting->id, $options);
        $this->assertArrayNotHasKey($otherMeeting->id, $options);
        $this->assertStringContainsString($selectedMeeting->section->name, $options[$selectedMeeting->id]);
        $this->assertStringContainsString($selectedMeeting->subject->code, $options[$selectedMeeting->id]);
        $this->assertStringContainsString('Tuesday', $options[$selectedMeeting->id]);
        $this->assertStringContainsString('08:00-10:00', $options[$selectedMeeting->id]);
        $this->assertStringContainsString('Room 204', $options[$selectedMeeting->id]);
    }

    public function test_schedule_change_options_are_empty_without_a_valid_term(): void
    {
        Term::factory()->create();

        $this->assertSame([], SectionMeeting::scheduleChangeOptionsFor(null));
        $this->assertSame([], SectionMeeting::scheduleChangeOptionsFor('not-an-id'));
    }

    public function test_schedule_change_validation_accepts_matching_term_and_meeting_ids(): void
    {
        $term = Term::factory()->create();
        $meeting = $this->createSectionMeeting($term);

        $validated = ScheduleChange::validateTargetMeetingData([
            'term_id' => (string) $term->id,
            'section_meeting_id' => (string) $meeting->id,
            'reason' => 'Faculty load conflict.',
        ]);

        $this->assertSame($term->id, $validated['term_id']);
        $this->assertSame($meeting->id, $validated['section_meeting_id']);
        $this->assertSame('Faculty load conflict.', $validated['reason']);
    }

    public function test_schedule_change_validation_rejects_cross_term_meeting_ids(): void
    {
        $selectedTerm = Term::factory()->create();
        $otherTerm = Term::factory()->create();
        $otherMeeting = $this->createSectionMeeting($otherTerm);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Choose an official schedule from the selected term.');

        ScheduleChange::validateTargetMeetingData([
            'term_id' => $selectedTerm->id,
            'section_meeting_id' => $otherMeeting->id,
        ]);
    }

    public function test_schedule_change_validation_rejects_malformed_target_ids(): void
    {
        $this->expectException(ValidationException::class);

        ScheduleChange::validateTargetMeetingData([
            'term_id' => 'first-term',
            'section_meeting_id' => 'meeting-1',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSectionMeeting(Term $term, array $overrides = []): SectionMeeting
    {
        $faculty = User::factory()->create([
            ...User::staffNamePayload('Ada', null, 'Lovelace'),
        ]);
        $committer = User::factory()->create([
            ...User::staffNamePayload('Grace', null, 'Hopper'),
        ]);
        $section = Section::factory()->create(['term_id' => $term->id]);
        $subject = Subject::factory()->create(['code' => fake()->unique()->bothify('REG-###')]);

        return SectionMeeting::query()->create([
            'term_id' => $term->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'faculty_id' => $faculty->id,
            'room' => 'Room 101',
            'day_of_week' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '11:00:00',
            'modality' => 'on_site',
            'committed_by' => $committer->id,
            'committed_at' => now(),
            ...$overrides,
        ]);
    }
}
