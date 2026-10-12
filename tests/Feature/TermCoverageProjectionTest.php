<?php

namespace Tests\Feature;

use App\Actions\Scheduling\BuildOfficialScheduleOutput;
use App\Actions\Scheduling\TermCoverageProjection;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCohort;
use App\Models\TermOffering;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TermCoverageProjectionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
    }

    public function test_active_curriculum_years_are_advisory_and_match_the_term_type(): void
    {
        $term = Term::factory()->create(['type' => Term::TypeFirstSemester]);
        $curriculum = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateActive]);
        foreach (['First Year', 'Second Year', 'Third Year'] as $year) {
            CurriculumEntry::factory()->for($curriculum)->create(['year_level' => $year]);
        }
        CurriculumEntry::factory()->for($curriculum)->create(['year_level' => 'Fourth Year', 'term_type' => Term::TypeSecondSemester]);
        $draft = CurriculumVersion::factory()->create();
        CurriculumEntry::factory()->for($draft)->create();

        $rows = collect(app(TermCoverageProjection::class)->forTerm($term)['rows'])->where('curriculum_version_id', $curriculum->id);
        $this->assertEqualsCanonicalizing(['First Year', 'Second Year', 'Third Year'], $rows->pluck('year_level')->all());
        $this->assertSame(['Not set up'], $rows->pluck('status')->unique()->values()->all());
        $this->assertStringContainsString('advisory', $rows->first()['reason']);
    }

    public function test_whole_term_counts_distinguish_classes_sections_and_weekly_meetings_and_preserve_cross_year_links(): void
    {
        $term = Term::factory()->create();
        $curriculum = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateActive]);
        $cohort = TermCohort::factory()->for($term)->for($curriculum)->create(['program_id' => $curriculum->program_id, 'reference' => 'HR-3A']);
        foreach (['First Year', 'Second Year'] as $year) {
            $entry = CurriculumEntry::factory()->for($curriculum)->create(['year_level' => $year, 'term_type' => $term->type]);
            $class = $this->classFor($term, $entry);
            $class->cohorts()->attach($cohort, ['expected_count' => 30]);
            $group = SectionDeliveryGroup::factory()->for($class, 'section')->create();
            SchedulingDemand::factory()->for($group, 'sectionDeliveryGroup')->create(['term_offering_id' => $class->term_offering_id, 'meeting_count' => 3]);
        }
        $this->classFor(Term::factory()->create(), CurriculumEntry::factory()->create());

        $projection = app(TermCoverageProjection::class)->forTerm($term);
        $this->assertSame(2, $projection['summary']['class_count']);
        $this->assertSame(1, $projection['summary']['section_count']);
        $this->assertSame(6, $projection['summary']['meeting_count']);
        $rows = collect($projection['rows'])->where('curriculum_version_id', $curriculum->id);
        foreach ($rows as $row) {
            $this->assertTrue($row['has_cross_year_sections']);
            $this->assertSame(['First Year', 'Second Year'], $row['sections'][0]['observed_year_levels']);
            $this->assertSame('Prepared', $row['status']);
            $this->assertStringContainsString('Generation still checks', $row['reason']);
        }
    }

    public function test_inactive_classes_and_groups_are_excluded_and_unlinked_sections_have_no_guessed_year(): void
    {
        $term = Term::factory()->create();
        $entry = CurriculumEntry::factory()->create(['term_type' => $term->type]);
        $class = $this->classFor($term, $entry);
        $closed = SectionDeliveryGroup::factory()->for($class, 'section')->closed()->create();
        SchedulingDemand::factory()->for($closed, 'sectionDeliveryGroup')->create(['term_offering_id' => $class->term_offering_id, 'meeting_count' => 4]);
        Section::factory()->for($class->termOffering, 'termOffering')->create(['state' => Section::StateCancelled]);
        Section::factory()->for($class->termOffering, 'termOffering')->create(['state' => Section::StateClosed]);
        $cancelled = $this->classFor($term, CurriculumEntry::factory()->create(['term_type' => $term->type]));
        $cancelled->termOffering->update(['state' => TermOffering::StateCancelled]);
        $cohort = TermCohort::factory()->for($term)->create(['reference' => 'SECTION-YEAR3']);

        $projection = app(TermCoverageProjection::class)->forTerm($term);
        $this->assertSame(1, $projection['summary']['class_count']);
        $this->assertSame(0, $projection['summary']['meeting_count']);
        $unlinked = collect($projection['additional'])->firstWhere('id', 'cohort-'.$cohort->id);
        $this->assertNull($unlinked['year_level']);
        $this->assertSame([], $unlinked['sections'][0]['observed_year_levels']);
        $this->assertSame('Needs association', $unlinked['status']);
        $this->assertSame('Needs preparation', collect($projection['additional'])->firstWhere('class_count', 1)['status']);
    }

    public function test_recorded_findings_prevent_prepared_status_and_remain_visible(): void
    {
        $term = Term::factory()->create();
        $entry = CurriculumEntry::factory()->create(['term_type' => $term->type]);
        $class = $this->classFor($term, $entry);
        $cohort = TermCohort::factory()->for($term)->create();
        $class->cohorts()->attach($cohort, ['expected_count' => 30]);
        $group = SectionDeliveryGroup::factory()->for($class, 'section')->create();
        SchedulingDemand::factory()->for($group, 'sectionDeliveryGroup')->create([
            'term_offering_id' => $class->term_offering_id,
            'readiness_findings' => [['key' => 'faculty_missing', 'message' => 'Needs an eligible teacher']],
            'validation_state' => SchedulingDemand::ValidationActionRequired,
        ]);
        $row = collect(app(TermCoverageProjection::class)->forTerm($term)['additional'])->firstWhere('class_count', 1);
        $this->assertSame('Needs preparation', $row['status']);
        $this->assertSame('faculty_missing', $row['findings'][0]['key']);
    }

    public function test_additional_classes_and_special_offerings_are_separate_from_expected_curriculum_rows(): void
    {
        $term = Term::factory()->create();
        $curriculum = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateActive]);
        $entry = CurriculumEntry::factory()->for($curriculum)->create(['term_type' => $term->type]);
        $class = $this->classFor($term, $entry);
        $class->update(['source' => Section::SourceAdditional]);
        $specialEntry = CurriculumEntry::factory()->for($curriculum)->create(['term_type' => $term->type, 'year_level' => 'Second Year']);
        $special = $this->classFor($term, $specialEntry);
        $special->termOffering->update(['category' => TermOffering::CategorySpecial, 'special_reason' => 'Approved additional demand']);

        $projection = app(TermCoverageProjection::class)->forTerm($term);
        $this->assertSame(2, $projection['summary']['class_count']);
        $this->assertSame(0, collect($projection['rows'])->where('curriculum_version_id', $curriculum->id)->sum('class_count'));
        $this->assertSame(2, collect($projection['additional'])->sum('class_count'));
        $this->assertSame(Section::SourceAdditional, $class->fresh()->source);
    }

    public function test_official_meeting_output_uses_readable_component_labels_without_changing_enum_values(): void
    {
        $method = new \ReflectionMethod(BuildOfficialScheduleOutput::class, 'meetingRow');
        foreach (CourseComponent::typeOptions() as $type => $label) {
            $component = new CourseComponent(['component_type' => $type]);
            $component->setRelation('courseSpecification', null);
            $demand = new SchedulingDemand;
            $demand->setRelation('courseComponent', $component)->setRelation('termOffering', null)->setRelation('sectionDeliveryGroup', null);
            $meeting = new SectionMeeting(['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '09:00:00']);
            $meeting->setRelation('schedulingDemand', $demand)->setRelation('room', null)->setRelation('faculty', null);
            $this->assertSame($label, $method->invoke(app(BuildOfficialScheduleOutput::class), $meeting)['component']);
            $this->assertSame($type, $component->component_type);
        }
    }

    public function test_shared_class_serves_each_linked_program_curriculum_row_but_counts_once_for_the_term(): void
    {
        $term = Term::factory()->create();
        $revision = CourseSpecification::factory()->create();
        $first = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateActive]);
        $second = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateActive]);
        $sourceEntry = CurriculumEntry::factory()->for($first)->for($revision, 'courseSpecification')->create(['year_level' => 'Second Year', 'term_type' => $term->type]);
        CurriculumEntry::factory()->for($second)->for($revision, 'courseSpecification')->create(['year_level' => 'Second Year', 'term_type' => $term->type]);
        $class = $this->classFor($term, $sourceEntry);
        foreach ([$first, $second] as $curriculum) {
            $cohort = TermCohort::factory()->for($term)->for($curriculum)->create(['program_id' => $curriculum->program_id]);
            $class->cohorts()->attach($cohort, ['expected_count' => 15]);
        }
        $group = SectionDeliveryGroup::factory()->for($class, 'section')->create();
        SchedulingDemand::factory()->for($group, 'sectionDeliveryGroup')->create(['term_offering_id' => $class->term_offering_id, 'meeting_count' => 2]);
        $projection = app(TermCoverageProjection::class)->forTerm($term);
        foreach ([$first, $second] as $curriculum) {
            $row = collect($projection['rows'])->firstWhere('curriculum_version_id', $curriculum->id);
            $this->assertSame(1, $row['class_count']);
            $this->assertSame(1, $row['section_count']);
            $this->assertSame($curriculum->program_id, $row['sections'][0]['program_id']);
            $this->assertSame(2, $row['meeting_count']);
        }
        $this->assertSame(1, $projection['summary']['class_count']);
        $this->assertSame(2, $projection['summary']['section_count']);
        $this->assertSame(2, $projection['summary']['meeting_count']);
        $this->assertSame(2, $projection['summary']['program_count']);
    }

    public function test_unresolved_shared_course_does_not_invent_a_year_and_marks_association_evidence(): void
    {
        $term = Term::factory()->create();
        $curriculum = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateActive]);
        CurriculumEntry::factory()->for($curriculum)->create(['year_level' => 'Second Year', 'term_type' => $term->type]);
        $cohort = TermCohort::factory()->for($term)->for($curriculum)->create(['program_id' => $curriculum->program_id, 'reference' => 'DO-NOT-GUESS-3A']);
        $class = $this->classFor($term, CurriculumEntry::factory()->create(['term_type' => $term->type]));
        $class->cohorts()->attach($cohort, ['expected_count' => 30]);
        $projection = app(TermCoverageProjection::class)->forTerm($term);
        $expected = collect($projection['rows'])->firstWhere('curriculum_version_id', $curriculum->id);
        $this->assertSame('Association unresolved', $expected['status']);
        $unknown = collect($projection['additional'])->firstWhere('id', 'cohort-'.$cohort->id);
        $this->assertNull($unknown['year_level']);
        $this->assertSame([], $unknown['sections'][0]['observed_year_levels']);
        $this->assertSame(1, $unknown['class_count']);
        $this->assertSame(1, $projection['summary']['class_count']);
    }

    private function classFor(Term $term, CurriculumEntry $entry): Section
    {
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create();

        return Section::factory()->for($offering, 'termOffering')->create(['confirmed_at' => now()]);
    }
}
