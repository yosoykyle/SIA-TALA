<?php

namespace Tests\Feature;

use App\Filament\Resources\AcademicYears\Pages\CreateAcademicYear;
use App\Filament\Resources\Terms\Pages\CreateTerm;
use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Program;
use App\Models\Room;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Policies\AcademicYearPolicy;
use App\Policies\CurriculumPolicy;
use App\Policies\CurriculumSubjectPolicy;
use App\Policies\ProgramPolicy;
use App\Policies\RoomPolicy;
use App\Policies\SubjectPolicy;
use App\Policies\TermPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AcademicFoundationFilamentResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_academic_foundation_resources_expose_controlled_manager_crud(): void
    {
        foreach ([
            'AcademicYears/AcademicYearResource.php' => ['CreateAcademicYear::route', 'EditAcademicYear::route', 'ViewAcademicYear::route'],
            'Programs/ProgramResource.php' => ['CreateProgram::route', 'EditProgram::route', 'ViewProgram::route'],
            'Subjects/SubjectResource.php' => ['CreateSubject::route', 'EditSubject::route', 'ViewSubject::route'],
            'Curriculums/CurriculumResource.php' => ['CreateCurriculum::route', 'EditCurriculum::route', 'ViewCurriculum::route'],
            'Terms/TermResource.php' => ['CreateTerm::route', 'EditTerm::route', 'ViewTerm::route'],
            'Rooms/RoomResource.php' => ['CreateRoom::route', 'EditRoom::route', 'ViewRoom::route'],
        ] as $relativePath => $expectedRoutes) {
            $source = $this->resourceSource($relativePath);

            $this->assertStringContainsString("'Registrar'", $source);
            $this->assertStringContainsString("'academic-head'", $source);

            foreach ($expectedRoutes as $expectedRoute) {
                $this->assertStringContainsString($expectedRoute, $source);
            }
        }
    }

    public function test_academic_foundation_forms_avoid_raw_foreign_key_entry_and_use_typed_relationships(): void
    {
        $academicYearForm = $this->resourceSource('AcademicYears/Schemas/AcademicYearForm.php');
        $programForm = $this->resourceSource('Programs/Schemas/ProgramForm.php');
        $subjectForm = $this->resourceSource('Subjects/Schemas/SubjectForm.php');
        $curriculumForm = $this->resourceSource('Curriculums/Schemas/CurriculumForm.php');
        $termForm = $this->resourceSource('Terms/Schemas/TermForm.php');
        $roomForm = $this->resourceSource('Rooms/Schemas/RoomForm.php');

        $this->assertStringContainsString("TextInput::make('academic_year')", $academicYearForm);
        $this->assertStringNotContainsString("Hidden::make('education_level')", $academicYearForm);
        $this->assertStringNotContainsString("Select::make('education_level')", $academicYearForm);
        $this->assertStringContainsString("DatePicker::make('school_year_start_date')", $academicYearForm);
        $this->assertStringContainsString("DatePicker::make('school_year_end_date')", $academicYearForm);
        $this->assertStringContainsString("Textarea::make('reference_note')", $academicYearForm);
        $this->assertStringContainsString("TextInput::make('code')", $programForm);
        $this->assertStringContainsString("TextInput::make('code')", $subjectForm);
        $this->assertStringContainsString("Select::make('program_id')", $curriculumForm);
        $this->assertStringContainsString("Repeater::make('curriculumSubjects')", $curriculumForm);
        $this->assertStringContainsString('->relationship()', $curriculumForm);
        $this->assertStringContainsString("Select::make('subject_id')", $curriculumForm);
        $this->assertStringContainsString("TextInput::make('weekly_contact_hours')", $curriculumForm);
        $this->assertStringContainsString("Select::make('academic_subject_type')", $curriculumForm);
        $this->assertStringContainsString("Select::make('scheduling_group')", $curriculumForm);
        $this->assertStringContainsString("Select::make('delivery_rule_override')", $curriculumForm);
        $this->assertStringContainsString("Select::make('academic_year_id')", $termForm);
        $this->assertStringContainsString("->relationship('academicYear', 'academic_year')", $termForm);
        $this->assertStringContainsString("TextInput::make('code')", $roomForm);
        $this->assertStringNotContainsString("TextInput::make('program_id')", $curriculumForm);
        $this->assertStringNotContainsString("TextInput::make('subject_id')", $curriculumForm);
        $this->assertStringNotContainsString("TextInput::make('academic_year_id')", $termForm);
    }

    public function test_academic_foundation_tables_do_not_expose_generic_bulk_delete(): void
    {
        foreach ([
            'AcademicYears/Tables/AcademicYearsTable.php',
            'Programs/Tables/ProgramsTable.php',
            'Subjects/Tables/SubjectsTable.php',
            'Curriculums/Tables/CurriculumsTable.php',
            'Terms/Tables/TermsTable.php',
            'Rooms/Tables/RoomsTable.php',
        ] as $relativePath) {
            $source = $this->resourceSource($relativePath);

            $this->assertStringContainsString('ViewAction::make()', $source);
            $this->assertStringContainsString('EditAction::make()', $source);
            $this->assertStringNotContainsString('DeleteAction::make()', $source);
            $this->assertStringNotContainsString('DeleteBulkAction::make()', $source);
            $this->assertStringContainsString('->toolbarActions([])', $source);
        }
    }

    public function test_academic_foundation_policies_allow_managers_and_block_dangerous_deletes(): void
    {
        $curriculumManager = $this->userAllowing(['manage-curricula']);
        $termManager = $this->userAllowing(['manage-terms']);
        $scheduleManager = $this->userAllowing(['manage-schedules']);
        $viewer = $this->userAllowing(['view-global-records']);
        $denied = $this->userAllowing([]);

        $academicYear = new AcademicYear;
        $program = new Program;
        $subject = new Subject;
        $curriculum = new Curriculum;
        $curriculumSubject = new CurriculumSubject;
        $term = new Term;
        $room = new Room;

        $this->assertTrue(app(ProgramPolicy::class)->create($curriculumManager));
        $this->assertTrue(app(SubjectPolicy::class)->create($curriculumManager));
        $this->assertTrue(app(CurriculumPolicy::class)->create($curriculumManager));
        $this->assertTrue(app(CurriculumSubjectPolicy::class)->delete($curriculumManager, $curriculumSubject));
        $this->assertTrue(app(AcademicYearPolicy::class)->create($termManager));
        $this->assertTrue(app(TermPolicy::class)->create($termManager));
        $this->assertTrue(app(RoomPolicy::class)->create($scheduleManager));

        $this->assertTrue(app(AcademicYearPolicy::class)->viewAny($viewer));
        $this->assertTrue(app(ProgramPolicy::class)->viewAny($viewer));
        $this->assertTrue(app(RoomPolicy::class)->viewAny($viewer));
        $this->assertFalse(app(ProgramPolicy::class)->viewAny($denied));

        $this->assertFalse(app(AcademicYearPolicy::class)->delete($termManager, $academicYear));
        $this->assertFalse(app(ProgramPolicy::class)->delete($curriculumManager, $program));
        $this->assertFalse(app(SubjectPolicy::class)->delete($curriculumManager, $subject));
        $this->assertFalse(app(CurriculumPolicy::class)->delete($curriculumManager, $curriculum));
        $this->assertFalse(app(TermPolicy::class)->delete($termManager, $term));
        $this->assertFalse(app(RoomPolicy::class)->delete($scheduleManager, $room));
    }

    public function test_calendar_setup_is_staff_operable_from_academic_year_to_term_forms(): void
    {
        $termManager = $this->userAllowing(['manage-terms']);

        $this->actingAs($termManager);

        Livewire::test(CreateAcademicYear::class)
            ->fillForm([
                'academic_year' => '2026-2027',
                'school_year_start_date' => '2026-06-01',
                'school_year_end_date' => '2027-03-31',
                'status' => 'draft',
                'reference_note' => 'College calendar fixture.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $academicYear = AcademicYear::query()
            ->where('academic_year', '2026-2027')
            ->firstOrFail();

        Livewire::test(CreateTerm::class)
            ->fillForm([
                'academic_year_id' => $academicYear->id,
                'term_name' => 'College 1st Sem AY 2026-2027',
                'term_type' => 'semester',
                'is_active' => true,
                'term_start_date' => '2026-06-01',
                'term_end_date' => '2026-10-31',
                'class_start_date' => '2026-06-08',
                'class_end_date' => '2026-10-24',
                'scheduling_starts_at' => '2026-05-01 08:00:00',
                'enrollment_starts_at' => '2026-05-15 08:00:00',
                'enrollment_ends_at' => '2026-05-31 17:00:00',
                'late_enrollment_ends_at' => '2026-06-07 17:00:00',
                'payment_deadline' => '2026-06-15 17:00:00',
                'adjustment_ends_at' => '2026-06-21 17:00:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $term = Term::query()
            ->where('term_name', 'College 1st Sem AY 2026-2027')
            ->firstOrFail();

        $this->assertTrue($term->academicYear->is($academicYear));
    }

    public function test_room_select_options_expose_only_active_rooms_plus_current_legacy_value(): void
    {
        Room::factory()->create([
            'code' => 'R-101',
            'name' => 'Room 101',
            'building' => 'Main',
            'capacity' => 30,
            'is_active' => true,
        ]);
        Room::factory()->create([
            'code' => 'R-404',
            'is_active' => false,
        ]);

        $options = Room::selectOptions('LEGACY-1');

        $this->assertArrayHasKey('R-101', $options);
        $this->assertArrayNotHasKey('R-404', $options);
        $this->assertSame('LEGACY-1 (inactive or legacy)', $options['LEGACY-1']);
    }

    /**
     * @param  list<string>  $allowedAbilities
     */
    private function userAllowing(array $allowedAbilities): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($allowedAbilities as $ability) {
            Permission::findOrCreate($ability);
        }

        $user = User::factory()->create();

        if ($allowedAbilities !== []) {
            $user->givePermissionTo($allowedAbilities);
        }

        return $user;
    }

    private function resourceSource(string $relativePath): string
    {
        $source = file_get_contents(app_path("Filament/Resources/{$relativePath}"));

        $this->assertIsString($source);

        return $source;
    }
}
