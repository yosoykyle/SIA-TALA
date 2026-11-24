<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Scheduling\TermCapacityHeadroomProjection;
use App\Filament\Pages\TermClasses;
use App\Filament\Pages\TermResources;
use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\Terms\Pages\CreateTerm;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\TermTeachingGridRow;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class Issue93TermPlanningQaGuardrailsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function actingAsRegistrar(): User
    {
        Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $role = Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $role->syncPermissions([
            Permission::findOrCreate('manage-schedules', 'web'),
            Permission::findOrCreate('approve-documents', 'web'),
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);
        $this->actingAs($user)->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleRegistrar]);

        return $user;
    }

    private function makeFaculty(string $firstName, string $lastName): User
    {
        $role = Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $faculty = User::factory()->create([
            'first_name' => $firstName,
            'middle_name' => null,
            'last_name' => $lastName,
            'name' => $firstName.' '.$lastName,
            'status' => User::StatusActive,
        ]);
        $faculty->assignRole($role);

        return $faculty;
    }

    public function test_create_term_blocks_duplicate_term_type_within_same_academic_year_while_allowing_distinct_types(): void
    {
        $this->actingAsRegistrar();
        $year = AcademicYear::factory()->create([
            'label' => '2026-2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
        ]);

        Term::factory()->for($year)->create([
            'type' => Term::TypeSecondSemester,
            'label' => 'Second Semester',
            'starts_on' => '2027-01-10',
            'ends_on' => '2027-05-20',
        ]);

        Livewire::test(CreateTerm::class)
            ->fillForm([
                'academic_year_id' => $year->id,
                'type' => Term::TypeSecondSemester,
                'starts_on' => '2027-02-01',
                'ends_on' => '2027-05-25',
            ])
            ->call('create')
            ->assertHasFormErrors(['type' => 'unique']);

        Livewire::test(CreateTerm::class)
            ->fillForm([
                'academic_year_id' => $year->id,
                'type' => Term::TypeSpecialTerm,
                'starts_on' => '2027-06-01',
                'ends_on' => '2027-07-15',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $specialTerm = Term::query()
            ->where('academic_year_id', $year->id)
            ->where('type', Term::TypeSpecialTerm)
            ->sole();

        $this->assertSame('Special Term', $specialTerm->label);
        $this->assertEquals(24.0, (float) $specialTerm->default_max_units);
    }

    public function test_capacity_headroom_projection_tracks_room_seats_room_hours_and_faculty_units(): void
    {
        $this->actingAsRegistrar();
        $term = Term::factory()->create(['type' => Term::TypeFirstSemester, 'default_max_units' => 12]);
        $package = TermCalendarPackage::factory()->for($term)->create(['state' => TermCalendarPackage::StateActive]);
        TermTeachingGridRow::factory()->create([
            'term_calendar_package_id' => $package->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '12:00:00',
            'breaks' => [],
        ]);

        Room::factory()->create(['is_active' => true, 'capacity' => 30]);
        $program = Program::factory()->create(['code' => 'BSIT']);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['code' => 'IT 101']);
        $spec = CourseSpecification::factory()->for($course)->create(['state' => CourseSpecification::StateActive, 'credit_units' => 3.0]);
        CourseComponent::factory()->for($spec, 'courseSpecification')->create([
            'weekly_contact_hours' => 3.0,
            'meeting_pattern' => '2x90',
        ]);
        $entry = CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create([
            'year_level' => 'First Year',
            'term_type' => Term::TypeFirstSemester,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create(['modality' => TermOffering::ModalityFaceToFace]);
        Section::factory()->for($offering, 'termOffering')->for($spec, 'courseRevision')->create(['delivery_mode' => TermOffering::ModalityFaceToFace]);

        $cohort = TermCohort::factory()->for($term)->for($program)->create([
            'reference' => 'BSIT 1-1',
            'confirmed_count' => 35,
        ]);

        $faculty = $this->makeFaculty('Elena', 'Cruz');
        FacultyQualification::factory()->create(['faculty_user_id' => $faculty->id, 'course_id' => $course->id, 'is_active' => true]);
        FacultyAvailabilityDeclaration::factory()->create([
            'term_id' => $term->id,
            'faculty_user_id' => $faculty->id,
            'declaration' => FacultyAvailabilityDeclaration::DeclarationAvailable,
            'availability_mode' => FacultyAvailabilityDeclaration::ModeAnyApproved,
        ]);

        $headroom = app(TermCapacityHeadroomProjection::class)->forTerm($term);
        $this->assertSame('over', $headroom['room_seats']['status']);
        $this->assertSame(1, $headroom['room_seats']['over_capacity_sections']);
        $this->assertEquals(3.0, $headroom['room_hours']['demanded_hours']);
        $this->assertEquals(4.0, $headroom['room_hours']['available_hours']);
        $this->assertEquals(3.0, $headroom['faculty_units']['demanded_units']);
        $this->assertEquals(12.0, $headroom['faculty_units']['available_units']);

        Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class)
            ->assertSee('Capacity headroom')
            ->assertSee('Room seat cap')
            ->assertSee('Weekly room-hour budget')
            ->assertSee('Available faculty teaching units');
    }

    public function test_registrar_can_mark_faculty_not_teaching_for_term_with_reason_and_restore(): void
    {
        $this->actingAsRegistrar();
        $term = Term::factory()->create(['type' => Term::TypeFirstSemester, 'default_max_units' => 24]);
        $faculty = $this->makeFaculty('Marco', 'Santos');
        $program = Program::factory()->create(['code' => 'BSIT']);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['code' => 'IT 202']);
        $spec = CourseSpecification::factory()->for($course)->create(['state' => CourseSpecification::StateActive]);
        $entry = CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create([
            'year_level' => 'Second Year',
            'term_type' => Term::TypeFirstSemester,
        ]);
        TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create(['state' => TermOffering::StatePendingScheduling]);
        FacultyQualification::factory()->create(['faculty_user_id' => $faculty->id, 'course_id' => $course->id, 'is_active' => true]);

        $component = Livewire::withQueryParams(['termId' => $term->id])->test(TermResources::class)
            ->assertSee('Marco Santos')
            ->callTableAction('markNotTeaching', 'faculty-'.$faculty->id, data: [
                'reason' => 'On sabbatical leave this semester',
            ])
            ->assertHasNoTableActionErrors();

        $override = FacultyTermLoadOverride::query()
            ->where('term_id', $term->id)
            ->where('faculty_user_id', $faculty->id)
            ->where('is_active', true)
            ->sole();

        $this->assertEquals(0.0, $override->allowedLoadUnits());
        $this->assertSame('On sabbatical leave this semester', $override->reason);

        $component->assertSee('Not teaching (Registrar)')
            ->assertSee('On sabbatical leave this semester')
            ->callTableAction('restoreTeaching', 'faculty-'.$faculty->id)
            ->assertHasNoTableActionErrors();

        $this->assertFalse($override->fresh()->is_active);
    }

    public function test_registrar_can_cancel_in_progress_timetable_generation_run(): void
    {
        $registrar = $this->actingAsRegistrar();
        $term = Term::factory()->create();
        $run = ScheduleGenerationRun::factory()->for($term)->create([
            'status' => ScheduleGenerationRun::StatusDispatching,
            'requested_by' => $registrar->id,
        ]);

        Livewire::withQueryParams(['termId' => $term->id, 'selectedRunId' => $run->id])->test(TermTimetable::class)
            ->assertSee('Cancel generation')
            ->callAction('cancelActiveRun')
            ->assertHasNoActionErrors();

        $run->refresh();
        $this->assertSame(ScheduleGenerationRun::StatusFailed, $run->status);
        $this->assertSame('Rejected', $run->candidate_state);
        $this->assertSame('CANCELLED_BY_REGISTRAR', data_get($run->diagnostics, 'solver_dispatch.failure.code'));
    }

    public function test_printed_timetable_omits_not_recorded_solver_metadata_and_repetitive_initial_labels(): void
    {
        $this->actingAsRegistrar();
        $version = PublishedTimetableVersion::factory()->create(['authority_reference' => null]);
        PublishedTimetableMeeting::factory()->create([
            'published_timetable_version_id' => $version->id,
            'supersedes_meeting_id' => null,
        ]);

        $this->get(route('timetable.version.print', ['version' => $version]))
            ->assertSuccessful()
            ->assertSee('Academic Year')
            ->assertDontSee('Solver generated')
            ->assertDontSee('Not recorded')
            ->assertDontSee('Initial publication')
            ->assertSee('Generated through TALA');
    }
}
