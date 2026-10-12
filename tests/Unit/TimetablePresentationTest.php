<?php

namespace Tests\Unit;

use App\Models\CandidateScheduleRow;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Support\TimetablePresentation;
use PHPUnit\Framework\TestCase;

class TimetablePresentationTest extends TestCase
{
    public function test_shared_classes_use_each_captured_cohorts_own_curriculum_years_and_missing_associations_stay_unknown(): void
    {
        $revision = new CourseSpecification(['course_id' => 100]);
        $hrEntry = new CurriculumEntry(['year_level' => 'First Year', 'term_type' => Term::TypeFirstSemester]);
        $hrEntry->setRelation('courseSpecification', $revision);
        $mathEntry = new CurriculumEntry(['year_level' => 'Third Year', 'term_type' => Term::TypeFirstSemester]);
        $mathEntry->setRelation('courseSpecification', $revision);
        $otherTermEntry = new CurriculumEntry(['year_level' => 'Second Year', 'term_type' => Term::TypeSecondSemester]);
        $otherTermEntry->setRelation('courseSpecification', $revision);
        $otherCourseEntry = new CurriculumEntry(['year_level' => 'Fourth Year', 'term_type' => Term::TypeFirstSemester]);
        $otherCourseEntry->setRelation('courseSpecification', new CourseSpecification(['course_id' => 101]));
        $hrCurriculum = new CurriculumVersion;
        $hrCurriculum->setRelation('entries', collect([$hrEntry]));
        $hrCurriculum->setRelation('program', new Program(['name' => 'HR']));
        $hrEntry->setRelation('curriculumVersion', $hrCurriculum);
        $mathCurriculum = new CurriculumVersion;
        $mathCurriculum->setRelation('entries', collect([$mathEntry, $otherTermEntry, $otherCourseEntry]));
        $hrCohort = new TermCohort(['reference' => 'HR-1A']);
        $hrCohort->setAttribute('id', 11)->setRelation('program', new Program(['name' => 'HR']))->setRelation('curriculumVersion', $hrCurriculum);
        $mathCohort = new TermCohort(['reference' => 'Math-3A']);
        $mathCohort->setAttribute('id', 22)->setRelation('program', new Program(['name' => 'Math']))->setRelation('curriculumVersion', $mathCurriculum);
        $class = new Section(['code' => 'SHARED-CLASS']);
        $class->setRelation('cohorts', collect([$hrCohort, $mathCohort]));
        $group = new SectionDeliveryGroup;
        $group->setRelation('section', $class);
        $offering = new TermOffering;
        $offering->setRelation('curriculumEntry', $hrEntry);
        $demand = new SchedulingDemand;
        $demand->setRelation('sectionDeliveryGroup', $group)->setRelation('termOffering', $offering);
        $row = new CandidateScheduleRow(['scheduling_demand_id' => 91, 'day_of_week' => 1, 'starts_at' => '08:00', 'ends_at' => '09:00']);
        $row->setRelation('schedulingDemand', $demand);
        $run = new ScheduleGenerationRun(['input_snapshot' => [
            'term' => ['type' => Term::TypeFirstSemester],
            'scheduling_demands' => [['scheduling_demand_id' => 91, 'course_id' => 100, 'cohort_or_student_group_ids' => [11, 22, 33]]],
        ]]);

        $sections = TimetablePresentation::forCandidate($run, collect([$row]))['meetings'][0]['sections'];

        $this->assertSame(['11', '22', '33'], array_column($sections, 'id'));
        $this->assertSame(['HR', 'Math', 'Program not associated'], array_column($sections, 'program'));
        $this->assertSame([['First Year'], ['Third Year'], []], array_column($sections, 'years'));
        $this->assertSame(['First Year', 'Third Year', 'Year not associated'], array_column($sections, 'year'));
        $this->assertSame('Captured student group #33', $sections[2]['label']);

        $mathCurriculum->setRelation('entries', collect([$hrEntry, $mathEntry]));
        $sections = TimetablePresentation::forCandidate($run, collect([$row]))['meetings'][0]['sections'];
        $this->assertSame(['First Year', 'Third Year'], $sections[1]['years']);
        $this->assertSame('Multiple curriculum years', $sections[1]['year']);
    }

    public function test_student_group_identities_come_from_the_captured_request_even_after_current_links_change(): void
    {
        $class = new Section(['code' => 'GE-CLASS']);
        $class->setRelation('cohorts', collect([new TermCohort(['reference' => 'NEW-GROUP'])]));
        $group = new SectionDeliveryGroup;
        $group->setRelation('section', $class);
        $demand = new SchedulingDemand;
        $demand->setRelation('sectionDeliveryGroup', $group);
        $row = new CandidateScheduleRow(['scheduling_demand_id' => 91, 'day_of_week' => 1, 'starts_at' => '08:00', 'ends_at' => '09:00']);
        $row->setRelation('schedulingDemand', $demand);
        $run = new ScheduleGenerationRun(['input_snapshot' => ['scheduling_demands' => [
            ['scheduling_demand_id' => 91, 'cohort_or_student_group_ids' => [11, 22]],
        ]]]);

        $presentation = TimetablePresentation::forCandidate($run, collect([$row]));

        $this->assertSame(['11', '22'], array_column($presentation['meetings'][0]['sections'], 'id'));
        $this->assertSame(['Captured student group #11', 'Captured student group #22'], array_column($presentation['meetings'][0]['sections'], 'label'));
        $this->assertCount(1, $presentation['meetings']);
    }

    public function test_half_hour_meeting_uses_its_true_start_and_duration_once(): void
    {
        $run = new ScheduleGenerationRun(['input_snapshot' => ['term' => [
            'scheduling_days' => [1, 7], 'scheduling_day_starts_at' => '08:00', 'scheduling_day_ends_at' => '12:00',
        ]]]);
        $row = new CandidateScheduleRow(['day_of_week' => 7, 'starts_at' => '08:30:00', 'ends_at' => '10:00:00']);

        $presentation = TimetablePresentation::forCandidate($run, collect([$row]));

        $this->assertSame([1, 7], array_column($presentation['days'], 'number'));
        $this->assertSame(8, $presentation['slots']);
        $this->assertCount(1, $presentation['meetings']);
        $this->assertSame(12.5, $presentation['meetings'][0]['top']);
        $this->assertSame(37.5, $presentation['meetings'][0]['height']);
        $this->assertSame('8:30 AM–10:00 AM', $presentation['meetings'][0]['time']);
        $this->assertSame('1h 30m', $presentation['meetings'][0]['duration']);
        $this->assertFalse($presentation['meetings'][0]['offGrid']);
    }

    public function test_overlapping_meetings_get_distinct_lanes_and_touching_meetings_reuse_a_lane(): void
    {
        $run = new ScheduleGenerationRun;
        $rows = collect([
            new CandidateScheduleRow(['day_of_week' => 1, 'starts_at' => '08:00', 'ends_at' => '10:00']),
            new CandidateScheduleRow(['day_of_week' => 1, 'starts_at' => '08:30', 'ends_at' => '09:00']),
            new CandidateScheduleRow(['day_of_week' => 1, 'starts_at' => '09:00', 'ends_at' => '09:30']),
            new CandidateScheduleRow(['day_of_week' => 1, 'starts_at' => '10:00', 'ends_at' => '11:00']),
        ]);

        $presentation = TimetablePresentation::forCandidate($run, $rows);

        $this->assertSame([0, 1, 1, 0], array_column($presentation['meetings'], 'lane'));
        $this->assertSame([2, 2, 2, 1], array_column($presentation['meetings'], 'lanes'));
        $this->assertSame([1], array_column($presentation['days'], 'number'));
        $this->assertFalse($presentation['snapshotAvailable']);
    }

    public function test_off_grid_meetings_extend_the_axis_and_invalid_records_remain_in_the_list(): void
    {
        $run = new ScheduleGenerationRun(['input_snapshot' => ['term' => [
            'scheduling_days' => [1], 'scheduling_day_starts_at' => '08:00', 'scheduling_day_ends_at' => '10:00',
        ]]]);
        $rows = collect([
            new CandidateScheduleRow(['day_of_week' => 7, 'starts_at' => '07:30', 'ends_at' => '10:30']),
            new CandidateScheduleRow(['day_of_week' => 1, 'starts_at' => '09:00', 'ends_at' => '08:00']),
            new CandidateScheduleRow(['day_of_week' => 9, 'starts_at' => 'bad', 'ends_at' => '10:30']),
        ]);

        $presentation = TimetablePresentation::forCandidate($run, $rows);

        $this->assertSame([1, 7], array_column($presentation['days'], 'number'));
        $this->assertSame('7:30 AM', $presentation['ticks'][0]['label']);
        $this->assertSame('10:30 AM', $presentation['ticks'][6]['label']);
        $this->assertCount(3, $presentation['meetings']);
        $this->assertSame([false, true, false], array_column($presentation['meetings'], 'plottable'));
        $this->assertTrue($presentation['meetings'][1]['offGrid']);
    }

    public function test_published_geometry_uses_its_source_run_and_detects_a_teaching_grid_gap(): void
    {
        $run = new ScheduleGenerationRun(['input_snapshot' => ['term' => [
            'scheduling_days' => [7], 'teaching_grid_rows' => [
                ['day_of_week' => 7, 'starts_at' => '08:00', 'ends_at' => '09:00'],
                ['day_of_week' => 7, 'starts_at' => '09:30', 'ends_at' => '11:00'],
            ],
        ]]]);
        $version = new PublishedTimetableVersion(['version' => 2, 'state' => 'Superseded']);
        $version->setRelation('scheduleRun', $run);
        $meetings = collect([
            new PublishedTimetableMeeting(['day_of_week' => 7, 'starts_at' => '08:30', 'ends_at' => '10:00']),
            new PublishedTimetableMeeting(['day_of_week' => 7, 'starts_at' => '10:00', 'ends_at' => '11:00']),
        ]);

        $presentation = TimetablePresentation::forPublished($version, $meetings);

        $this->assertSame('Published version 2 · Superseded', $presentation['source']);
        $this->assertSame([7], array_column($presentation['days'], 'number'));
        $this->assertSame([true, false], array_column($presentation['meetings'], 'offGrid'));
        $this->assertSame('11:00 AM', $presentation['ticks'][6]['label']);
    }

    public function test_loaded_course_relations_and_unassigned_online_location_are_projected_without_queries(): void
    {
        $course = new Course(['code' => 'IT101']);
        $specification = new CourseSpecification(['title' => 'Introduction to Computing']);
        $specification->setRelation('course', $course);
        $component = new CourseComponent(['component_type' => 'LABORATORY']);
        $component->setRelation('courseSpecification', $specification);
        $demand = new SchedulingDemand(['modality' => 'ONLINE']);
        $demand->setRelation('courseComponent', $component);
        $row = new CandidateScheduleRow(['day_of_week' => 1, 'starts_at' => '08:00', 'ends_at' => '09:00']);
        $row->setRelation('schedulingDemand', $demand);

        $presentation = TimetablePresentation::forCandidate(new ScheduleGenerationRun, collect([$row]));

        $this->assertSame('IT101', $presentation['meetings'][0]['courseCode']);
        $this->assertSame('Introduction to Computing', $presentation['meetings'][0]['courseTitle']);
        $this->assertSame('Laboratory', $presentation['meetings'][0]['component']);
        $this->assertSame('Faculty unassigned', $presentation['meetings'][0]['faculty']);
        $this->assertSame(['Online'], $presentation['filters']['room']);
    }

    public function test_empty_unrecorded_calendar_does_not_invent_days_or_time_rows(): void
    {
        $presentation = TimetablePresentation::forCandidate(new ScheduleGenerationRun, collect());

        $this->assertSame([], $presentation['days']);
        $this->assertSame([], $presentation['ticks']);
        $this->assertSame([], $presentation['meetings']);
        $this->assertSame(0, $presentation['slots']);
    }

    public function test_frozen_institution_breaks_are_visible_and_room_blocks_do_not_become_global_breaks(): void
    {
        $run = new ScheduleGenerationRun(['input_snapshot' => [
            'term' => ['scheduling_days' => [1], 'scheduling_day_starts_at' => '08:00', 'scheduling_day_ends_at' => '17:00'],
            'calendar_blocks' => [
                ['day_of_week' => 1, 'starts_at' => '12:00', 'ends_at' => '13:00', 'scope_type' => 'Institution', 'event_type' => 'TeachingGridBreak'],
                ['day_of_week' => 1, 'starts_at' => '08:00', 'ends_at' => '09:00', 'scope_type' => 'Room', 'room_id' => 2],
            ],
        ]]);
        $rows = collect([
            new CandidateScheduleRow(['day_of_week' => 1, 'starts_at' => '08:00', 'ends_at' => '09:00']),
            new CandidateScheduleRow(['day_of_week' => 1, 'starts_at' => '12:00', 'ends_at' => '13:00']),
        ]);

        $presentation = TimetablePresentation::forCandidate($run, $rows);

        $this->assertCount(1, $presentation['blockedPeriods']);
        $this->assertSame('Approved break', $presentation['blockedPeriods'][0]['label']);
        $this->assertSame('12:00 PM–1:00 PM', $presentation['blockedPeriods'][0]['time']);
        $this->assertSame([false, true], array_column($presentation['meetings'], 'offGrid'));
    }
}
