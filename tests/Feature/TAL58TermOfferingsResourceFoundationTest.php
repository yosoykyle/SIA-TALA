<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\Room;
use App\Models\RoomFeature;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TAL58TermOfferingsResourceFoundationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());
    }

    public function test_rooms_own_flat_features_and_calendar_availability_source_records(): void
    {
        $room = Room::factory()->create([
            'room_type' => Room::TypeComputerLaboratory,
            'capacity' => 36,
            'is_active' => true,
        ]);
        $projector = RoomFeature::factory()->for($room)->create(['feature_key' => 'PROJECTOR']);
        $computers = RoomFeature::factory()->for($room)->create(['feature_key' => 'COMPUTER_UNITS']);
        $availabilityBlock = CalendarEvent::factory()->for($room)->create([
            'event_type' => CalendarEvent::TypeBreak,
            'scope_type' => CalendarEvent::ScopeRoom,
            'blocks_scheduling' => true,
        ]);

        $this->assertTrue($room->features->contains($projector));
        $this->assertTrue($room->features->contains($computers));
        $this->assertTrue($room->calendarEvents->contains($availabilityBlock));
        $this->assertTrue($availabilityBlock->room->is($room));
        $this->assertTrue($room->is_active);
        $this->assertSame(Room::TypeComputerLaboratory, $room->room_type);
    }

    public function test_faculty_qualifications_are_active_flat_faculty_to_course_mappings(): void
    {
        $faculty = User::factory()->create();
        $recorder = User::factory()->create();
        $course = Course::factory()->create(['code' => 'IT101']);
        $qualification = FacultyQualification::factory()
            ->for($faculty, 'faculty')
            ->for($course)
            ->for($recorder, 'recorder')
            ->create(['is_active' => true]);

        FacultyQualification::factory()
            ->for($faculty, 'faculty')
            ->inactive()
            ->create(['course_id' => Course::factory()->create(['code' => 'MATH101'])->id]);

        $this->assertTrue($faculty->facultyQualifications->contains($qualification));
        $this->assertTrue($course->facultyQualifications->contains($qualification));
        $this->assertTrue($qualification->faculty->is($faculty));
        $this->assertTrue($qualification->course->is($course));
        $this->assertTrue($qualification->recorder->is($recorder));
        $this->assertSame(1, FacultyQualification::query()->active()->count());
    }

    public function test_faculty_term_load_override_uses_term_specific_snapshot_for_allowed_load(): void
    {
        $faculty = User::factory()->create();
        $term = Term::factory()->create(['default_max_units' => 21.00]);
        $override = FacultyTermLoadOverride::factory()
            ->for($faculty, 'faculty')
            ->for($term)
            ->create([
                'default_max_units_snapshot' => 21.00,
                'approved_overload_units' => 3.00,
            ]);

        $term->update(['default_max_units' => 18.00]);

        $this->assertTrue($faculty->facultyTermLoadOverrides->contains($override));
        $this->assertTrue($term->facultyTermLoadOverrides->contains($override));
        $this->assertSame(24.0, $override->fresh()->allowedLoadUnits());
        $this->assertSame('21.00', $override->fresh()->default_max_units_snapshot);
    }

    public function test_term_offering_inherits_course_facts_through_curriculum_entry_and_specification(): void
    {
        $course = Course::factory()->create(['code' => 'IT202']);
        $specification = CourseSpecification::factory()->for($course)->create([
            'title' => 'Data Structures',
            'credit_units' => 3.00,
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace, TermOffering::ModalityOnline],
        ]);
        CourseComponent::factory()->for($specification)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 3.00,
        ]);
        $entry = CurriculumEntry::factory()->for($specification, 'courseSpecification')->create([
            'year_level' => 'Second Year',
            'term_type' => Term::TypeFirstSemester,
        ]);
        $offering = TermOffering::factory()->for($entry, 'curriculumEntry')->create([
            'category' => TermOffering::CategorySpecial,
            'special_reason' => 'Graduating Student Need',
            'delivery_variant' => TermOffering::ArrangementTutorial,
            'modality' => TermOffering::ModalityFaceToFace,
            'expected_count' => 12,
            'state' => TermOffering::StatePendingScheduling,
        ]);

        $this->assertTrue($entry->termOfferings->contains($offering));
        $this->assertTrue($offering->curriculumEntry->is($entry));
        $this->assertTrue($offering->courseSpecification()?->is($specification));
        $this->assertTrue($offering->course()?->is($course));
        $this->assertSame('3.00', $offering->courseSpecification()->credit_units);
        $this->assertSame(3.0, $offering->courseSpecification()->totalWeeklyContactHours());
        $this->assertTrue($offering->usesSpecialReason());
    }

    public function test_sections_belong_to_term_offerings_and_validate_capacity_without_occupancy_counters(): void
    {
        $offering = TermOffering::factory()->create(['expected_count' => 30]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'BSIT-1A',
            'capacity' => 30,
            'state' => Section::StateOpen,
        ]);

        $this->assertTrue($offering->sections->contains($section));
        $this->assertTrue($section->termOffering->is($offering));
        $this->assertTrue($section->hasCapacityFor(30));
        $this->assertFalse($section->hasCapacityFor(31));
        $this->assertArrayNotHasKey('enrolled_count', $section->getAttributes());
        $this->assertArrayNotHasKey('reserved_count', $section->getAttributes());
    }

    public function test_section_delivery_groups_validate_expected_count_against_section_capacity(): void
    {
        $section = Section::factory()->create(['capacity' => 25]);
        $validGroup = SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'Regular Cohort',
            'expected_count' => 25,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $invalidGroup = SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'Overflow Cohort',
            'expected_count' => 26,
            'state' => SectionDeliveryGroup::StatePlanned,
        ]);

        $this->assertTrue($section->deliveryGroups->contains($validGroup));
        $this->assertFalse($validGroup->exceedsSectionCapacity());
        $this->assertTrue($invalidGroup->exceedsSectionCapacity());
        $this->assertSame(TermOffering::ModalityFaceToFace, $validGroup->modality);
    }
}
