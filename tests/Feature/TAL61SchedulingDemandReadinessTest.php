<?php

namespace Tests\Feature;

use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Filament\Resources\SchedulingDemands\Pages\ListSchedulingDemands;
use App\Filament\Resources\SchedulingDemands\SchedulingDemandResource;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL61SchedulingDemandReadinessTest extends TestCase
{
    use DatabaseTransactions;

    private GenerateSchedulingDemand $generator;

    private int $scopeCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());

        $this->generator = app(GenerateSchedulingDemand::class);

        foreach ([User::StaffRoleRegistrar, User::StaffRoleAcademicHead, User::StaffRoleSystemSuperAdmin, User::StaffRoleFaculty] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_generation_creates_source_linked_demand_rows_without_duplicates(): void
    {
        $source = $this->schedulingSource();
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $first = $this->generator->forTerm($registrar, $source['term']);
        $second = $this->generator->forTerm($registrar, $source['term']);

        $this->assertSame(2, $first['created']);
        $this->assertSame(2, $first['ready']);
        $this->assertSame(0, $first['action_required']);
        $this->assertSame(2, $second['total']);
        $this->assertSame(2, SchedulingDemand::query()->count());

        $lectureDemand = SchedulingDemand::query()
            ->with([
                'termOffering',
                'courseComponent',
                'sectionDeliveryGroup.section',
            ])
            ->where('course_component_id', $source['lecture']->id)
            ->firstOrFail();

        $this->assertTrue($lectureDemand->termOffering->is($source['offering']));
        $this->assertTrue($lectureDemand->courseComponent->is($source['lecture']));
        $this->assertTrue($lectureDemand->sectionDeliveryGroup->is($source['group']));
        $this->assertTrue($lectureDemand->sectionDeliveryGroup->section->is($source['section']));
        $this->assertSame(SchedulingDemand::ValidationReadyForReview, $lectureDemand->validation_state);
        $this->assertFalse($lectureDemand->hasReadinessFindings());
        $this->assertSame('term-offering:'.$source['offering']->id.':delivery-group:'.$source['group']->id.':component:'.$source['lecture']->id, $lectureDemand->demand_key);
        $this->assertSame($source['term']->id, $lectureDemand->source_snapshot['term_id']);
        $this->assertSame($source['course']->id, $lectureDemand->source_snapshot['course_id']);
        $this->assertSame(1, $lectureDemand->source_snapshot['eligible_faculty_count']);
        $this->assertSame('24.00', $lectureDemand->source_snapshot['faculty_load_options'][0]['max_allowed_units']);
        $this->assertSame(1, $lectureDemand->source_snapshot['active_scheduling_window_count']);
    }

    public function test_readiness_findings_surface_missing_or_invalid_source_records(): void
    {
        $source = $this->schedulingSource(
            withSecondComponent: false,
            withCalendar: false,
            withFaculty: false,
            withRooms: false,
            termState: Term::StateDraft,
            groupState: SectionDeliveryGroup::StatePlanned,
            sectionCapacity: 30,
            groupExpectedCount: 31,
        );

        $summary = $this->generator->forTerm($this->staff(User::StaffRoleRegistrar), $source['term']);
        $demand = SchedulingDemand::query()->firstOrFail();
        $findingKeys = $demand->readinessFindingKeys();

        $this->assertSame(1, $summary['action_required']);
        $this->assertSame(SchedulingDemand::ValidationActionRequired, $demand->validation_state);
        $this->assertContains('term_not_active', $findingKeys);
        $this->assertContains('missing_active_scheduling_window', $findingKeys);
        $this->assertContains('delivery_group_not_ready', $findingKeys);
        $this->assertContains('delivery_group_expected_count_exceeds_section_capacity', $findingKeys);
        $this->assertContains('missing_active_faculty_qualification', $findingKeys);
        $this->assertContains('missing_suitable_room', $findingKeys);
    }

    public function test_registrar_and_academic_head_authorization_boundaries_are_enforced(): void
    {
        $source = $this->schedulingSource();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $systemSuperAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $faculty = $this->staff(User::StaffRoleFaculty);

        $this->assertTrue(Gate::forUser($registrar)->allows('viewAny', SchedulingDemand::class));
        $this->assertTrue(Gate::forUser($academicHead)->allows('viewAny', SchedulingDemand::class));
        $this->assertTrue(Gate::forUser($systemSuperAdmin)->allows('create', SchedulingDemand::class));
        $this->assertFalse(Gate::forUser($academicHead)->allows('create', SchedulingDemand::class));
        $this->assertFalse(Gate::forUser($faculty)->allows('viewAny', SchedulingDemand::class));

        $this->expectException(AuthorizationException::class);
        $this->generator->forTerm($academicHead, $source['term']);
    }

    public function test_admin_panel_registers_scheduling_demand_review_surface(): void
    {
        $source = $this->schedulingSource();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $faculty = $this->staff(User::StaffRoleFaculty);

        $this->generator->forTerm($registrar, $source['term']);

        $this->assertTrue(Route::has('filament.admin.resources.scheduling-demands.index'));

        Livewire::actingAs($registrar)
            ->test(ListSchedulingDemands::class)
            ->assertOk();

        Livewire::actingAs($academicHead)
            ->test(ListSchedulingDemands::class)
            ->assertOk();

        $this->actingAs($faculty)
            ->get(SchedulingDemandResource::getUrl())
            ->assertForbidden();
    }

    /**
     * @return array{
     *     term: Term,
     *     course: Course,
     *     specification: CourseSpecification,
     *     lecture: CourseComponent,
     *     laboratory: CourseComponent|null,
     *     offering: TermOffering,
     *     section: Section,
     *     group: SectionDeliveryGroup
     * }
     */
    private function schedulingSource(
        bool $withSecondComponent = true,
        bool $withCalendar = true,
        bool $withFaculty = true,
        bool $withRooms = true,
        string $termState = Term::StateActive,
        string $groupState = SectionDeliveryGroup::StateReady,
        int $sectionCapacity = 30,
        int $groupExpectedCount = 30,
    ): array {
        $term = Term::factory()->create([
            'type' => Term::TypeFirstSemester,
            'label' => 'First Semester '.$this->scopeCounter,
            'state' => $termState,
            'default_max_units' => 21.00,
        ]);

        if ($withCalendar) {
            CalendarEvent::factory()->for($term)->create([
                'event_type' => CalendarEvent::TypeWindow,
                'process_key' => 'scheduling',
                'start_at' => now()->addWeek(),
                'end_at' => now()->addWeeks(2),
                'state' => CalendarEvent::StateActive,
            ]);
        }

        $program = Program::factory()->create(['code' => 'BS'.++$this->scopeCounter]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['code' => 'IT'.str_pad((string) $this->scopeCounter, 3, '0', STR_PAD_LEFT)]);
        $specification = CourseSpecification::factory()->for($course)->create([
            'title' => 'Scheduling Systems',
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace, TermOffering::ModalityOnline],
            'same_faculty_default' => true,
        ]);
        $lecture = CourseComponent::factory()->for($specification)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 3.00,
            'room_type_default' => Room::TypeLectureRoom,
            'sequence' => 1,
        ]);
        $laboratory = $withSecondComponent
            ? CourseComponent::factory()->for($specification)->create([
                'component_type' => CourseComponent::TypeLaboratory,
                'weekly_contact_hours' => 2.00,
                'room_type_default' => Room::TypeLaboratory,
                'sequence' => 2,
            ])
            : null;
        $entry = CurriculumEntry::factory()->for($curriculum)->for($specification, 'courseSpecification')->create([
            'year_level' => 'First Year',
            'term_type' => $term->type,
            'sequence' => 1,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'expected_count' => $groupExpectedCount,
            'state' => TermOffering::StatePendingScheduling,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'BSIT-1A-'.$this->scopeCounter,
            'capacity' => $sectionCapacity,
            'state' => Section::StatePlanned,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'Regular Cohort '.$this->scopeCounter,
            'expected_count' => $groupExpectedCount,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => $groupState,
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
                    'default_max_units_snapshot' => 21.00,
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

            if ($withSecondComponent) {
                Room::factory()->create([
                    'room_type' => Room::TypeLaboratory,
                    'capacity' => max(40, $groupExpectedCount),
                    'is_active' => true,
                ]);
            }
        }

        return [
            'term' => $term,
            'course' => $course,
            'specification' => $specification,
            'lecture' => $lecture,
            'laboratory' => $laboratory,
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
}
