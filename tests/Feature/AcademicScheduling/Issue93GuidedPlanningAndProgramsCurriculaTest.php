<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Scheduling\TermCoverageProjection;
use App\Filament\Pages\CatalogCurriculaWorkbench;
use App\Filament\Pages\TermCalendar;
use App\Filament\Pages\TermClasses;
use App\Filament\Pages\TermOverview;
use App\Filament\Pages\TermResources;
use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\Courses\CourseResource;
use App\Filament\Resources\Courses\Pages\ListCourses;
use App\Filament\Resources\Courses\Pages\ViewCourse;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\CurriculumVersions\Pages\ListCurriculumVersions;
use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Filament\Resources\Programs\Pages\ListPrograms;
use App\Filament\Resources\Programs\Pages\ViewProgram;
use App\Filament\Resources\Programs\ProgramResource;
use App\Filament\Resources\Programs\RelationManagers\CurriculumVersionsRelationManager;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\Sections\Pages\ViewSection;
use App\Filament\Resources\Terms\Pages\CreateTerm;
use App\Models\AcademicYear;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermOffering;
use App\Models\TermTeachingGridRow;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class Issue93GuidedPlanningAndProgramsCurriculaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_four_step_guided_progress_bar_and_dependency_order_appear_across_term_planning(): void
    {
        $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateDraft]);

        $overview = Livewire::withQueryParams(['termId' => $term->id])->test(TermOverview::class);
        $html = $overview->html();

        $overview->assertSee('Teaching calendar & load')
            ->assertSee('Student sections & classes')
            ->assertSee('Faculty & rooms')
            ->assertSee('Generate & publish timetable')
            ->assertSee('Current')
            ->assertSee('Needs attention')
            ->assertSeeHtml('href="'.e(TermCalendar::getUrl(['termId' => $term->id])).'"')
            ->assertSeeHtml('href="'.e(TermClasses::getUrl(['termId' => $term->id])).'"')
            ->assertSeeHtml('href="'.e(TermResources::getUrl(['termId' => $term->id])).'"')
            ->assertSeeHtml('href="'.e(TermTimetable::getUrl(['termId' => $term->id])).'"');

        $posCalendar = strpos($html, '1. Teaching calendar &amp; load');
        $posClasses = strpos($html, '2. Student sections &amp; classes');
        $posResources = strpos($html, '3. Faculty &amp; rooms');
        $posTimetable = strpos($html, '4. Generate &amp; publish timetable');

        $this->assertNotFalse($posCalendar);
        $this->assertNotFalse($posClasses);
        $this->assertNotFalse($posResources);
        $this->assertNotFalse($posTimetable);
        $this->assertTrue($posCalendar < $posClasses && $posClasses < $posResources && $posResources < $posTimetable);

        foreach ([TermCalendar::class, TermClasses::class, TermResources::class] as $pageClass) {
            Livewire::withQueryParams(['termId' => $term->id])->test($pageClass)
                ->assertSee('Teaching calendar & load')
                ->assertSee('Student sections & classes')
                ->assertSee('Faculty & rooms')
                ->assertSee('Generate & publish timetable');
        }

        // Activate calendar -> Step 1 transitions to Done, Step 2 becomes Current.
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);
        TermTeachingGridRow::factory()->create([
            'term_calendar_package_id' => $package->id,
            'day_of_week' => 1,
            'starts_at' => '07:00:00',
            'ends_at' => '19:00:00',
            'breaks' => [],
        ]);

        Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class)
            ->assertSee('Done')
            ->assertSee('Current');
    }

    public function test_term_creation_uncollapses_and_defaults_max_units_to_24(): void
    {
        $this->staff(User::StaffRoleRegistrar);
        $year = AcademicYear::factory()->create();

        Livewire::test(CreateTerm::class)
            ->assertFormSet(['default_max_units' => 24])
            ->fillForm([
                'academic_year_id' => $year->id,
                'type' => Term::TypeFirstSemester,
                'starts_on' => $year->starts_on->toDateString(),
                'ends_on' => $year->ends_on->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $term = Term::query()->where('academic_year_id', $year->id)->sole();
        $this->assertEquals(24.00, (float) $term->default_max_units);
    }

    public function test_term_calendar_prefills_defaults_and_activates_in_one_step(): void
    {
        $this->staff(User::StaffRoleRegistrar);
        $year = AcademicYear::factory()->create([
            'starts_on' => '2027-08-01',
            'ends_on' => '2027-12-20',
        ]);
        $term = Term::factory()->for($year)->create([
            'state' => Term::StateDraft,
            'starts_on' => '2027-08-10',
            'ends_on' => '2027-12-15',
        ]);

        $component = Livewire::withQueryParams(['termId' => $term->id])->test(TermCalendar::class)
            ->assertSee('Save & activate calendar')
            ->assertDontSee('Draft calendar package')
            ->mountAction('saveAndActivateCalendar')
            ->assertSchemaStateSet([
                'administrative_starts_on' => '2027-08-10',
                'administrative_ends_on' => '2027-12-15',
                'classes_start_on' => '2027-08-10',
                'classes_end_on' => '2027-12-15',
            ]);

        $rows = array_values($component->instance()->getSchema($component->instance()->getMountedActionSchemaName())->getRawState()['teaching_grid_rows']);
        $this->assertCount(6, $rows);
        $this->assertSame([1, 2, 3, 4, 5, 6], array_map('intval', array_column($rows, 'day_of_week')));
        $this->assertSame('07:00', $rows[0]['starts_at']);
        $this->assertSame('19:00', $rows[0]['ends_at']);

        $component->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Teaching calendar saved and activated');

        $activated = TermCalendarPackage::query()->where('term_id', $term->id)->sole();
        $this->assertSame(TermCalendarPackage::StateActive, $activated->state);
        $this->assertSame(Term::StateActive, $term->fresh()->state);
        $this->assertCount(6, $activated->teachingGridRows);

        // Academic Head stays read-only and cannot access saveAndActivateCalendar.
        $this->staff(User::StaffRoleAcademicHead);
        Livewire::withQueryParams(['termId' => $term->id])->test(TermCalendar::class)
            ->assertActionHidden('saveAndActivateCalendar');
    }

    public function test_term_resources_exposes_add_room_and_manage_teaching_qualifications_directly(): void
    {
        $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();

        Livewire::withQueryParams(['termId' => $term->id])->test(TermResources::class)
            ->assertActionVisible('addRoom')
            ->assertActionVisible('manageTeachingQualifications')
            ->assertSee('Add room')
            ->assertSee('Manage teaching qualifications')
            ->assertSeeHtml('href="'.e(RoomResource::getUrl('create', ['termId' => $term->id])).'"')
            ->assertSeeHtml('href="'.e(FacultyQualificationResource::getUrl('index', ['termId' => $term->id])).'"');

        $this->staff(User::StaffRoleAcademicHead);
        Livewire::withQueryParams(['termId' => $term->id])->test(TermResources::class)
            ->assertActionHidden('addRoom')
            ->assertActionHidden('manageTeachingQualifications');
    }

    public function test_coverage_projection_and_section_infolist_hide_raw_ulid_and_use_semantic_badges(): void
    {
        $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['type' => Term::TypeFirstSemester]);
        $program = Program::factory()->create(['code' => 'BSCS', 'name' => 'BS Computer Science']);
        $ulidCode = (string) Str::ulid();
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'name' => 'BSCS 2026 Study Plan',
            'version_code' => $ulidCode,
            'state' => CurriculumVersion::StateActive,
        ]);
        $course = Course::factory()->create(['code' => 'CS 101']);
        $spec = CourseSpecification::factory()->for($course)->create(['state' => CourseSpecification::StateActive]);
        $entry = CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create([
            'year_level' => 'First Year',
            'term_type' => $term->type,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create();
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'CS101-A',
            'state' => Section::StateOpen,
        ]);

        $projection = app(TermCoverageProjection::class)->forTerm($term);
        $row = collect($projection['rows'])->firstWhere('curriculum_version_id', $curriculum->id);
        $this->assertNotNull($row);
        $this->assertSame('BSCS 2026 Study Plan', $row['curriculum_label']);
        $this->assertSame('Active', $row['curriculum_state_label']);

        Livewire::withQueryParams(['termId' => $term->id])->test(TermOverview::class)
            ->assertSee('BSCS 2026 Study Plan')
            ->assertDontSee($ulidCode);

        Livewire::withQueryParams(['termId' => $term->id])->test(ViewSection::class, ['record' => $section->id])
            ->assertSee('Open');
    }

    public function test_programs_and_curricula_naming_always_expanded_sidebar_room_default_and_bidirectional_visibility(): void
    {
        $this->staff(User::StaffRoleRegistrar);

        // Sidebar exposes Programs & Curricula with Programs -> Subjects -> Curricula in execution order.
        $items = collect(Filament::getPanel('admin')->getNavigation())
            ->flatMap(fn ($group) => $group->getItems());
        $programsCurriculaItem = $items->first(fn (NavigationItem $item): bool => $item->getLabel() === 'Programs & Curricula');
        $this->assertNotNull($programsCurriculaItem);
        $this->assertSame(
            ['Programs', 'Subjects', 'Curricula'],
            collect($programsCurriculaItem->getChildItems())->map->getLabel()->values()->all(),
        );
        $this->assertSame('Programs & Curricula', CatalogCurriculaWorkbench::getNavigationLabel());

        // CourseSpecificationForm uncollapses room_type_default and auto-defaults LECTURE -> LECTURE_ROOM, LABORATORY -> LABORATORY.
        $createSubject = Livewire::test(ListCourses::class)->mountAction('create');
        $mountedSchema = $createSubject->instance()->getSchema($createSubject->instance()->getMountedActionSchemaName());
        $components = $mountedSchema->getRawState()['components'];
        $firstKey = array_key_first($components);
        $this->assertSame(CourseComponent::RoomTypeLectureRoom, $components[$firstKey]['room_type_default']);

        $createSubject->set("mountedActions.0.data.components.{$firstKey}.component_type", CourseComponent::TypeLaboratory);
        $updatedComponents = $createSubject->instance()->getSchema($createSubject->instance()->getMountedActionSchemaName())->getRawState()['components'];
        $this->assertSame(CourseComponent::RoomTypeLaboratory, $updatedComponents[$firstKey]['room_type_default']);

        // Bidirectional visibility between Programs, Curricula, and Subjects.
        $programA = Program::factory()->create(['code' => 'BSIT', 'name' => 'BS Information Technology']);
        $programB = Program::factory()->create(['code' => 'BSBA', 'name' => 'BS Business Administration']);

        $curriculumA = CurriculumVersion::factory()->for($programA)->create([
            'name' => 'BSIT 2026 Plan',
            'version_code' => (string) Str::ulid(),
            'state' => CurriculumVersion::StateActive,
        ]);
        $curriculumB = CurriculumVersion::factory()->for($programB)->create([
            'name' => 'BSBA 2026 Plan',
            'version_code' => (string) Str::ulid(),
            'state' => CurriculumVersion::StateDraft,
        ]);

        $courseA = Course::factory()->create(['code' => 'IT 101']);
        $specA = CourseSpecification::factory()->for($courseA)->create([
            'title' => 'Introduction to Computing',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);
        $courseB = Course::factory()->create(['code' => 'BA 101']);
        $specB = CourseSpecification::factory()->for($courseB)->create([
            'title' => 'Business Fundamentals',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);

        CurriculumEntry::factory()->for($curriculumA)->for($specA, 'courseSpecification')->create([
            'year_level' => 'First Year',
            'term_type' => Term::TypeFirstSemester,
        ]);
        CurriculumEntry::factory()->for($curriculumB)->for($specB, 'courseSpecification')->create([
            'year_level' => 'First Year',
            'term_type' => Term::TypeFirstSemester,
        ]);

        // 1. CoursesTable shows Programs badge column and filters by Program.
        Livewire::test(ListCourses::class)
            ->assertSee('BSIT')
            ->assertSee('BSBA')
            ->filterTable('program_id', $programA->id)
            ->assertCanSeeTableRecords([$courseA])
            ->assertCanNotSeeTableRecords([$courseB]);

        // 2. ViewCourse / course-detail shows "Used in programs & curricula" links using curriculum->name (no raw ULID).
        Livewire::test(ViewCourse::class, ['record' => $courseA->id])
            ->assertSee('Used in programs & curricula')
            ->assertSee('BSIT')
            ->assertSee('BSIT 2026 Plan')
            ->assertDontSee($curriculumA->version_code)
            ->assertSeeHtml('href="'.e(ProgramResource::getUrl('view', ['record' => $programA->id])).'"')
            ->assertSeeHtml('href="'.e(CurriculumVersionResource::getUrl('review', ['record' => $curriculumA->id, 'programId' => $programA->id])).'"');

        // 3. ViewProgram with 1 active curriculum hides redundant 1-row Curricula table, exposes clear header shortcuts,
        // and renders Active curriculum subjects with shared <x-tala-navigation stretch>.
        Livewire::test(ViewProgram::class, ['record' => $programA->id])
            ->assertSee('Open active curriculum')
            ->assertSee('New curriculum')
            ->assertSee('Edit program details')
            ->assertDontSee('Curricula for this program. Open any row to view or edit its subjects, or start a new batch curriculum.')
            ->assertSee('Active curriculum subjects')
            ->assertSee('Open curriculum')
            ->assertDontSee('Open study plan')
            ->assertSee('BSIT 2026 Plan')
            ->assertSee('IT 101')
            ->assertSee('Introduction to Computing')
            ->assertSeeHtml('href="'.e(CourseResource::getUrl('view', [
                'record' => $courseA->id,
                'programId' => $programA->id,
                'curriculumVersionId' => $curriculumA->id,
            ])).'"');
    }

    public function test_programs_and_curricula_polish_breadcrumbs_docked_tabs_relation_manager_and_safe_program_deletion(): void
    {
        $this->staff(User::StaffRoleRegistrar);

        $usedProgram = Program::factory()->create([
            'code' => 'BSIT',
            'name' => 'BS Information Technology',
            'duration_years' => 4,
            'is_active' => true,
        ]);
        $unusedProgram = Program::factory()->create([
            'code' => 'BSTM',
            'name' => 'BS Tourism Management',
            'duration_years' => 4,
            'is_active' => true,
        ]);

        $activeCurriculum = CurriculumVersion::factory()->for($usedProgram)->create([
            'name' => 'BSIT Curriculum — Batch 2026',
            'state' => CurriculumVersion::StateActive,
        ]);
        $draftCurriculum = CurriculumVersion::factory()->for($usedProgram)->create([
            'name' => 'BSIT Curriculum — Batch 2027 Draft',
            'state' => CurriculumVersion::StateDraft,
        ]);
        $course = Course::factory()->create(['code' => 'IT 101']);
        $spec = CourseSpecification::factory()->for($course)->create([
            'title' => 'Introduction to Computing',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);
        CurriculumEntry::factory()->for($activeCurriculum)->for($spec, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
        ]);

        // 1. Breadcrumbs across Programs, Curricula, and Subjects use "Programs & Curricula".
        foreach ([
            Livewire::test(ListPrograms::class),
            Livewire::test(ListCurriculumVersions::class),
            Livewire::test(ListCourses::class),
            Livewire::test(ViewCourse::class, ['record' => $course->id]),
            Livewire::test(ViewProgram::class, ['record' => $usedProgram->id]),
        ] as $page) {
            $page->assertSee('Programs & Curricula')
                ->assertDontSee('Catalog & Curricula');
        }

        // 2. CatalogCurriculaWorkbench uses shared <x-tala-navigation> docked in table header + single-line Dropdown filters.
        Livewire::test(CatalogCurriculaWorkbench::class)
            ->assertSeeHtml('tala-navigation tala-navigation-compact tala-curricula-tabs-dock')
            ->assertSeeHtml('role="tablist"')
            ->assertDontSeeHtml('fi-ta-filters-above-content-ctn')
            ->assertSee('Draft curricula')
            ->assertSee('Active curricula')
            ->assertCanSeeTableRecords([$draftCurriculum])
            ->call('switchTab', 'active')
            ->assertCanSeeTableRecords([$activeCurriculum]);

        // Compact single-line Dropdown filters across Programs, Subjects, and Curricula tables.
        foreach ([
            Livewire::test(ListPrograms::class),
            Livewire::test(ListCourses::class),
            Livewire::test(ListCurriculumVersions::class),
        ] as $tablePage) {
            $tablePage->assertDontSeeHtml('fi-ta-filters-above-content-ctn');
        }

        // 3. ViewProgram with >1 curriculum renders relation manager, shared <x-tala-navigation stretch> prospectus tabs,
        // and "Open active curriculum" / "Edit program details" header shortcuts.
        Livewire::test(ViewProgram::class, ['record' => $usedProgram->id])
            ->assertSee('Open active curriculum')
            ->assertSee('Edit program details')
            ->assertSee('Open curriculum')
            ->assertDontSee('Open study plan')
            ->assertSee('Curricula for this program. Open any row to view or edit its subjects, or start a new batch curriculum.')
            ->assertSeeHtml('tala-navigation tala-navigation-stretch')
            ->assertSeeHtml('role="tablist"');

        // Program with 0 curricula hides relation manager and promotes "Create curriculum" shortcut.
        Livewire::test(ViewProgram::class, ['record' => $unusedProgram->id])
            ->assertSee('Create curriculum')
            ->assertSee('Edit program details')
            ->assertDontSee('Curricula for this program. Open any row to view or edit its subjects, or start a new batch curriculum.');

        Livewire::test(CurriculumVersionsRelationManager::class, [
            'ownerRecord' => $usedProgram,
            'pageClass' => ViewProgram::class,
        ])
            ->assertDontSeeHtml('fi-ta-filters-above-content-ctn')
            ->assertSee('Curricula for this program. Open any row to view or edit its subjects, or start a new batch curriculum.')
            ->assertTableActionVisible('discardDraft', $draftCurriculum)
            ->assertTableActionHidden('discardDraft', $activeCurriculum)
            ->callTableAction('discardDraft', $draftCurriculum)
            ->assertHasNoTableActionErrors()
            ->assertNotified('Draft curriculum discarded');

        $this->assertDatabaseMissing('curriculum_versions', ['id' => $draftCurriculum->id]);

        // 4. ProgramsTable allows deleting strictly unused programs while protecting used programs (Deactivate only).
        Livewire::test(ListPrograms::class)
            ->assertTableActionVisible('deleteProgram', $unusedProgram)
            ->assertTableActionHidden('deactivateProgram', $unusedProgram)
            ->assertTableActionHidden('deleteProgram', $usedProgram)
            ->assertTableActionVisible('deactivateProgram', $usedProgram)
            ->callTableAction('deleteProgram', $unusedProgram)
            ->assertHasNoTableActionErrors()
            ->assertNotified('Program deleted')
            ->callTableAction('deactivateProgram', $usedProgram)
            ->assertHasNoTableActionErrors()
            ->assertNotified('Program deactivated');

        $this->assertDatabaseMissing('programs', ['id' => $unusedProgram->id]);
        $this->assertFalse($usedProgram->fresh()->is_active);

        // Read-only Academic Head cannot delete, deactivate, or reactivate programs.
        $inactiveProgram = Program::factory()->create(['code' => 'BSARCH', 'is_active' => false]);
        $usedProgram->update(['is_active' => true]);
        $anotherUnused = Program::factory()->create(['code' => 'BSCE', 'is_active' => true]);
        $this->staff(User::StaffRoleAcademicHead);
        Livewire::test(ListPrograms::class)
            ->assertTableActionHidden('deleteProgram', $anotherUnused)
            ->assertTableActionHidden('deactivateProgram', $usedProgram)
            ->assertTableActionHidden('reactivateProgram', $inactiveProgram);
    }

    public function test_program_deactivation_blocks_on_open_published_admission_cycle_preserves_teach_out_records_and_supports_reactivation(): void
    {
        $this->staff(User::StaffRoleRegistrar);

        $program = Program::factory()->create([
            'code' => 'BSIT',
            'name' => 'BS Information Technology',
            'duration_years' => 4,
            'is_active' => true,
        ]);

        $activeCurriculum = CurriculumVersion::factory()->for($program)->create([
            'name' => 'BSIT 2026 Active Plan',
            'state' => CurriculumVersion::StateActive,
        ]);

        $student = StudentProfile::factory()->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $activeCurriculum->id,
            'lifecycle_status' => StudentProfile::LifecycleActive,
        ]);

        $openCycle = AdmissionCycle::factory()->published()->create([
            'code' => 'CYCLE-2026-1',
            'label' => 'AY 2026-2027 1st Semester Admissions',
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addDays(14),
            'correction_closes_at' => now()->addDays(21),
        ]);
        $openCycle->programs()->attach($program->id, [
            'accepts_first_year' => true,
            'accepts_transferee' => true,
        ]);

        AdmissionApplication::factory()->create([
            'admission_cycle_id' => $openCycle->id,
            'term_id' => $openCycle->term_id,
            'program_id' => $program->id,
            'application_state' => AdmissionApplication::StateSubmitted,
        ]);

        // (a) Open published admission cycle hard-blocks program deactivation and names the blocking cycle.
        Livewire::test(ListPrograms::class)
            ->assertTableActionVisible('deactivateProgram', $program)
            ->assertTableActionHidden('reactivateProgram', $program)
            ->callTableAction('deactivateProgram', $program)
            ->assertNotified('Program cannot be deactivated');

        $this->assertTrue($program->fresh()->is_active);

        // Close the admission cycle so the program becomes eligible for deactivation.
        $openCycle->update([
            'closes_at' => now()->subMinute(),
        ]);

        // (b) Eligible program shows live impact counts + CHED teach-out preservation and deactivates without mutating curricula or students.
        Livewire::test(ListPrograms::class)
            ->mountTableAction('deactivateProgram', $program)
            ->assertMountedActionModalSee('1 active curriculum, 1 enrolled student, and 1 open application')
            ->assertMountedActionModalSee('Deactivating stops new intakes and curriculum placements. Active curricula and enrolled students remain intact and continue normally.')
            ->callMountedAction()
            ->assertHasNoTableActionErrors()
            ->assertNotified('Program deactivated');

        $this->assertFalse($program->fresh()->is_active);
        $this->assertSame(CurriculumVersion::StateActive, $activeCurriculum->fresh()->state);
        $this->assertSame(StudentProfile::LifecycleActive, $student->fresh()->lifecycle_status);
        $this->assertSame($program->id, $student->fresh()->program_id);
        $this->assertSame($activeCurriculum->id, $student->fresh()->curriculum_version_id);

        // (c) Inactive program exposes Reactivate action and reactivates cleanly.
        Livewire::test(ListPrograms::class)
            ->assertTableActionHidden('deactivateProgram', $program->fresh())
            ->assertTableActionVisible('reactivateProgram', $program->fresh())
            ->callTableAction('reactivateProgram', $program->fresh())
            ->assertHasNoTableActionErrors()
            ->assertNotified('Program reactivated');

        $this->assertTrue($program->fresh()->is_active);
    }

    private function staff(string $roleName): User
    {
        Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $role = Role::findOrCreate($roleName, 'web');
        if ($roleName === User::StaffRoleRegistrar) {
            $role->syncPermissions([
                Permission::findOrCreate('manage-schedules', 'web'),
                Permission::findOrCreate('approve-documents', 'web'),
            ]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);
        $this->actingAs($user)->withSession([WorkspaceContextResolver::SessionKey => $roleName]);

        return $user;
    }
}
