<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\Scheduling\PublishTimetableVersion;
use App\Actions\Scheduling\RevisePublishedTimetable;
use App\Models\CandidateScheduleRow;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\PublishedTimetableMeeting;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class PublishedTimetableLectureLaboratoryMeetingIdentityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $registrar = Role::findOrCreate(User::StaffRoleRegistrar);
        $registrar->givePermissionTo(Permission::findOrCreate('manage-schedules'));
    }

    public function test_publishing_a_lecture_and_laboratory_section_with_matching_meeting_sequence_succeeds(): void
    {
        $fixture = $this->lectureAndLaboratoryFixture();
        $run = ScheduleGenerationRun::factory()->create(['term_id' => $fixture['term']->id]);
        $rows = new Collection([
            $this->candidateRow($run, $fixture['lectureDemand'], dayOfWeek: 1, startsAt: '08:00:00', endsAt: '09:30:00'),
            $this->candidateRow($run, $fixture['laboratoryDemand'], dayOfWeek: 2, startsAt: '10:00:00', endsAt: '12:00:00'),
        ]);

        $version = app(PublishTimetableVersion::class)->createLocked(
            $run,
            $this->registrar(),
            $rows,
            'SYNTH-TIMETABLE-AUTHORITY',
            'Publish Lecture and Laboratory components together.',
            [],
            [],
        );

        $meetings = $version->meetings()->orderBy('scheduling_demand_id')->get();
        $this->assertSame(2, $meetings->count());
        $this->assertSame(
            [$fixture['lectureDemand']->id, $fixture['laboratoryDemand']->id],
            $meetings->pluck('scheduling_demand_id')->sort()->values()->all(),
        );
        $this->assertTrue($meetings->every(fn (PublishedTimetableMeeting $meeting): bool => (int) $meeting->meeting_sequence === 1));
    }

    public function test_revising_a_published_lecture_and_laboratory_timetable_matches_each_meeting_to_its_component(): void
    {
        $fixture = $this->lectureAndLaboratoryFixture();
        $run = ScheduleGenerationRun::factory()->create([
            'term_id' => $fixture['term']->id,
            'contract_version' => 'legacy-pre-v2',
        ]);
        $rows = new Collection([
            $this->candidateRow($run, $fixture['lectureDemand'], dayOfWeek: 1, startsAt: '08:00:00', endsAt: '09:30:00'),
            $this->candidateRow($run, $fixture['laboratoryDemand'], dayOfWeek: 2, startsAt: '10:00:00', endsAt: '12:00:00'),
        ]);
        $registrar = $this->registrar();
        $version = app(PublishTimetableVersion::class)->createLocked(
            $run,
            $registrar,
            $rows,
            'SYNTH-TIMETABLE-AUTHORITY',
            'Publish Lecture and Laboratory components together.',
            [],
            [],
        );
        $lectureMeeting = $version->meetings()->where('scheduling_demand_id', $fixture['lectureDemand']->id)->sole();
        $laboratoryMeeting = $version->meetings()->where('scheduling_demand_id', $fixture['laboratoryDemand']->id)->sole();

        $successor = app(RevisePublishedTimetable::class)->execute(
            $version,
            $registrar,
            [
                $lectureMeeting->id => ['day_of_week' => 3, 'starts_at' => '13:00:00', 'ends_at' => '14:30:00'],
            ],
            'SYNTH-REVISION-AUTHORITY',
            'Move the Lecture component to a different day.',
        );

        $revisedLecture = $successor->meetings()->where('scheduling_demand_id', $fixture['lectureDemand']->id)->sole();
        $revisedLaboratory = $successor->meetings()->where('scheduling_demand_id', $fixture['laboratoryDemand']->id)->sole();

        $this->assertSame(3, $revisedLecture->day_of_week);
        $this->assertSame('13:00:00', $revisedLecture->starts_at);
        $this->assertSame('14:30:00', $revisedLecture->ends_at);
        $this->assertSame(2, $revisedLaboratory->day_of_week);
        $this->assertSame('10:00:00', $revisedLaboratory->starts_at);
        $this->assertSame('12:00:00', $revisedLaboratory->ends_at);
    }

    /**
     * @return array{term: Term, specification: CourseSpecification, section: Section, lectureDemand: SchedulingDemand, laboratoryDemand: SchedulingDemand}
     */
    private function lectureAndLaboratoryFixture(): array
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $specification = CourseSpecification::factory()->create(['state' => CourseSpecification::StateActive]);
        $lecture = CourseComponent::factory()->for($specification)->create([
            'component_type' => CourseComponent::TypeLecture,
            'meeting_pattern' => '1x90',
        ]);
        $laboratory = CourseComponent::factory()->for($specification)->create([
            'component_type' => CourseComponent::TypeLaboratory,
            'meeting_pattern' => '1x120',
        ]);
        $entry = CurriculumEntry::factory()->create(['course_specification_id' => $specification->id]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'state' => TermOffering::StatePendingScheduling,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'course_specification_id' => $specification->id,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $lectureDemand = SchedulingDemand::factory()->create([
            'term_offering_id' => $offering->id,
            'course_component_id' => $lecture->id,
            'section_delivery_group_id' => $group->id,
            'required_duration_minutes' => 90,
            'meeting_count' => 1,
        ]);
        $laboratoryDemand = SchedulingDemand::factory()->create([
            'term_offering_id' => $offering->id,
            'course_component_id' => $laboratory->id,
            'section_delivery_group_id' => $group->id,
            'required_duration_minutes' => 120,
            'meeting_count' => 1,
        ]);

        return compact('term', 'specification', 'section', 'lectureDemand', 'laboratoryDemand');
    }

    private function candidateRow(
        ScheduleGenerationRun $run,
        SchedulingDemand $demand,
        int $dayOfWeek,
        string $startsAt,
        string $endsAt,
    ): CandidateScheduleRow {
        return CandidateScheduleRow::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => User::factory()->create()->id,
            'room_id' => Room::factory()->create()->id,
            'day_of_week' => $dayOfWeek,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'time_block_key' => "D{$dayOfWeek}-{$startsAt}",
            'status' => CandidateScheduleRow::StatusOk,
        ]);
    }

    private function registrar(): User
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        return $registrar;
    }
}
