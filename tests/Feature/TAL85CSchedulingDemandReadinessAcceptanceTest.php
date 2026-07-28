<?php

namespace Tests\Feature;

use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\TermSchedulingReadinessService;
use App\Models\CalendarEvent;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\Program;
use App\Models\Room;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL85CSchedulingDemandReadinessAcceptanceTest extends TestCase
{
    use DatabaseTransactions;

    private int $scopeCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotContains(DB::connection()->getDatabaseName(), [
            'tala_db',
            'tala_test_codex',
        ]);

        foreach ([User::StaffRoleRegistrar, User::StaffRoleFaculty] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_generated_demand_is_idempotent_and_captures_current_source_readiness_evidence(): void
    {
        Room::query()
            ->where('room_type', Room::TypeLectureRoom)
            ->update(['is_active' => false]);
        $source = $this->sourceGraph();
        $registrar = $this->staff(User::StaffRoleRegistrar);

        CalendarEvent::factory()->for($source['term'])->create([
            'event_type' => CalendarEvent::TypeBreak,
            'scope_type' => CalendarEvent::ScopeInstitution,
            'process_key' => CalendarEvent::ProcessMasterSchedule,
            'start_at' => null,
            'end_at' => null,
            'day_of_week' => 1,
            'starts_at' => '12:00:00',
            'ends_at' => '13:00:00',
            'blocks_scheduling' => true,
            'state' => CalendarEvent::StateActive,
        ]);

        $first = app(GenerateSchedulingDemand::class)->forTerm($registrar, $source['term']);
        $second = app(GenerateSchedulingDemand::class)->forTerm($registrar, $source['term']);

        $this->assertSame(1, $first['created']);
        $this->assertSame(1, $first['ready']);
        $this->assertSame(0, $first['action_required']);
        $this->assertSame(1, $second['total']);
        $this->assertSame(
            1,
            SchedulingDemand::query()->where('term_offering_id', $source['offering']->id)->count(),
        );

        $demand = SchedulingDemand::query()
            ->where('term_offering_id', $source['offering']->id)
            ->sole();
        $snapshot = $this->arrayAttribute($demand, 'source_snapshot');

        $this->assertSame(SchedulingDemand::ValidationReadyForReview, $demand->validation_state);
        $this->assertSame($source['term']->id, $snapshot['term_id']);
        $this->assertSame($source['offering']->id, $snapshot['term_offering_id']);
        $this->assertSame($source['section']->id, $snapshot['section_id']);
        $this->assertSame($source['group']->id, $snapshot['section_delivery_group_id']);
        $this->assertSame($source['component']->id, $snapshot['course_component_id']);
        $this->assertSame('3.00', $snapshot['weekly_contact_hours']);
        $this->assertSame(30, $snapshot['expected_count']);
        $this->assertSame(30, $snapshot['section_capacity']);
        $this->assertSame(1, $snapshot['eligible_faculty_count']);
        $this->assertSame(1, $snapshot['suitable_room_count']);
        $this->assertSame(1, $snapshot['active_scheduling_window_count']);
        $this->assertSame(1, $snapshot['blocking_calendar_event_count']);
        $this->assertIsArray($snapshot['blocking_calendar_blocks'] ?? null);
        $this->assertSame(1, $snapshot['blocking_calendar_blocks'][0]['day_of_week']);
        $this->assertSame('24.00', $snapshot['faculty_load_options'][0]['max_allowed_units']);
    }

    public function test_generation_blocks_missing_current_sources_and_allows_non_physical_modalities_without_rooms(): void
    {
        Room::query()
            ->where('room_type', Room::TypeLectureRoom)
            ->update(['is_active' => false]);
        $blocked = $this->sourceGraph(
            withCalendar: false,
            withFaculty: false,
            withRooms: false,
            defaultMaxUnits: null,
            sectionCapacity: 25,
            groupExpectedCount: 26,
        );

        app(GenerateSchedulingDemand::class)->forTerm($this->staff(User::StaffRoleRegistrar), $blocked['term']);

        $demand = SchedulingDemand::query()
            ->where('term_offering_id', $blocked['offering']->id)
            ->sole();
        $findingKeys = $demand->readinessFindingKeys();

        $this->assertSame(SchedulingDemand::ValidationActionRequired, $demand->validation_state);
        $this->assertContains('missing_active_scheduling_window', $findingKeys);
        $this->assertContains('delivery_group_expected_count_exceeds_section_capacity', $findingKeys);
        $this->assertContains('missing_active_faculty_qualification', $findingKeys);
        $this->assertContains('missing_suitable_room', $findingKeys);

        SchedulingDemand::query()->delete();

        $online = $this->sourceGraph(
            modality: TermOffering::ModalityOnline,
            withRooms: false,
        );

        app(GenerateSchedulingDemand::class)->forTerm($this->staff(User::StaffRoleRegistrar), $online['term']);

        $onlineDemand = SchedulingDemand::query()->sole();
        $onlineSnapshot = $this->arrayAttribute($onlineDemand, 'source_snapshot');

        $this->assertSame(SchedulingDemand::ValidationReadyForReview, $onlineDemand->validation_state);
        $this->assertSame(0, $onlineSnapshot['suitable_room_count']);
        $this->assertNotContains('missing_suitable_room', $onlineDemand->readinessFindingKeys());
    }

    public function test_fixed_delivery_override_values_are_validated_against_source_records(): void
    {
        $source = $this->sourceGraph();
        $unqualifiedFaculty = $this->staff(User::StaffRoleFaculty);
        $wrongRoom = Room::factory()->create([
            'room_type' => Room::TypeLaboratory,
            'capacity' => 10,
            'is_active' => true,
        ]);

        CalendarEvent::factory()->for($source['term'])->create([
            'event_type' => CalendarEvent::TypeUnavailable,
            'scope_type' => CalendarEvent::ScopeFaculty,
            'faculty_user_id' => $unqualifiedFaculty->id,
            'process_key' => CalendarEvent::ProcessMasterSchedule,
            'start_at' => null,
            'end_at' => null,
            'day_of_week' => 2,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
            'blocks_scheduling' => true,
            'state' => CalendarEvent::StateActive,
        ]);

        $source['group']->update([
            'delivery_override' => [
                'fixed_faculty_user_id' => $unqualifiedFaculty->id,
                'fixed_room_id' => $wrongRoom->id,
                'fixed_day_of_week' => 2,
                'fixed_start_time' => '08:30:00',
            ],
        ]);

        app(GenerateSchedulingDemand::class)->forTerm($this->staff(User::StaffRoleRegistrar), $source['term']);

        $demand = SchedulingDemand::query()
            ->where('term_offering_id', $source['offering']->id)
            ->sole();
        $findingKeys = $demand->readinessFindingKeys();

        $this->assertSame(SchedulingDemand::ValidationActionRequired, $demand->validation_state);
        $this->assertSame($unqualifiedFaculty->id, $demand->fixed_faculty_user_id);
        $this->assertSame($wrongRoom->id, $demand->fixed_room_id);
        $this->assertContains('fixed_faculty_not_qualified', $findingKeys);
        $this->assertContains('fixed_room_not_suitable', $findingKeys);
        $this->assertContains('fixed_time_conflicts_with_calendar_block', $findingKeys);
    }

    public function test_fixed_time_must_belong_to_the_term_grid_and_fit_the_operating_day(): void
    {
        $source = $this->sourceGraph();
        $source['term']->update([
            'scheduling_days' => [1, 2, 3, 4, 5],
            'scheduling_slot_minutes' => 30,
            'scheduling_day_starts_at' => '08:00:00',
            'scheduling_day_ends_at' => '18:00:00',
        ]);
        $source['group']->update([
            'delivery_override' => [
                'fixed_day_of_week' => 6,
                'fixed_start_time' => '17:13:00',
            ],
        ]);

        app(GenerateSchedulingDemand::class)->forTerm($this->staff(User::StaffRoleRegistrar), $source['term']->fresh());

        $demand = SchedulingDemand::query()
            ->where('term_offering_id', $source['offering']->id)
            ->sole();
        $findingKeys = $demand->readinessFindingKeys();

        $this->assertSame(SchedulingDemand::ValidationActionRequired, $demand->validation_state);
        $this->assertContains('fixed_day_outside_scheduling_grid', $findingKeys);
        $this->assertContains('fixed_start_outside_scheduling_grid', $findingKeys);
        $this->assertContains('fixed_time_exceeds_scheduling_day', $findingKeys);
    }

    public function test_term_scheduling_readiness_service_uses_clean_schema_sources(): void
    {
        $source = $this->sourceGraph();

        $readiness = app(TermSchedulingReadinessService::class)->evaluateTerm($source['term']);

        $this->assertTrue($readiness['is_ready']);
        $this->assertSame([], $readiness['missing_term_fields']);
        $this->assertSame([], $readiness['section_issues']);
        $this->assertSame([], $readiness['delivery_group_issues']);
        $this->assertSame([], $readiness['faculty_input_issues']);
        $this->assertSame([], $readiness['room_input_issues']);
        $this->assertStringContainsString('term_offerings', $readiness['room_catalog_mode']);
        $this->assertStringNotContainsString('legacy', $readiness['room_catalog_mode']);

        $source['term']->update(['default_max_units' => null]);
        FacultyTermLoadOverride::query()->delete();
        Room::query()->update(['is_active' => false]);

        $blocked = app(TermSchedulingReadinessService::class)->evaluateTerm($source['term']);

        $this->assertFalse($blocked['is_ready']);
        $this->assertContains('missing_default_faculty_load', $blocked['faculty_input_issues'][0]['missing_inputs']);
        $this->assertContains('missing_suitable_room', $blocked['room_input_issues'][0]['missing_inputs']);
    }

    /**
     * @return array{
     *     term: Term,
     *     course: Course,
     *     specification: CourseSpecification,
     *     component: CourseComponent,
     *     offering: TermOffering,
     *     section: Section,
     *     group: SectionDeliveryGroup
     * }
     */
    private function sourceGraph(
        string $modality = TermOffering::ModalityFaceToFace,
        bool $withCalendar = true,
        bool $withFaculty = true,
        bool $withRooms = true,
        ?float $defaultMaxUnits = 21.00,
        int $sectionCapacity = 30,
        int $groupExpectedCount = 30,
    ): array {
        $this->scopeCounter++;

        $term = Term::factory()->create([
            'label' => 'TAL-85C Term '.$this->scopeCounter,
            'state' => Term::StateActive,
            'scheduling_slot_minutes' => 30,
            'default_max_units' => $defaultMaxUnits,
        ]);

        if ($withCalendar) {
            CalendarEvent::factory()->for($term)->create([
                'event_type' => CalendarEvent::TypeWindow,
                'scope_type' => CalendarEvent::ScopeInstitution,
                'process_key' => CalendarEvent::ProcessScheduling,
                'start_at' => now()->subDay(),
                'end_at' => now()->addWeek(),
                'state' => CalendarEvent::StateActive,
            ]);
        }

        $program = Program::factory()->create(['code' => 'TC'.str_pad((string) $this->scopeCounter, 2, '0', STR_PAD_LEFT)]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['code' => 'TAL85C'.$this->scopeCounter]);
        $specification = CourseSpecification::factory()->for($course)->create([
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace, TermOffering::ModalityOnline, TermOffering::ModalityModular],
            'credit_units' => 3.00,
        ]);
        $component = CourseComponent::factory()->for($specification)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 3.00,
            'room_type_default' => Room::TypeLectureRoom,
            'sequence' => 1,
        ]);
        $entry = CurriculumEntry::factory()->for($curriculum)->for($specification, 'courseSpecification')->create([
            'term_type' => $term->type,
            'term_label' => $term->label,
            'sequence' => 1,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'modality' => $modality,
            'expected_count' => $groupExpectedCount,
            'state' => TermOffering::StatePendingScheduling,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'TAL85C-'.$this->scopeCounter,
            'capacity' => $sectionCapacity,
            'state' => Section::StatePlanned,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'Regular Cohort '.$this->scopeCounter,
            'expected_count' => $groupExpectedCount,
            'modality' => $modality,
            'state' => SectionDeliveryGroup::StateReady,
        ]);

        if ($withFaculty) {
            $faculty = $this->staff(User::StaffRoleFaculty);

            FacultyQualification::factory()
                ->for($faculty, 'faculty')
                ->for($course)
                ->create(['is_active' => true]);

            FacultyTermLoadOverride::factory()
                ->for($faculty, 'faculty')
                ->for($term)
                ->create([
                    'default_max_units_snapshot' => $defaultMaxUnits ?? 21.00,
                    'approved_overload_units' => 3.00,
                    'is_active' => true,
                ]);
        }

        if ($withRooms) {
            Room::factory()->create([
                'room_type' => Room::TypeLectureRoom,
                'capacity' => max(40, $groupExpectedCount),
                'is_active' => true,
            ]);
        }

        return [
            'term' => $term,
            'course' => $course,
            'specification' => $specification,
            'component' => $component,
            'offering' => $offering,
            'section' => $section,
            'group' => $group,
        ];
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayAttribute(Model $model, string $key): array
    {
        $value = $model->getAttribute($key);

        $this->assertIsArray($value);

        return $value;
    }
}
