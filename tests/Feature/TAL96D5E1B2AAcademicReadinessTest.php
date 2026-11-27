<?php

namespace Tests\Feature;

use App\Actions\AcademicSetup\CurriculumVersionLifecycleService;
use App\Actions\AcademicSetup\CurriculumWorkbenchService;
use App\Actions\Authentication\WorkspaceContextResolver;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\CatalogCurriculaWorkbench;
use App\Filament\Resources\AcademicYears\AcademicYearResource;
use App\Filament\Resources\Courses\CourseResource;
use App\Filament\Resources\Courses\Pages\ListCourses;
use App\Filament\Resources\Courses\Pages\ViewCourse;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\CurriculumVersions\Pages\ListCurriculumVersions;
use App\Filament\Resources\CurriculumVersions\Pages\ReviewCurriculumVersion;
use App\Filament\Resources\Programs\Pages\ListPrograms;
use App\Filament\Resources\Programs\Pages\ViewProgram;
use App\Filament\Resources\Programs\ProgramResource;
use App\Filament\Resources\Programs\RelationManagers\CurriculumVersionsRelationManager;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\ExternalCompetencyRequirement;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL96D5E1B2AAcademicReadinessTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame(config('database.test_database'), DB::connection()->getDatabaseName());

        foreach ([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
            User::StaffRoleAccounting,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function registrar_sidebar_exposes_one_academic_readiness_task_instead_of_peer_source_tables(): void
    {
        $this->actingAs($this->staff(User::StaffRoleRegistrar));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $items = collect(Filament::getPanel('admin')->buildNavigation())
            ->flatMap(fn ($group) => $group->getItems());

        $labels = $items
            ->map(fn ($item): string => $item->getLabel())
            ->values()
            ->all();

        $this->assertContains('Programs & Curricula', $labels);

        $catalogItem = $items->first(fn ($item): bool => $item->getLabel() === 'Programs & Curricula');
        $this->assertNotNull($catalogItem);
        $this->assertSame([
            'Programs',
            'Curricula',
            'Subject catalog',
        ], collect($catalogItem->getChildItems())->map(fn ($child): string => $child->getLabel())->values()->all());

        $this->resolveCurrentCatalogPage(ListPrograms::class);
        $expandedItems = collect($this->staffNavigation()->getNavigation())
            ->flatMap(fn ($group) => $group->getItems());
        $expandedCatalogItem = $expandedItems->first(fn ($item): bool => $item->getLabel() === 'Programs & Curricula');
        $this->assertNotNull($expandedCatalogItem);
        $childLabels = collect($expandedCatalogItem->getChildItems())
            ->map(fn ($child): string => $child->getLabel())
            ->values()
            ->all();

        $this->assertSame([
            'Programs',
            'Curricula',
            'Subject catalog',
        ], $childLabels);

        foreach (['Academic Years', 'Terms', 'Programs', 'Subject catalog', 'Course Specifications', 'Curriculum Versions', 'Import Batches'] as $sourceRecordLabel) {
            $this->assertNotContains($sourceRecordLabel, $labels);
        }
    }

    #[Test]
    public function workbench_access_is_role_appropriate_while_source_record_routes_remain_available(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $accounting = $this->staff(User::StaffRoleAccounting);

        $this->actingAs($registrar)
            ->get(CatalogCurriculaWorkbench::getUrl())
            ->assertOk()
            ->assertSee('Set up your first program')
            ->assertSee('Programs')
            ->assertSee('Curricula')
            ->assertSee('Subject catalog')
            ->assertDontSee('Academic setup order');

        foreach ([
            AcademicYearResource::getUrl('index'),
            ProgramResource::getUrl('index'),
            CourseResource::getUrl('index'),
            CurriculumVersionResource::getUrl('index'),
        ] as $url) {
            $this->actingAs($registrar)->get($url)->assertOk();
        }

        $this->actingAs($academicHead)
            ->get(CatalogCurriculaWorkbench::getUrl())
            ->assertOk()
            ->assertSee('Read-only oversight view')
            ->assertSee('No programs recorded yet')
            ->assertSee('Programs')
            ->assertDontSee('Draft curricula');

        $this->actingAs($accounting)
            ->get(CatalogCurriculaWorkbench::getUrl())
            ->assertForbidden();
    }

    #[Test]
    public function workbench_states_the_program_readiness_blocker_and_next_action_in_plain_language(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $missing = Program::factory()->create([
            'code' => 'B2MISS',
            'name' => 'Missing Curriculum Program',
            'duration_years' => 4,
        ]);
        $incomplete = Program::factory()->create([
            'code' => 'B2DRAF',
            'name' => 'Draft Curriculum Program',
            'duration_years' => 4,
        ]);
        $ready = Program::factory()->create([
            'code' => 'B2RDY',
            'name' => 'Ready Curriculum Program',
            'duration_years' => 4,
        ]);

        CurriculumVersion::factory()->for($incomplete)->create([
            'version_code' => 'DRAF-2026',
            'name' => 'Draft without rows',
            'state' => CurriculumVersion::StateDraft,
        ]);

        $readyVersion = CurriculumVersion::factory()->for($ready)->create([
            'version_code' => 'RDY-2026',
            'name' => 'Ready Curriculum',
            'state' => CurriculumVersion::StateActive,
        ]);
        $specification = $this->readySpecification();
        CurriculumEntry::factory()
            ->for($readyVersion)
            ->for($specification)
            ->create(['year_level' => '4']);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CatalogCurriculaWorkbench::class)
            ->assertOk()
            ->assertSee('Has drafts')
            ->assertSee('1 draft')
            ->assertSee('B2MISS')
            ->assertSee('B2DRAF')
            ->assertSee('B2RDY')
            ->assertDontSee('Record current approval for B2MISS')
            ->assertDontSee('Record current approval for B2RDY');

        Livewire::test(ListPrograms::class)
            ->assertCanSeeTableRecords([$missing, $incomplete, $ready])
            ->assertSee('B2MISS')
            ->assertSee('B2DRAF')
            ->assertSee('Draft in progress: Draft without rows')
            ->assertSee('B2RDY')
            ->assertSee('Ready Curriculum')
            ->assertDontSee('Current approval');

        Livewire::test(ViewProgram::class, ['record' => $ready->getRouteKey()])
            ->assertOk()
            ->assertSee('B2RDY · 4 years')
            ->assertSee('Ready Curriculum')
            ->assertDontSee('Approval periods');
    }

    #[Test]
    public function workbench_surfaces_a_pending_revision_instead_of_hiding_it_behind_the_active_curriculum(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create([
            'code' => 'B2REV',
            'name' => 'Program with Pending Revision',
        ]);
        CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'B2REV-ACTIVE',
            'state' => CurriculumVersion::StateActive,
            'created_at' => now()->subDay(),
        ]);
        CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'B2REV-DRAFT',
            'state' => CurriculumVersion::StateDraft,
            'created_at' => now(),
        ]);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $draft = $program->curriculumVersions()->where('state', CurriculumVersion::StateDraft)->first();
        $active = $program->curriculumVersions()->where('state', CurriculumVersion::StateActive)->first();

        Livewire::test(CatalogCurriculaWorkbench::class)
            ->assertOk()
            ->assertSee('Has drafts')
            ->assertSee('B2REV')
            ->assertSee('Program with Pending Revision')
            ->assertSee('1 draft')
            ->assertDontSee('Record current approval for B2REV');

        Livewire::test(ListPrograms::class)
            ->assertCanSeeTableRecords([$program])
            ->assertSee('Draft in progress:')
            ->assertSee($program->curriculumVersions()->where('state', CurriculumVersion::StateDraft)->first()->name)
            ->assertSee($program->curriculumVersions()->where('state', CurriculumVersion::StateActive)->first()->name);
    }

    #[Test]
    public function manually_created_draft_redirects_to_the_combined_curriculum_review(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create(['code' => 'B2MAN']);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::test(ListCurriculumVersions::class)
            ->callAction(TestAction::make('create')->table(), data: [
                'program_id' => $program->id,
                'name' => 'Manual Curriculum Draft',
                'effective_entry_term_id' => null,
            ])
            ->assertHasNoActionErrors();

        $curriculum = CurriculumVersion::query()
            ->where('program_id', $program->id)
            ->where('name', 'Manual Curriculum Draft')
            ->sole();

        $component->assertRedirect(
            CurriculumVersionResource::getUrl('review', [
                'record' => $curriculum,
                'programId' => $program->id,
            ]),
        );
    }

    #[Test]
    public function curriculum_review_combines_source_specification_placement_readiness_and_correction_path(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create(['code' => 'B2DBM', 'duration_years' => 4]);
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'DBM-2026',
            'name' => 'DBM Four-Year Curriculum',
            'state' => CurriculumVersion::StateDraft,
        ]);
        $incompleteSpecification = CourseSpecification::factory()->create([
            'title' => 'Business Operations',
            'credit_units' => 3,
            'allowed_modalities' => [],
            'state' => CourseSpecification::StateDraft,
        ]);
        $incompleteEntry = CurriculumEntry::factory()
            ->for($curriculum)
            ->for($incompleteSpecification)
            ->create([
                'year_level' => '2',
                'term_label' => 'Second Semester',
                'sequence' => 4,
                'requirement_group' => CurriculumEntry::RequirementGroupRequired,
            ]);
        $externalSpecification = CourseSpecification::factory()->create([
            'title' => 'Industry Internship',
            'credit_units' => 6,
            'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
            'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
            'state' => CourseSpecification::StateActive,
        ]);
        $externalEntry = CurriculumEntry::factory()
            ->for($curriculum)
            ->for($externalSpecification)
            ->create([
                'year_level' => '4',
                'term_label' => 'First Semester',
                'term_type' => Term::TypeFirstSemester,
                'sequence' => 1,
                'requirement_group' => CurriculumEntry::RequirementGroupRequired,
            ]);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $curriculum->getRouteKey()])
            ->assertSet('activeYear', 2)
            ->assertSee($incompleteSpecification->course->code)
            ->assertSee('Business Operations')
            ->assertSee('Missing teaching details')
            ->assertSee('needs teaching details.')
            ->assertSee('Year 2')
            ->assertSee('Year 4')
            ->assertSee('First Semester')
            ->assertSee('Second Semester')
            ->assertDontSee('Teaching &amp; schedule', false)
            ->assertActionDoesNotExist('editCurriculumRows')
            ->assertDontSee('TESDA & External Certifications')
            ->assertActionVisible('externalQualifications')
            ->call('selectYear', 4)
            ->assertSet('activeYear', 4)
            ->assertSee($externalSpecification->course->code)
            ->assertSee('Industry Internship')
            ->call('selectReviewStep')
            ->assertSet('activeStep', 'review')
            ->assertSee('TESDA & External Certifications')
            ->call('selectCurriculumSubject', $incompleteEntry->id)
            ->assertSet('selectedEntryId', $incompleteEntry->id)
            ->assertSet('subjectEditorOpen', true)
            ->call('closeSubjectEditor')
            ->callAction('addExternalCompetency', data: [
                'qualification_label' => 'Bookkeeping NC III',
                'qualification_level' => 'NC III',
                'treatment' => ExternalCompetencyRequirement::TreatmentTrackedOnly,
            ])
            ->assertHasNoActionErrors()
            ->mountAction('externalQualifications')
            ->assertMountedActionModalSee('Bookkeeping NC III')
            ->assertMountedActionModalSee('Result recorded only');

        $this->assertMatchesRegularExpression('/^EXT-[A-Z0-9]{6}$/', (string) $curriculum->externalCompetencyRequirements()->value('requirement_code'));
    }

    #[Test]
    public function registrar_can_add_a_curriculum_row_without_leaving_the_combined_review(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create(['duration_years' => 4]);
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'state' => CurriculumVersion::StateDraft,
        ]);
        $specification = $this->readySpecification();

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $curriculum->getRouteKey()])
            ->call('openSubjectEditor')
            ->fillForm([
                'course_specification_ids' => [$specification->id],
                'year_level' => 4,
                'term_label' => 'First Semester',
                'term_type' => Term::TypeFirstSemester,
                'sequence' => 1,
                'requirement_group' => CurriculumEntry::RequirementGroupRequired,
            ], 'subjectForm')
            ->call('saveCurriculumSubject')
            ->assertHasNoActionErrors()
            ->assertHasNoFormErrors(form: 'subjectForm')
            ->assertNotified('Curriculum saved');

        $this->assertDatabaseHas('curriculum_entries', [
            'curriculum_version_id' => $curriculum->id,
            'course_specification_id' => $specification->id,
            'year_level' => '4',
            'term_label' => 'First Semester',
            'term_type' => Term::TypeFirstSemester,
            'sequence' => 1,
            'requirement_group' => CurriculumEntry::RequirementGroupRequired,
        ]);
    }

    #[Test]
    public function registrar_can_correct_curriculum_placement_inside_the_combined_review(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $curriculum = CurriculumVersion::factory()->create([
            'state' => CurriculumVersion::StateDraft,
        ]);
        $entry = CurriculumEntry::factory()
            ->for($curriculum)
            ->for($this->readySpecification())
            ->create([
                'year_level' => '1',
                'term_label' => 'First Semester',
                'term_type' => Term::TypeFirstSemester,
                'sequence' => 1,
                'requirement_group' => CurriculumEntry::RequirementGroupRequired,
            ]);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $entryCourseCode = (string) $entry->courseSpecification->course->code;

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $curriculum->getRouteKey()])
            ->call('selectCurriculumSubject', $entry->id)
            ->fillForm([
                'year_level' => 2,
                'term_label' => 'Second Semester',
                'term_type' => Term::TypeSecondSemester,
                'confirm_placement_move' => true,
                'sequence' => 4,
                'requirement_group' => CurriculumEntry::RequirementGroupElective,
            ], 'subjectForm')
            ->call('saveCurriculumSubject')
            ->assertHasNoFormErrors(form: 'subjectForm')
            ->assertNotified("Moved {$entryCourseCode} to Year 2 · Second Semester");

        $entry->refresh();

        $this->assertSame('2', $entry->year_level);
        $this->assertSame('Second Semester', $entry->term_label);
        $this->assertSame(Term::TypeSecondSemester, $entry->term_type);
        $this->assertSame(1, $entry->sequence);
        $this->assertSame(CurriculumEntry::RequirementGroupElective, $entry->requirement_group);
    }

    #[Test]
    public function curriculum_placement_rejects_a_whitespace_only_term_label(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $curriculum = CurriculumVersion::factory()->create([
            'state' => CurriculumVersion::StateDraft,
        ]);
        $entry = CurriculumEntry::factory()
            ->for($curriculum)
            ->for($this->readySpecification())
            ->create([
                'term_label' => 'First Semester',
            ]);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        try {
            app(CurriculumWorkbenchService::class)->updatePlacement($registrar, $entry, [
                'year_level' => 1, 'term_label' => '   ', 'term_type' => Term::TypeFirstSemester,
                'sequence' => 1, 'requirement_group' => CurriculumEntry::RequirementGroupRequired,
            ]);
            $this->fail('A blank source term label must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('term_label', $exception->errors());
        }

        $this->assertSame('First Semester', $entry->refresh()->term_label);
    }

    #[Test]
    public function registrar_can_complete_a_draft_specification_inside_the_combined_review(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $curriculum = CurriculumVersion::factory()->create([
            'state' => CurriculumVersion::StateDraft,
        ]);
        $specification = CourseSpecification::factory()->create([
            'title' => 'Incomplete Business Operations',
            'credit_units' => 0,
            'grading_profile_key' => '',
            'allowed_modalities' => [],
            'state' => CourseSpecification::StateDraft,
        ]);
        $entry = CurriculumEntry::factory()
            ->for($curriculum)
            ->for($specification)
            ->create(['year_level' => '1']);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $curriculum->getRouteKey()])
            ->call('selectCurriculumSubject', $entry->id)
            ->fillForm([
                'title' => 'Business Operations',
                'credit_units' => 3,
                'grading_profile_key' => CourseSpecification::GradingProfileServitechV1,
                'grading_profile_version' => 1,
                'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
                'allowed_modalities' => [
                    CourseSpecification::ModalityFaceToFace,
                    CourseSpecification::ModalityOnline,
                ],
                'same_faculty_default' => false,
                'components' => [[
                    'component_type' => CourseComponent::TypeLecture,
                    'weekly_contact_hours' => 3,
                    'meeting_pattern' => '2x90',
                    'room_type_default' => CourseComponent::RoomTypeLectureRoom,
                    'required_room_feature_keys' => [],
                    'modality_restriction' => null,
                    'requires_consecutive_block' => false,
                    'same_faculty' => false,
                    'sequence' => 1,
                ]],
            ], 'subjectForm')
            ->call('saveCurriculumSubject')
            ->assertHasNoFormErrors(form: 'subjectForm')
            ->assertNotified('Curriculum saved');

        $specification->refresh();

        $this->assertSame('Business Operations', $specification->title);
        $this->assertSame('3.00', $specification->credit_units);
        $this->assertSame(CourseSpecification::GradingProfileServitechV1, $specification->grading_profile_key);
        $this->assertSame([
            CourseSpecification::ModalityFaceToFace,
            CourseSpecification::ModalityOnline,
        ], $specification->allowed_modalities);
        $this->assertSame(CourseSpecification::StateDraft, $specification->state);
        $this->assertDatabaseHas('course_components', [
            'course_specification_id' => $specification->id,
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 3,
            'room_type_default' => CourseComponent::RoomTypeLectureRoom,
            'sequence' => 1,
        ]);
    }

    #[Test]
    public function specification_completion_rejects_a_whitespace_only_subject_title(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $curriculum = CurriculumVersion::factory()->create([
            'state' => CurriculumVersion::StateDraft,
        ]);
        $specification = CourseSpecification::factory()->create([
            'title' => 'Original Subject Title',
            'state' => CourseSpecification::StateDraft,
        ]);
        $entry = CurriculumEntry::factory()
            ->for($curriculum)
            ->for($specification)
            ->create(['year_level' => '1']);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $curriculum->getRouteKey()])
            ->call('selectCurriculumSubject', $entry->id)
            ->fillForm([
                'title' => '   ',
                'credit_units' => 3,
                'grading_profile_key' => CourseSpecification::GradingProfileServitechV1,
                'grading_profile_version' => 1,
                'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
                'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
                'same_faculty_default' => false,
                'components' => [[
                    'component_type' => CourseComponent::TypeLecture,
                    'weekly_contact_hours' => 3,
                    'meeting_pattern' => '2x90',
                    'room_type_default' => CourseComponent::RoomTypeLectureRoom,
                    'required_room_feature_keys' => [],
                    'modality_restriction' => null,
                    'requires_consecutive_block' => false,
                    'same_faculty' => false,
                    'sequence' => 1,
                ]],
            ], 'subjectForm')
            ->call('saveCurriculumSubject')
            ->assertHasFormErrors(['title'], 'subjectForm');

        $this->assertSame('Original Subject Title', $specification->refresh()->title);
    }

    #[Test]
    public function registrar_records_approval_and_confirms_activation_from_the_combined_review(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create(['code' => 'B2ACT']);
        $intake = Term::factory()->create();
        $previous = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'B2ACT-OLD',
            'state' => CurriculumVersion::StateActive,
        ]);
        $lockedStudent = StudentProfile::factory()->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $previous->id,
        ]);
        $candidate = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'B2ACT-NEW',
            'state' => CurriculumVersion::StateDraft,
        ]);
        CurriculumEntry::factory()
            ->for($candidate)
            ->for($this->readySpecification())
            ->create();

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $previous->getRouteKey()])
            ->assertSee('Active')
            ->assertDontSee('&amp;');

        Livewire::test(ViewProgram::class, ['record' => $program->getRouteKey()])
            ->assertOk()
            ->assertDontSee('Approval periods')
            ->assertSee('Curricula')
            ->assertSee($previous->name)
            ->assertSee($candidate->name);

        $readyDraftSpec = CourseSpecification::factory()->create([
            'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
            'state' => CourseSpecification::StateDraft,
        ]);
        CourseComponent::factory()->for($readyDraftSpec)->create();
        CurriculumEntry::factory()
            ->for($candidate)
            ->for($readyDraftSpec)
            ->create(['year_level' => '2']);

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $candidate->getRouteKey()])
            ->mountAction('activateCurriculum')
            ->assertMountedActionModalSeeHtml('It replaces <strong>'.e($previous->name).'</strong> for new students.')
            ->assertMountedActionModalSee('1 student already assigned to an earlier curriculum keeps their current plan.')
            ->assertMountedActionModalSee('It also makes 1 draft subject in this plan active.')
            ->assertMountedActionModalDontSee('approval');

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $candidate->getRouteKey()])
            ->callAction('activateCurriculum', data: ['effective_entry_term_id' => $intake->id])
            ->assertHasNoActionErrors()
            ->assertNotified('Curriculum activated');

        $this->assertSame(CurriculumVersion::StateActive, $candidate->refresh()->state);
        $this->assertSame($intake->id, $candidate->effective_entry_term_id);
        $this->assertSame(CourseSpecification::StateActive, $readyDraftSpec->refresh()->state);
        $this->assertSame(CurriculumVersion::StateSuperseded, $previous->refresh()->state);
        $this->assertSame($previous->id, $lockedStudent->fresh()->curriculum_version_id);
    }

    #[Test]
    public function activation_remains_blocked_when_a_reviewed_row_has_an_incomplete_specification(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create(['code' => 'B2BLK']);
        $candidate = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'B2BLOCK',
            'state' => CurriculumVersion::StateDraft,
        ]);
        $incompleteSpecification = CourseSpecification::factory()->create([
            'credit_units' => 0,
            'allowed_modalities' => [],
            'state' => CourseSpecification::StateDraft,
        ]);
        CurriculumEntry::factory()
            ->for($candidate)
            ->for($incompleteSpecification)
            ->create();

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        try {
            app(CurriculumVersionLifecycleService::class)->activate(
                actor: $registrar,
                curriculumVersion: $candidate->refresh(),
            );
            $this->fail('Activation should remain blocked while a reviewed row is incomplete.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('curriculum_version', $exception->errors());
        }

        $this->assertSame(CurriculumVersion::StateDraft, $candidate->refresh()->state);
    }

    #[Test]
    public function academic_head_receives_the_same_curriculum_truth_without_mutation_actions(): void
    {
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $term = Term::factory()->create();
        $curriculum = CurriculumVersion::factory()->create();
        $specification = $this->readySpecification();
        $entry = CurriculumEntry::factory()
            ->for($curriculum)
            ->for($specification)
            ->create(['year_level' => '1']);

        $this->actingAs($academicHead);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ReviewCurriculumVersion::class, [
            'record' => $curriculum->getRouteKey(),
            'oversightTermId' => $term->id,
        ])
            ->assertSee($specification->course->code)
            ->assertSee($specification->title)
            ->assertActionVisible('returnToOversight')
            ->assertActionHasUrl('returnToOversight', AcademicApprovals::getUrl(['termId' => $term->id]))
            ->assertActionHidden('addCurriculumRow')
            ->assertActionHidden('planSettings')
            ->call('selectCurriculumSubject', $entry->id)
            ->assertSet('selectedEntryId', $entry->id)
            ->assertSet('subjectEditorOpen', true)
            ->assertSet('subjectMode', 'readonly')
            ->assertActionVisible('viewSubjectDetails')
            ->assertActionHidden('removeSelectedSubject')
            ->assertActionHidden('copySelectedSubject')
            ->assertActionHidden('addExternalCompetency')
            ->assertActionHidden('copyToDraft')
            ->assertActionHidden('activateCurriculum');
    }

    #[Test]
    public function retired_catalog_import_routes_are_not_offered(): void
    {
        $this->actingAs($this->staff(User::StaffRoleRegistrar))->get('/admin/import-batches')->assertNotFound();
    }

    #[Test]
    public function catalog_pillars_and_relation_managers_expose_unified_courses_program_authority_and_breadcrumbs(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $program = Program::factory()->create(['code' => 'B2PIL', 'name' => 'Pillar Verification Program']);
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'PIL-2026',
            'state' => CurriculumVersion::StateActive,
        ]);
        $specification = $this->readySpecification();
        $course = $specification->course;

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        foreach ([
            ProgramResource::getUrl('index'),
            ProgramResource::getUrl('view', ['record' => $program]),
            CourseResource::getUrl('index'),
            CourseResource::getUrl('view', ['record' => $course]),
            CurriculumVersionResource::getUrl('index'),
            CurriculumVersionResource::getUrl('review', ['record' => $curriculum]),
        ] as $url) {
            $this->get($url)->assertOk();
        }

        Livewire::test(CurriculumVersionsRelationManager::class, [
            'ownerRecord' => $program,
            'pageClass' => ViewProgram::class,
        ])->assertCanSeeTableRecords([$curriculum]);

        $this->actingAs($academicHead)
            ->get(CourseResource::getUrl('view', ['record' => $course]))
            ->assertOk()
            ->assertSee('Subject catalog');
    }

    #[Test]
    public function catalog_sidebar_navigation_exposes_five_children_badges_and_active_states_for_registrar_and_head(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $term = Term::factory()->create();

        $beforeCoursesWithDrafts = Course::query()
            ->whereHas('specifications', fn ($q) => $q->where('state', CourseSpecification::StateDraft))
            ->count();
        $beforeCurriculaPending = CurriculumVersion::query()
            ->where('state', CurriculumVersion::StateDraft)
            ->count();
        $program = Program::factory()->create(['is_active' => true, 'code' => 'NAV-PRG']);
        $specification = $this->readySpecification();
        $course = $specification->course;
        CourseSpecification::factory()->for($course)->create([
            'state' => CourseSpecification::StateDraft,
        ]);
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'state' => CurriculumVersion::StateDraft,
        ]);

        $this->actingAs($registrar);
        session()->put(WorkspaceContextResolver::SessionKey, User::StaffRoleRegistrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->resolveCurrentCatalogPage(CatalogCurriculaWorkbench::class);
        $overviewGroups = $this->staffNavigation()->getNavigation();
        $overviewCatalogItem = collect($overviewGroups)
            ->flatMap(fn ($group) => $group->getItems())
            ->first(fn (NavigationItem $item): bool => $item->getLabel() === 'Programs & Curricula');
        $this->assertNotNull($overviewCatalogItem);
        $this->assertTrue($overviewCatalogItem->isActive());
        $this->assertSame([
            'Programs',
            'Curricula',
            'Subject catalog',
        ], collect($overviewCatalogItem->getChildItems())->map(fn (NavigationItem $child): string => $child->getLabel())->values()->all());

        $registrarChecks = [
            [ViewProgram::class, [], ['record' => $program->id], 'Programs'],
            [ViewCourse::class, [], ['record' => $course->id], 'Subject catalog'],
            [ReviewCurriculumVersion::class, [], ['record' => $curriculum->id], 'Curricula'],
        ];

        foreach ($registrarChecks as [$pageClass, $query, $routeParams, $expectedActiveChild]) {
            $this->resolveCurrentCatalogPage($pageClass, $query, $routeParams);
            $groups = $this->staffNavigation()->getNavigation();
            $catalogItem = collect($groups)
                ->flatMap(fn ($group) => $group->getItems())
                ->first(fn (NavigationItem $item): bool => $item->getLabel() === 'Programs & Curricula');

            $this->assertNotNull($catalogItem);
            $this->assertTrue($catalogItem->isActive(), "Catalog & Curricula parent must stay active on {$pageClass}.");

            $children = collect($catalogItem->getChildItems());
            $this->assertSame([
                'Programs',
                'Curricula',
                'Subject catalog',
            ], $children->map(fn (NavigationItem $child): string => $child->getLabel())->values()->all());

            $byLabel = $children->keyBy(fn (NavigationItem $child): string => $child->getLabel());
            $this->assertNull($byLabel['Programs']->getBadge());
            $this->assertSame((string) ($beforeCoursesWithDrafts + 1), $byLabel['Subject catalog']->getBadge());
            $this->assertSame((string) ($beforeCurriculaPending + 1), $byLabel['Curricula']->getBadge());

            foreach ($children as $child) {
                $this->assertSame(
                    $child->getLabel() === $expectedActiveChild,
                    $child->isActive(),
                    "Registrar child {$child->getLabel()} active state mismatch on {$pageClass}.",
                );
            }
        }

        $this->actingAs($academicHead);
        session()->put(WorkspaceContextResolver::SessionKey, User::StaffRoleAcademicHead);

        $this->resolveCurrentCatalogPage(AcademicApprovals::class, ['termId' => $term->id]);
        $oversightOnApprovals = collect($this->staffNavigation()->getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->first(fn (NavigationItem $item): bool => $item->getLabel() === 'Academic Oversight');
        $this->assertNotNull($oversightOnApprovals);
        $this->assertSame([
            '1. Calendar',
            '2. Sections & classes',
            '3. Faculty & rooms',
            '4. Timetable',
        ], collect($oversightOnApprovals->getChildItems())->map(fn (NavigationItem $child): string => $child->getLabel())->values()->all());

        $this->resolveCurrentCatalogPage(CatalogCurriculaWorkbench::class, ['oversightTermId' => $term->id]);
        $oversightOnWorkbench = collect($this->staffNavigation()->getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->first(fn (NavigationItem $item): bool => $item->getLabel() === 'Academic Oversight');
        $this->assertNotNull($oversightOnWorkbench);
        $this->assertTrue($oversightOnWorkbench->isActive());
        $this->assertSame([], $oversightOnWorkbench->getChildItems());

        $headChecks = [
            [ViewProgram::class, ['oversightTermId' => $term->id], ['record' => $program->id], 'Programs'],
            [ViewCourse::class, ['oversightTermId' => $term->id], ['record' => $course->id], 'Subject catalog'],
            [ReviewCurriculumVersion::class, ['oversightTermId' => $term->id], ['record' => $curriculum->id], 'Curricula'],
        ];

        foreach ($headChecks as [$pageClass, $query, $routeParams, $expectedActiveChild]) {
            $this->resolveCurrentCatalogPage($pageClass, $query, $routeParams);
            $oversight = collect($this->staffNavigation()->getNavigation())
                ->flatMap(fn ($group) => $group->getItems())
                ->first(fn (NavigationItem $item): bool => $item->getLabel() === 'Academic Oversight');

            $this->assertNotNull($oversight);
            $this->assertTrue($oversight->isActive());
            $this->assertSame(AcademicApprovals::getUrl(['termId' => $term->id]), $oversight->getUrl());

            $children = collect($oversight->getChildItems());
            $this->assertSame([
                'Programs',
                'Curricula',
                'Subject catalog',
            ], $children->map(fn (NavigationItem $child): string => $child->getLabel())->values()->all());

            foreach ($children as $child) {
                $this->assertNull($child->getBadge(), 'Academic Head sub-items must remain read-only without Registrar action badges.');
                $this->assertStringContainsString('oversightTermId='.$term->id, (string) $child->getUrl());
                $this->assertSame(
                    $child->getLabel() === $expectedActiveChild,
                    $child->isActive(),
                    "Academic Head child {$child->getLabel()} active state mismatch on {$pageClass}.",
                );
            }
        }
    }

    #[Test]
    public function overview_directory_filters_and_academic_head_context_work_across_authority_states(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $term = Term::factory()->create();

        $activeCurrent = Program::factory()->create(['code' => 'V2DIR-CUR', 'name' => 'Current Authority Program', 'is_active' => true]);

        $activeMissing = Program::factory()->create(['code' => 'V2DIR-MIS', 'name' => 'Missing Authority Program', 'is_active' => true]);

        $activeHistorical = Program::factory()->create(['code' => 'V2DIR-HIS', 'name' => 'Historical Authority Program', 'is_active' => true]);

        $inactiveFuture = Program::factory()->create(['code' => 'V2DIR-FUT', 'name' => 'Future Authority Program', 'is_active' => false]);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListPrograms::class)
            ->searchTable('V2DIR-')
            ->assertCanSeeTableRecords([$activeCurrent, $activeMissing, $activeHistorical, $inactiveFuture])
            ->filterTable('is_active', '0')
            ->assertCanSeeTableRecords([$inactiveFuture])
            ->assertCanNotSeeTableRecords([$activeCurrent, $activeMissing, $activeHistorical]);

        $this->actingAs($academicHead);

        Livewire::test(CatalogCurriculaWorkbench::class, ['oversightTermId' => $term->id])
            ->assertOk()
            ->assertActionVisible('returnToOversight');
        Livewire::test(ListPrograms::class, ['oversightTermId' => $term->id])
            ->assertActionVisible('returnToOversight')
            ->assertActionHidden(TestAction::make('create')->table());
    }

    #[Test]
    public function view_program_and_view_course_relation_managers_enforce_registrar_actions_and_head_read_only(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);

        $program = Program::factory()->create(['code' => 'RM2-PRG', 'name' => 'Relation Manager Program']);

        $course = Course::factory()->create(['code' => 'RM2-CRS']);
        $activeSpec = CourseSpecification::factory()->for($course)->create([
            'revision_code' => 'RM2-ACT',
            'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
            'state' => CourseSpecification::StateActive,
        ]);
        CourseComponent::factory()->for($activeSpec)->create();
        $draftSpec = CourseSpecification::factory()->for($course)->create([
            'revision_code' => 'RM2-DFT',
            'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
            'state' => CourseSpecification::StateDraft,
        ]);
        CourseComponent::factory()->for($draftSpec)->create();

        $draftCurriculum = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'RM2-CUR-DFT',
            'state' => CurriculumVersion::StateDraft,
        ]);
        $approvedCurriculum = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'RM2-CUR-APP',
            'state' => CurriculumVersion::StateActive,
        ]);
        CurriculumEntry::factory()->for($approvedCurriculum)->for($activeSpec)->create();

        $this->actingAs($academicHead);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CurriculumVersionsRelationManager::class, [
            'ownerRecord' => $program,
            'pageClass' => ViewProgram::class,
        ])
            ->assertCanSeeTableRecords([$draftCurriculum, $approvedCurriculum])
            ->assertActionHidden(TestAction::make('createCurriculumDraft')->table())
            ->assertActionDoesNotExist(TestAction::make('importCurriculumCsv')->table())
            ->assertActionVisible(TestAction::make('openCurriculumSheet')->table($draftCurriculum));

        Livewire::test(ViewCourse::class, ['record' => $course->id])
            ->assertActionHidden('createRevision')
            ->assertActionHidden('editRevision')
            ->assertActionHidden('reviseSubject')
            ->assertActionHidden('activateRevision');

        $this->actingAs($registrar);

        Livewire::test(CurriculumVersionsRelationManager::class, [
            'ownerRecord' => $program,
            'pageClass' => ViewProgram::class,
        ])
            ->assertActionVisible(TestAction::make('createCurriculumDraft')->table())
            ->assertActionDoesNotExist(TestAction::make('importCurriculumCsv')->table())
            ->assertActionDoesNotExist(TestAction::make('activateCurriculum')->table($draftCurriculum));

        Livewire::test(ViewCourse::class, ['record' => $course->id])
            ->call('selectRevision', $draftSpec->id)
            ->assertActionVisible('editRevision')
            ->callAction('activateRevision')
            ->assertHasNoActionErrors();

        $this->assertSame(CourseSpecification::StateActive, $draftSpec->fresh()->state);
    }

    #[Test]
    public function grouped_curriculum_sheet_filters_curricula_table_and_explicit_breadcrumbs_work(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $term = Term::factory()->create();

        $program = Program::factory()->create(['code' => 'GRP2', 'name' => 'Grouped Table Program', 'duration_years' => 4]);

        $previousActive = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'GRP2-OLD',
            'state' => CurriculumVersion::StateActive,
        ]);
        StudentProfile::factory()->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $previousActive->id,
        ]);

        $candidate = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'GRP2-NEW',
            'name' => 'Grouped Candidate Curriculum',
            'state' => CurriculumVersion::StateDraft,
        ]);

        $firstSpec = $this->readySpecification();
        $firstSpec->course->update(['code' => 'GRP2-101']);
        $firstSpec->update(['title' => 'Foundational Systems']);
        $firstEntry = CurriculumEntry::factory()->for($candidate)->for($firstSpec)->create([
            'year_level' => '1',
            'term_label' => 'First Semester',
            'term_type' => Term::TypeFirstSemester,
            'sequence' => 1,
            'requirement_group' => CurriculumEntry::RequirementGroupRequired,
        ]);

        $fourthSpec = CourseSpecification::factory()->create([
            'title' => 'Practicum Deployment',
            'credit_units' => 6,
            'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
            'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
            'state' => CourseSpecification::StateActive,
        ]);
        $fourthSpec->course->update(['code' => 'GRP2-401']);
        $fourthEntry = CurriculumEntry::factory()->for($candidate)->for($fourthSpec)->create([
            'year_level' => '4',
            'term_label' => 'Second Semester',
            'term_type' => Term::TypeSecondSemester,
            'sequence' => 1,
            'requirement_group' => CurriculumEntry::RequirementGroupElective,
        ]);

        $incompleteCurriculum = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'GRP2-INC',
            'state' => CurriculumVersion::StateDraft,
        ]);
        $incompleteSpec = CourseSpecification::factory()->create([
            'allowed_modalities' => [],
            'state' => CourseSpecification::StateDraft,
        ]);
        CurriculumEntry::factory()->for($incompleteCurriculum)->for($incompleteSpec)->create();

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListCurriculumVersions::class)
            ->filterTable('program_id', $program->id)
            ->assertCanSeeTableRecords([$candidate, $incompleteCurriculum]);

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $candidate->getRouteKey()])
            ->assertSet('activeYear', 1)
            ->assertSee('Year 1')
            ->assertSee('Year 4')
            ->assertSee('First Semester')
            ->assertSee('Second Semester')
            ->assertSeeHtml('<span class="tala-semester-row__code">GRP2-101</span>')
            ->assertSee('Foundational Systems')
            ->assertDontSeeHtml('<span class="tala-semester-row__code">GRP2-401</span>')
            ->call('selectYear', 4)
            ->assertSet('activeYear', 4)
            ->assertSeeHtml('<span class="tala-semester-row__code">GRP2-401</span>')
            ->assertSee('Practicum Deployment')
            ->assertDontSeeHtml('<span class="tala-semester-row__code">GRP2-101</span>')
            ->call('selectYear', 1)
            ->assertSet('activeYear', 1)
            ->set('search', 'GRP2-401')
            ->assertSet('activeYear', 4)
            ->assertSeeHtml('<span class="tala-semester-row__code">GRP2-401</span>')
            ->assertDontSeeHtml('<span class="tala-semester-row__code">GRP2-101</span>')
            ->call('clearSearch')
            ->assertSet('search', '')
            ->mountAction('activateCurriculum')
            ->assertMountedActionModalSeeHtml('It replaces <strong>'.e($previousActive->name).'</strong> for new students.')
            ->assertMountedActionModalSee('1 student already assigned to an earlier curriculum keeps their current plan.')
            ->assertMountedActionModalDontSee('Supplementary approval reference');

        $this->assertSame([], Livewire::test(CatalogCurriculaWorkbench::class)->instance()->getBreadcrumbs());
        $this->assertSame([
            CatalogCurriculaWorkbench::getUrl() => 'Programs & Curricula',
            'Programs',
        ], Livewire::test(ListPrograms::class)->instance()->getBreadcrumbs());
        $this->assertSame([
            CatalogCurriculaWorkbench::getUrl() => 'Programs & Curricula',
            ProgramResource::getUrl('index') => 'Programs',
            $program->code,
        ], Livewire::test(ViewProgram::class, ['record' => $program->getRouteKey()])->instance()->getBreadcrumbs());
        $this->assertSame([
            CatalogCurriculaWorkbench::getUrl() => 'Programs & Curricula',
            'Subject catalog',
        ], Livewire::test(ListCourses::class)->instance()->getBreadcrumbs());
        $this->assertSame([
            CatalogCurriculaWorkbench::getUrl() => 'Programs & Curricula',
            CourseResource::getUrl('index') => 'Subject catalog',
            $firstSpec->course->code,
        ], Livewire::test(ViewCourse::class, ['record' => $firstSpec->course->getRouteKey()])->instance()->getBreadcrumbs());
        $this->assertSame([
            CatalogCurriculaWorkbench::getUrl() => 'Programs & Curricula',
            'Curricula',
        ], Livewire::test(ListCurriculumVersions::class)->instance()->getBreadcrumbs());
        $this->assertSame([
            CatalogCurriculaWorkbench::getUrl() => 'Programs & Curricula',
            CurriculumVersionResource::getUrl('index') => 'Curricula',
            ProgramResource::getUrl('view', ['record' => $program]) => $program->code,
            $candidate->name,
        ], Livewire::test(ReviewCurriculumVersion::class, ['record' => $candidate->getRouteKey()])->instance()->getBreadcrumbs());

        $this->actingAs($academicHead);

        Livewire::test(ListPrograms::class, ['oversightTermId' => $term->id])
            ->searchTable($program->code)
            ->assertSee('oversightTermId='.$term->id);

        Livewire::test(ViewCourse::class, [
            'record' => $firstSpec->course->getRouteKey(),
            'curriculumVersionId' => $candidate->id,
            'programId' => $program->id,
            'oversightTermId' => $term->id,
        ])
            ->assertActionVisible('returnToCurriculumSheet')
            ->assertActionHasUrl('returnToCurriculumSheet', CurriculumVersionResource::getUrl('review', [
                'record' => $candidate->id,
                'programId' => $program->id,
                'oversightTermId' => $term->id,
            ]))
            ->assertActionVisible('returnToOversight')
            ->assertSee($firstSpec->title);
    }

    private function readySpecification(): CourseSpecification
    {
        $specification = CourseSpecification::factory()->create([
            'allowed_modalities' => [
                CourseSpecification::ModalityFaceToFace,
                CourseSpecification::ModalityOnline,
            ],
            'state' => CourseSpecification::StateActive,
        ]);

        CourseComponent::factory()->for($specification)->create();

        return $specification;
    }

    /**
     * @param  array<string, bool|int|string>  $query
     * @param  array<string, int|string>  $routeParameters
     */
    private function resolveCurrentCatalogPage(string $page, array $query = [], array $routeParameters = []): void
    {
        $route = new Route(['GET'], '/admin/catalog-page', fn (): null => null);
        $route->name($page::getRouteName());
        $request = Request::create('http://localhost/admin/catalog-page', 'GET', $query);
        $route->bind($request);

        foreach ($routeParameters as $name => $value) {
            $route->setParameter($name, $value);
        }

        $request->setRouteResolver(fn (): Route => $route);
        $this->app->instance('originalRequest', $request);
    }

    private function staffNavigation(): NavigationBuilder
    {
        $method = new \ReflectionMethod(AdminPanelProvider::class, 'staffNavigation');

        return $method->invoke(new AdminPanelProvider($this->app), new NavigationBuilder);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);
        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $user->saveAppAuthenticationRecoveryCodes(['stored-code']);

        return $user;
    }
}
