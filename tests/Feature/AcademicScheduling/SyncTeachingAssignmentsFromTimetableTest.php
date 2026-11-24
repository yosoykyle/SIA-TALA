<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\Grades\ManageTeachingAssignment;
use App\Actions\Scheduling\PublishTimetableVersion;
use App\Actions\Scheduling\RevisePublishedTimetable;
use App\Filament\Resources\Sections\Pages\ViewSection;
use App\Models\CandidateScheduleRow;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\GradeRoster;
use App\Models\OperationalEvent;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SyncTeachingAssignmentsFromTimetableTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(User::StaffRoleRegistrar)->givePermissionTo(Permission::findOrCreate('manage-schedules'));
        Role::findOrCreate(User::StaffRoleFaculty);
    }

    public function test_publishing_designates_the_faculty_with_most_meetings_and_adds_the_others_as_co_faculty(): void
    {
        $registrar = $this->registrar();
        [$lead, $partner] = [$this->faculty(), $this->faculty()];
        $class = $this->classWithMeetings(3);

        $version = $this->publish($class, $registrar, [$partner, $lead, $lead]);

        $this->assertActiveAssignments($class['section'], [
            [$lead->id, ClassOfferingTeachingAssignment::RoleDesignated],
            [$partner->id, ClassOfferingTeachingAssignment::RoleCoFaculty],
        ]);
        $this->assertSame('Published timetable v'.$version->version.' (#'.$version->id.')',
            ClassOfferingTeachingAssignment::query()->where('faculty_user_id', $lead->id)->sole()->authority_reference);
        $roster = GradeRoster::query()->where('section_id', $class['section']->id)->sole();
        $this->assertSame($lead->id, (int) $roster->faculty_user_id);
        $this->assertSame(0, OperationalEvent::query()->where('event_type', OperationalEvent::TypeGradeSubmissionRequiredEmail)->count());
    }

    public function test_revising_the_faculty_moves_the_class_and_its_roster_to_the_new_faculty(): void
    {
        $registrar = $this->registrar();
        [$original, $replacement] = [$this->faculty(), $this->faculty()];
        $class = $this->classWithMeetings(2);
        $version = $this->publish($class, $registrar, [$original, $original]);

        $this->revise($version, $registrar, $replacement);

        $this->assertActiveAssignments($class['section'], [[$replacement->id, ClassOfferingTeachingAssignment::RoleDesignated]]);
        $this->assertSame(ClassOfferingTeachingAssignment::StateReplaced,
            ClassOfferingTeachingAssignment::query()->where('faculty_user_id', $original->id)->sole()->state);
        $this->assertSame($replacement->id, (int) GradeRoster::query()->where('section_id', $class['section']->id)->sole()->faculty_user_id);
    }

    public function test_revising_the_faculty_keeps_an_assignment_the_registrar_recorded_by_hand(): void
    {
        $registrar = $this->registrar();
        [$scheduled, $substitute, $replacement] = [$this->faculty(), $this->faculty(), $this->faculty()];
        $class = $this->classWithMeetings(2);
        $version = $this->publish($class, $registrar, [$scheduled, $scheduled]);
        app(ManageTeachingAssignment::class)->designate($class['section'], $substitute, $registrar, 'MEMO-SUBSTITUTE-1');

        $this->revise($version, $registrar, $replacement);

        $this->assertActiveAssignments($class['section'], [[$substitute->id, ClassOfferingTeachingAssignment::RoleDesignated]]);
    }

    public function test_backfill_previews_by_default_and_applies_once_without_touching_manual_assignments(): void
    {
        $registrar = $this->registrar();
        [$scheduled, $manual] = [$this->faculty(), $this->faculty()];
        $unassigned = $this->classWithMeetings(1);
        $assignedByHand = $this->classWithMeetings(1, $unassigned['term']);
        $version = $this->publishRows($registrar, [
            ...$this->rows($unassigned, [$scheduled]),
            ...$this->rows($assignedByHand, [$scheduled]),
        ], $unassigned['term']);
        GradeRoster::query()->whereIn('section_id', [$unassigned['section']->id, $assignedByHand['section']->id])->delete();
        ClassOfferingTeachingAssignment::query()->whereIn('section_id', [$unassigned['section']->id, $assignedByHand['section']->id])->delete();
        app(ManageTeachingAssignment::class)->designate($assignedByHand['section'], $manual, $registrar, 'MEMO-LOAD-7');

        $this->artisan('teaching-assignments:backfill')->assertSuccessful();
        $this->assertSame(0, $unassigned['section']->teachingAssignments()->count());

        $this->artisan('teaching-assignments:backfill', ['--apply' => true])->assertSuccessful();
        $this->artisan('teaching-assignments:backfill', ['--apply' => true])->assertSuccessful();

        $this->assertActiveAssignments($unassigned['section'], [[$scheduled->id, ClassOfferingTeachingAssignment::RoleDesignated]]);
        $this->assertSame(1, $unassigned['section']->teachingAssignments()->count());
        $this->assertSame(1, GradeRoster::query()->where('section_id', $unassigned['section']->id)->count());
        $this->assertActiveAssignments($assignedByHand['section'], [[$manual->id, ClassOfferingTeachingAssignment::RoleDesignated]]);
        $this->assertSame(PublishedTimetableVersion::StatePublished, $version->fresh()->state);
    }

    public function test_registrar_assigns_teaching_faculty_from_the_class_page(): void
    {
        $registrar = $this->registrar();
        $faculty = $this->faculty();
        $class = $this->classWithMeetings(1);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ViewSection::class, ['record' => $class['section']->id])
            ->callAction('assignTeachingFaculty', [
                'faculty_user_id' => $faculty->id,
                'role' => ClassOfferingTeachingAssignment::RoleDesignated,
                'authority_reference' => 'MEMO-LOAD-12',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Teaching assignment recorded');

        $this->assertActiveAssignments($class['section'], [[$faculty->id, ClassOfferingTeachingAssignment::RoleDesignated]]);
        $this->assertSame($faculty->id, (int) GradeRoster::query()->where('section_id', $class['section']->id)->sole()->faculty_user_id);
    }

    /** @param list<array{0: int, 1: string}> $expected */
    private function assertActiveAssignments(Section $section, array $expected): void
    {
        $this->assertEqualsCanonicalizing($expected, ClassOfferingTeachingAssignment::query()
            ->where('section_id', $section->id)
            ->where('state', ClassOfferingTeachingAssignment::StateActive)
            ->get()
            ->map(fn (ClassOfferingTeachingAssignment $assignment): array => [(int) $assignment->faculty_user_id, $assignment->role])
            ->all());
    }

    /**
     * @param  array{term: Term, section: Section, demand: SchedulingDemand}  $class
     * @param  list<User>  $facultyPerMeeting
     */
    private function publish(array $class, User $registrar, array $facultyPerMeeting): PublishedTimetableVersion
    {
        return $this->publishRows($registrar, $this->rows($class, $facultyPerMeeting), $class['term']);
    }

    /** @param list<array<string, mixed>> $rows */
    private function publishRows(User $registrar, array $rows, Term $term): PublishedTimetableVersion
    {
        $run = ScheduleGenerationRun::factory()->create(['term_id' => $term->id, 'contract_version' => 'legacy-pre-v2']);
        $candidateRows = new Collection(array_map(fn (array $row): CandidateScheduleRow => CandidateScheduleRow::query()->create([
            ...$row,
            'schedule_run_id' => $run->id,
            'room_id' => Room::factory()->create()->id,
            'status' => CandidateScheduleRow::StatusOk,
        ]), $rows));

        return app(PublishTimetableVersion::class)->createLocked($run, $registrar, $candidateRows, 'SYNTH-TIMETABLE', 'Publish the term timetable.', [], []);
    }

    /**
     * @param  array{term: Term, section: Section, demand: SchedulingDemand}  $class
     * @param  list<User>  $facultyPerMeeting
     * @return list<array<string, mixed>>
     */
    private function rows(array $class, array $facultyPerMeeting): array
    {
        $day = $class['section']->id % 6 + 1;

        return array_map(fn (User $faculty, int $index): array => [
            'scheduling_demand_id' => $class['demand']->id,
            'meeting_sequence' => $index + 1,
            'faculty_user_id' => $faculty->id,
            'day_of_week' => $day,
            'starts_at' => sprintf('%02d:00:00', 7 + $index * 2),
            'ends_at' => sprintf('%02d:30:00', 8 + $index * 2),
            'time_block_key' => "D{$day}-{$index}",
        ], $facultyPerMeeting, array_keys($facultyPerMeeting));
    }

    private function revise(PublishedTimetableVersion $version, User $registrar, User $replacement): PublishedTimetableVersion
    {
        $changes = $version->meetings()->get()
            ->mapWithKeys(fn ($meeting): array => [$meeting->id => ['faculty_user_id' => $replacement->id]])
            ->all();

        return app(RevisePublishedTimetable::class)->execute($version, $registrar, $changes, 'SYNTH-REVISION', 'Faculty replaced for the term.');
    }

    /** @return array{term: Term, section: Section, demand: SchedulingDemand} */
    private function classWithMeetings(int $meetings, ?Term $term = null): array
    {
        $term ??= Term::factory()->create(['state' => Term::StateActive]);
        $specification = CourseSpecification::factory()->create(['state' => CourseSpecification::StateActive]);
        $component = CourseComponent::factory()->for($specification)->create([
            'component_type' => CourseComponent::TypeLecture,
            'meeting_pattern' => $meetings.'x90',
        ]);
        $offering = TermOffering::factory()->for($term)
            ->for(CurriculumEntry::factory()->create(['course_specification_id' => $specification->id]), 'curriculumEntry')
            ->create(['state' => TermOffering::StatePendingScheduling]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'course_specification_id' => $specification->id,
            'state' => Section::StateOpen,
        ]);
        $demand = SchedulingDemand::factory()->create([
            'term_offering_id' => $offering->id,
            'course_component_id' => $component->id,
            'section_delivery_group_id' => SectionDeliveryGroup::factory()->for($section)->create(['state' => SectionDeliveryGroup::StateReady])->id,
            'required_duration_minutes' => 90,
            'meeting_count' => $meetings,
        ]);

        return compact('term', 'section', 'demand');
    }

    private function registrar(): User
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        return $registrar;
    }

    private function faculty(): User
    {
        $faculty = User::factory()->create(['status' => User::StatusActive]);
        $faculty->assignRole(User::StaffRoleFaculty);

        return $faculty;
    }
}
