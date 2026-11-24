<?php

namespace Tests\Feature;

use App\Actions\AcademicSetup\CurriculumWorkbenchService;
use App\Filament\Resources\Courses\CourseResource;
use App\Filament\Resources\Courses\Pages\ListCourses;
use App\Filament\Resources\Courses\Pages\ViewCourse;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\CurriculumVersions\Pages\ListCurriculumVersions;
use App\Filament\Resources\CurriculumVersions\Pages\ReviewCurriculumVersion;
use App\Filament\Resources\Programs\Pages\ListPrograms;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL82BCurriculumCourseCatalogAcceptanceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

        foreach ([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
            User::StaffRoleAccounting,
            User::StaffRoleFaculty,
            User::StaffRoleSystemSuperAdmin,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function clean_course_catalog_resources_are_registered_without_reactivating_legacy_catalog_resources(): void
    {
        $resources = Filament::getPanel('admin')->getResources();

        $this->assertContains(CourseResource::class, $resources);
        $this->assertContains(CurriculumVersionResource::class, $resources);
        $this->assertNotContains('App\Filament\Resources\CourseSpecifications\CourseSpecificationResource', $resources);
        $this->assertNotContains('App\Filament\Resources\Subjects\SubjectResource', $resources);
        $this->assertNotContains('App\Filament\Resources\Curriculums\CurriculumResource', $resources);
    }

    /**
     * @param  list<class-string>  $visibleResources
     * @param  list<class-string>  $hiddenResources
     */
    #[DataProvider('resourceAccessBoundaries')]
    public function test_resource_access_follows_registrar_manage_and_academic_head_view_boundary(
        string $role,
        bool $canCreate,
        array $visibleResources,
        array $hiddenResources,
    ): void {
        $this->actingAs($this->staff($role));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        foreach ($visibleResources as $resource) {
            $this->assertTrue($resource::canAccess(), "{$role} should access {$resource}.");
            $this->assertSame($canCreate, $resource::canCreate(), "{$role} create boundary mismatch for {$resource}.");
        }

        foreach ($hiddenResources as $resource) {
            $this->assertFalse($resource::canAccess(), "{$role} should not access {$resource}.");
        }
    }

    #[Test]
    public function academic_head_can_view_but_cannot_mutate_clean_course_catalog_records(): void
    {
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $course = Course::factory()->create();
        $specification = CourseSpecification::factory()->create(['course_id' => $course->id]);
        $curriculumVersion = CurriculumVersion::factory()->create();

        $this->actingAs($academicHead);

        $this->assertTrue($academicHead->can('view', $course));
        $this->assertTrue($academicHead->can('view', $specification));
        $this->assertTrue($academicHead->can('view', $curriculumVersion));
        $this->assertFalse($academicHead->can('create', Course::class));
        $this->assertFalse($academicHead->can('update', $course));
        $this->assertFalse($academicHead->can('create', CourseSpecification::class));
        $this->assertFalse($academicHead->can('update', $specification));
        $this->assertFalse($academicHead->can('create', CurriculumVersion::class));
        $this->assertFalse($academicHead->can('update', $curriculumVersion));
    }

    #[Test]
    public function registrar_can_create_clean_course_specification_and_curriculum_version_records(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $prerequisite = Course::factory()->create(['code' => 'IT100']);
        $term = Term::factory()
            ->for(AcademicYear::factory()->create(['label' => '2026-2027']))
            ->create(['label' => 'First Semester']);

        Livewire::test(ListCourses::class)
            ->callAction('create', data: [
                ...$this->subjectInput(),
                'code' => 'IT101',
                'title' => 'Introduction to Computing',
                'prerequisite_ids' => [$prerequisite->id],
            ])
            ->assertHasNoActionErrors();

        $course = Course::query()->where('code', 'IT101')->firstOrFail();
        $specification = CourseSpecification::query()
            ->where('course_id', $course->id)
            ->firstOrFail();

        $this->assertDatabaseHas('course_components', [
            'course_specification_id' => $specification->id,
            'component_type' => CourseComponent::TypeLecture,
        ]);
        $this->assertDatabaseHas('course_requirements', [
            'course_specification_id' => $specification->id,
            'rule_type' => CourseRequirement::TypePrerequisite,
            'related_course_id' => $prerequisite->id,
            'state' => CourseRequirement::StateActive,
        ]);

        $program = Program::factory()->create(['code' => 'BSIT', 'name' => 'Bachelor of Science in Information Technology']);

        $createCurriculum = Livewire::test(ListCurriculumVersions::class)
            ->callAction('create', data: [
                'program_id' => $program->id,
                'name' => 'BSIT 2026 Curriculum',
                'effective_entry_term_id' => $term->id,
            ])
            ->assertHasNoActionErrors();

        $curriculumVersion = CurriculumVersion::query()
            ->where('program_id', $program->id)
            ->where('name', 'BSIT 2026 Curriculum')
            ->firstOrFail();

        $createCurriculum->assertRedirect(
            CurriculumVersionResource::getUrl('review', [
                'record' => $curriculumVersion,
                'programId' => $program->id,
            ]),
        );

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $curriculumVersion->getRouteKey()])
            ->call('openSubjectEditor')
            ->fillForm([
                'course_specification_ids' => [$specification->id],
                'year_level' => 1,
                'term_label' => 'First Semester',
                'term_type' => Term::TypeFirstSemester,
                'sequence' => 1,
                'requirement_group' => CurriculumEntry::RequirementGroupRequired,
            ], 'subjectForm')
            ->call('saveCurriculumSubject')
            ->assertHasNoFormErrors(form: 'subjectForm');

        $this->assertDatabaseHas('curriculum_entries', [
            'curriculum_version_id' => $curriculumVersion->id,
            'course_specification_id' => $specification->id,
            'year_level' => '1',
            'term_label' => 'First Semester',
            'term_type' => Term::TypeFirstSemester,
            'requirement_group' => CurriculumEntry::RequirementGroupRequired,
        ]);
    }

    #[Test]
    public function subject_and_placement_are_created_together_with_derived_order_and_draft_state(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $curriculum = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateDraft]);
        $entry = app(CurriculumWorkbenchService::class)->createSubjectEntry($registrar, $curriculum, $this->subjectInput());
        $this->assertSame('First Semester', $entry->term_label);
        $this->assertSame(1, $entry->sequence);
        $this->assertSame(CourseSpecification::StateDraft, $entry->courseSpecification->state);
        $this->assertSame(CurriculumVersion::StateDraft, $curriculum->fresh()->state);
        $this->assertDatabaseHas('course_components', ['course_specification_id' => $entry->course_specification_id, 'meeting_pattern' => '3x60']);
    }

    #[Test]
    public function failed_placement_rolls_back_new_subject_and_its_definition(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $curriculum = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateDraft]);
        $before = CourseSpecification::query()->count();
        try {
            app(CurriculumWorkbenchService::class)->createSubjectEntry($registrar, $curriculum, [...$this->subjectInput(), 'year_level' => 999]);
            $this->fail('Invalid placement must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('year_level', $exception->errors());
        }
        $this->assertDatabaseMissing('courses', ['code' => 'NEW101']);
        $this->assertSame($before, CourseSpecification::query()->count());
        $this->assertSame(0, $curriculum->entries()->count());
    }

    #[Test]
    public function read_only_users_and_active_curricula_cannot_create_subjects_through_the_inspector(): void
    {
        $service = app(CurriculumWorkbenchService::class);
        $draft = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateDraft]);
        $active = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateActive]);
        foreach ([[$this->staff(User::StaffRoleAcademicHead), $draft], [$this->staff(User::StaffRoleRegistrar), $active]] as [$actor, $curriculum]) {
            try {
                $service->createSubjectEntry($actor, $curriculum, $this->subjectInput());
                $this->fail('Read-only context must reject creation.');
            } catch (AuthorizationException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
        $this->assertDatabaseMissing('courses', ['code' => 'NEW101']);
    }

    /** @return array<string, mixed> */
    #[Test]
    public function existing_subjects_are_added_together_and_duplicate_placement_rolls_back_the_whole_selection(): void
    {
        $this->actingAs($this->staff(User::StaffRoleRegistrar));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $curriculum = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateDraft]);
        $subjects = CourseSpecification::factory()->count(2)->create(['state' => CourseSpecification::StateActive]);
        $form = ['course_specification_ids' => $subjects->modelKeys(), 'year_level' => 1,
            'term_type' => Term::TypeFirstSemester, 'requirement_group' => CurriculumEntry::RequirementGroupRequired];
        Livewire::test(ReviewCurriculumVersion::class, ['record' => $curriculum->getRouteKey()])
            ->fillForm($form, 'subjectForm')->call('saveCurriculumSubject')
            ->assertHasNoFormErrors(form: 'subjectForm');
        $this->assertSame(2, $curriculum->entries()->count());
        $third = CourseSpecification::factory()->create(['state' => CourseSpecification::StateActive]);
        Livewire::test(ReviewCurriculumVersion::class, ['record' => $curriculum->getRouteKey()])
            ->fillForm([...$form, 'course_specification_ids' => [$third->id, $subjects[0]->id]], 'subjectForm')
            ->call('saveCurriculumSubject')->assertHasFormErrors(['term_type'], form: 'subjectForm');
        $this->assertSame(2, $curriculum->entries()->count());
        $this->assertFalse($curriculum->entries()->where('course_specification_id', $third->id)->exists());
    }

    #[Test]
    public function new_subject_repeater_saves_all_rows_and_rejects_duplicate_codes_without_partial_records(): void
    {
        $this->actingAs($this->staff(User::StaffRoleRegistrar));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $curriculum = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateDraft]);
        $placement = ['year_level' => 1, 'term_type' => Term::TypeFirstSemester, 'requirement_group' => CurriculumEntry::RequirementGroupRequired];
        Livewire::test(ReviewCurriculumVersion::class, ['record' => $curriculum->getRouteKey()])
            ->call('openSubjectEditor', 'new')
            ->fillForm([...$placement, 'new_subjects' => [$this->subjectInput(), [...$this->subjectInput(), 'code' => 'NEW102']]], 'subjectForm')
            ->call('saveCurriculumSubject')->assertHasNoFormErrors(form: 'subjectForm');
        $this->assertSame(2, $curriculum->entries()->count());
        Livewire::test(ReviewCurriculumVersion::class, ['record' => $curriculum->getRouteKey()])
            ->call('openSubjectEditor', 'new')
            ->fillForm([...$placement, 'new_subjects' => [[...$this->subjectInput(), 'code' => 'ROLL101'], [...$this->subjectInput(), 'code' => 'roll101']]], 'subjectForm')
            ->call('saveCurriculumSubject')->assertHasErrors();
        $this->assertDatabaseMissing('courses', ['code' => 'ROLL101']);
        $this->assertSame(2, $curriculum->entries()->count());
    }

    #[Test]
    public function switching_editor_modes_rebuilds_the_native_fields_and_subject_details_are_read_only(): void
    {
        $this->actingAs($this->staff(User::StaffRoleRegistrar));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $curriculum = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateDraft]);
        $specification = CourseSpecification::factory()->create(['state' => CourseSpecification::StateActive]);
        CourseComponent::factory()->for($specification)->create();
        $entry = CurriculumEntry::factory()->for($curriculum)->for($specification)->create(['year_level' => '1']);
        $component = Livewire::test(ReviewCurriculumVersion::class, ['record' => $curriculum->getRouteKey()])
            ->assertSet('subjectEditorOpen', false)
            ->call('openSubjectEditor')
            ->assertSet('subjectEditorOpen', true)
            ->assertFormFieldExists('course_specification_ids', form: 'subjectForm')
            ->call('openSubjectEditor', 'new')
            ->assertFormFieldExists('new_subjects', form: 'subjectForm')
            ->assertFormFieldDoesNotExist('course_specification_ids', form: 'subjectForm');
        $firstNewRow = collect($component->get('subjectData.new_subjects'))->first();
        $this->assertSame(CourseSpecification::GradingProfileServitechV1, $firstNewRow['grading_profile_key'] ?? null);
        $this->assertSame(CourseSpecification::SchedulingRecurring, $firstNewRow['scheduling_treatment'] ?? null);
        $this->assertSame(1, collect($firstNewRow['components'] ?? [])->first()['sequence'] ?? null);
        $component->call('selectCurriculumSubject', $entry->id)
            ->assertSet('subjectMode', 'edit')
            ->assertFormFieldDoesNotExist('new_subjects', form: 'subjectForm')
            ->assertFormFieldDoesNotExist('course_specification_ids', form: 'subjectForm')
            ->mountAction('viewSubjectDetails')->assertActionMounted('viewSubjectDetails');
        $this->assertTrue($component->instance()->getMountedAction()->getRecord()->is($specification));
        $component->unmountAction()
            ->set('subjectData.title', 'Curriculum-specific draft title')
            ->call('saveCurriculumSubject')
            ->assertHasNoErrors();
        $adoptedDraftId = $entry->fresh()->course_specification_id;
        $this->assertNotSame($specification->id, $adoptedDraftId);
        $this->assertSame(CourseSpecification::StateDraft, CourseSpecification::query()->findOrFail($adoptedDraftId)->state);
        $this->assertSame('Curriculum-specific draft title', CourseSpecification::query()->findOrFail($adoptedDraftId)->title);
        $component->assertSet('subjectEditorOpen', false);
        $this->assertSame(CourseSpecification::StateActive, $specification->fresh()->state);
    }

    #[Test]
    public function native_creation_modals_share_the_program_subject_and_draft_curriculum_workflow(): void
    {
        $this->actingAs($this->staff(User::StaffRoleRegistrar));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListPrograms::class)->callAction('create', data: [
            'code' => 'MODAL-P', 'name' => 'Modal Program', 'duration_years' => 4, 'is_active' => true,
        ])->assertHasNoActionErrors();
        $program = Program::query()->where('code', 'MODAL-P')->firstOrFail();
        Livewire::test(ListCourses::class)->callAction('create', data: [...$this->subjectInput(), 'code' => 'MODAL101'])
            ->assertHasNoActionErrors();
        $course = Course::query()->where('code', 'MODAL101')->firstOrFail();
        $this->assertSame(CourseSpecification::StateDraft, $course->specifications()->firstOrFail()->state);
        Livewire::test(ListCurriculumVersions::class)->callAction('create', data: [
            'program_id' => $program->id, 'version_code' => 'MODAL-C', 'name' => 'Modal Curriculum',
            'state' => CurriculumVersion::StateActive,
        ])->assertHasNoActionErrors();
        $curriculum = CurriculumVersion::query()->where('name', 'Modal Curriculum')->firstOrFail();
        $this->assertSame(CurriculumVersion::StateDraft, $curriculum->state);
        $this->assertStringStartsWith($program->code.'-', $curriculum->version_code);
        $this->assertNotSame('MODAL-C', $curriculum->version_code);
        $this->assertSame($program->id, $curriculum->program_id);
    }

    #[Test]
    public function registrar_can_create_another_program_without_reopening_the_modal(): void
    {
        $this->actingAs($this->staff(User::StaffRoleRegistrar));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::test(ListPrograms::class)
            ->callAction('create', data: ['code' => 'FIRST', 'name' => 'First Program', 'is_active' => true], arguments: ['another' => true])
            ->assertHasNoActionErrors()
            ->assertActionMounted('create')
            ->assertNoRedirect();
        $component->fillForm(['code' => 'SECOND', 'name' => 'Second Program', 'is_active' => true])
            ->callMountedAction()
            ->assertHasNoActionErrors();
        $this->assertDatabaseHas('programs', ['code' => 'FIRST', 'name' => 'First Program']);
        $this->assertDatabaseHas('programs', ['code' => 'SECOND', 'name' => 'Second Program']);
    }

    #[Test]
    public function creation_modals_do_not_allow_academic_head_writes(): void
    {
        $this->actingAs($this->staff(User::StaffRoleAcademicHead));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach ([ListPrograms::class, ListCourses::class, ListCurriculumVersions::class] as $page) {
            Livewire::test($page)->assertActionHidden('create');
        }
    }

    #[Test]
    public function registrar_revises_and_edits_a_subject_in_place_without_changing_the_active_definition(): void
    {
        $this->actingAs($this->staff(User::StaffRoleRegistrar));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $source = CourseSpecification::factory()->create(['state' => CourseSpecification::StateActive, 'title' => 'Existing title']);
        CourseComponent::factory()->for($source)->create();
        $component = Livewire::test(ViewCourse::class, ['record' => $source->course_id])
            ->assertActionHidden('editRevision')
            ->callAction('reviseSubject')
            ->assertHasNoActionErrors()
            ->assertActionMounted('editRevision')
            ->assertNoRedirect();
        $draft = $source->course->specifications()->where('state', CourseSpecification::StateDraft)->sole();
        $component->assertSet('selectedRevisionId', $draft->id)
            ->fillForm(['title' => 'Updated draft title'])
            ->callMountedAction()
            ->assertHasNoActionErrors()->assertNoRedirect();
        $this->assertSame('Updated draft title', $draft->fresh()->title);
        $this->assertSame('Existing title', $source->fresh()->title);
        $this->assertSame(CourseSpecification::StateActive, $source->fresh()->state);
        $this->assertSame(1, $draft->components()->count());
    }

    #[Test]
    public function native_subject_revision_creation_keeps_the_subject_and_draft_state_server_owned(): void
    {
        $this->actingAs($this->staff(User::StaffRoleRegistrar));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $course = Course::factory()->create();
        $foreign = Course::factory()->create();
        Livewire::test(ViewCourse::class, ['record' => $course->id])
            ->callAction('createRevision', data: [
                'course_id' => $foreign->id, 'state' => CourseSpecification::StateActive,
                'title' => 'Contextual teaching details', 'credit_units' => 3,
                'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
                'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
                'allowed_modalities' => [CourseSpecification::ModalityFaceToFace], 'same_faculty_default' => false,
            ])->assertHasNoActionErrors()->assertNoRedirect();
        $revision = $course->specifications()->sole();
        $this->assertSame(CourseSpecification::StateDraft, $revision->state);
        $this->assertSame('Contextual teaching details', $revision->title);
        $this->assertSame(0, $foreign->specifications()->count());
    }

    #[Test]
    public function revision_selection_is_scoped_to_the_subject_and_head_cannot_create_or_edit(): void
    {
        $source = CourseSpecification::factory()->create(['state' => CourseSpecification::StateDraft]);
        $foreign = CourseSpecification::factory()->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->staff(User::StaffRoleAcademicHead));
        Livewire::test(ViewCourse::class, ['record' => $source->course_id])
            ->assertActionHidden('createRevision')->assertActionHidden('editRevision')
            ->assertActionHidden('reviseSubject')->assertActionHidden('activateRevision')
            ->call('selectRevision', $foreign->id)->assertStatus(404);
    }

    #[Test]
    public function draft_edit_rechecks_immutability_before_saving_related_teaching_details(): void
    {
        $this->actingAs($this->staff(User::StaffRoleRegistrar));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $draft = CourseSpecification::factory()->create(['state' => CourseSpecification::StateDraft, 'title' => 'Original draft']);
        $meeting = CourseComponent::factory()->for($draft)->create(['weekly_contact_hours' => 3]);
        $component = Livewire::test(ViewCourse::class, ['record' => $draft->course_id])->mountAction('editRevision');
        $draft->update(['state' => CourseSpecification::StateActive]);
        $component->fillForm(['title' => 'Stale overwrite'])->callMountedAction();
        $this->assertSame('Original draft', $draft->fresh()->title);
        $this->assertSame(3.0, (float) $meeting->fresh()->weekly_contact_hours);
        $this->assertSame(CourseSpecification::StateActive, $draft->fresh()->state);
    }

    #[Test]
    public function contextual_revision_creation_links_open_the_subject_modal_and_keep_head_read_only(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $course = Course::factory()->create();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);

        $this->actingAs($registrar);
        Livewire::test(ViewCourse::class, ['record' => $course->id])
            ->assertActionVisible('createRevision')
            ->assertActionHidden('editRevision')
            ->assertActionHidden('reviseSubject');

        $revision = CourseSpecification::factory()->for($course)->create(['state' => CourseSpecification::StateDraft]);
        Livewire::test(ViewCourse::class, ['record' => $course->id])
            ->assertActionHidden('createRevision')
            ->assertActionVisible('editRevision');

        $revision->update(['state' => CourseSpecification::StateActive]);
        Livewire::test(ViewCourse::class, ['record' => $course->id])
            ->assertActionVisible('reviseSubject');

        $this->actingAs($academicHead);
        Livewire::test(ViewCourse::class, ['record' => $course->id])
            ->assertActionHidden('createRevision')
            ->assertActionHidden('editRevision')
            ->assertActionHidden('reviseSubject');
    }

    #[Test]
    public function revising_an_active_curriculum_keeps_the_source_and_returns_to_the_new_draft_in_context(): void
    {
        $this->actingAs($this->staff(User::StaffRoleRegistrar));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $source = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateActive]);
        $entry = CurriculumEntry::factory()->for($source)->create();
        $term = Term::factory()->create();

        $page = Livewire::test(ReviewCurriculumVersion::class, ['record' => $source->id, 'oversightTermId' => $term->id])
            ->callAction('copyToDraft', data: ['name' => 'Revised study plan', 'effective_entry_term_id' => $term->id])
            ->assertHasNoActionErrors();

        $draft = CurriculumVersion::query()->where('program_id', $source->program_id)->where('name', 'Revised study plan')->sole();
        $this->assertSame(CurriculumVersion::StateDraft, $draft->state);
        $this->assertSame($term->id, $draft->effective_entry_term_id);
        $this->assertSame($entry->course_specification_id, $draft->entries()->sole()->course_specification_id);
        $this->assertSame(CurriculumVersion::StateActive, $source->fresh()->state);
        $this->assertSame(1, $source->entries()->count());
        $page->assertRedirect(CurriculumVersionResource::getUrl('review', [
            'record' => $draft, 'programId' => $source->program_id, 'oversightTermId' => $term->id,
        ]));
    }

    #[Test]
    public function registrar_can_reorder_semester_subjects_via_livewire_sort_in_draft_curriculum(): void
    {
        $this->actingAs($this->staff(User::StaffRoleRegistrar));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $draft = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateDraft]);
        $first = CurriculumEntry::factory()->for($draft)->create([
            'year_level' => '1',
            'term_label' => 'First Semester',
            'term_type' => Term::TypeFirstSemester,
            'sequence' => 1,
        ]);
        $second = CurriculumEntry::factory()->for($draft)->create([
            'year_level' => '1',
            'term_label' => 'First Semester',
            'term_type' => Term::TypeFirstSemester,
            'sequence' => 2,
        ]);
        $third = CurriculumEntry::factory()->for($draft)->create([
            'year_level' => '1',
            'term_label' => 'First Semester',
            'term_type' => Term::TypeFirstSemester,
            'sequence' => 3,
        ]);

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $draft->id])
            ->assertSeeHtml('wire:sort="sortSemesterEntry"')
            ->assertSeeHtml('wire:sort:handle')
            ->call('sortSemesterEntry', $third->id, 0);

        $this->assertSame(1, $third->fresh()->sequence);
        $this->assertSame(2, $first->fresh()->sequence);
        $this->assertSame(3, $second->fresh()->sequence);
    }

    private function subjectInput(): array
    {
        return [
            'code' => 'NEW101', 'title' => 'New subject', 'credit_units' => 3,
            'grading_profile_key' => CourseSpecification::GradingProfileServitechV1, 'grading_profile_version' => 1,
            'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
            'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
            'allowed_modalities' => [CourseSpecification::ModalityFaceToFace], 'same_faculty_default' => false,
            'components' => [['component_type' => CourseComponent::TypeLecture, 'weekly_contact_hours' => 3, 'meeting_pattern' => '3x60',
                'room_type_default' => null, 'required_room_feature_keys' => [], 'modality_restriction' => null,
                'requires_consecutive_block' => false, 'same_faculty' => false, 'sequence' => 1]],
            'prerequisite_ids' => [], 'corequisite_ids' => [], 'equivalent_ids' => [],
            'year_level' => 1, 'term_type' => Term::TypeFirstSemester, 'requirement_group' => CurriculumEntry::RequirementGroupRequired,
        ];
    }

    /**
     * @return array<string, array{role: string, canCreate: bool, visibleResources: list<class-string>, hiddenResources: list<class-string>}>
     */
    public static function resourceAccessBoundaries(): array
    {
        $catalogResources = [
            CourseResource::class,
            CurriculumVersionResource::class,
        ];

        return [
            'registrar manages clean catalog resources' => [
                'role' => User::StaffRoleRegistrar,
                'canCreate' => true,
                'visibleResources' => $catalogResources,
                'hiddenResources' => [],
            ],
            'academic head views clean catalog resources only' => [
                'role' => User::StaffRoleAcademicHead,
                'canCreate' => false,
                'visibleResources' => $catalogResources,
                'hiddenResources' => [],
            ],
            'accounting cannot access academic catalog resources' => [
                'role' => User::StaffRoleAccounting,
                'canCreate' => false,
                'visibleResources' => [],
                'hiddenResources' => $catalogResources,
            ],
            'faculty cannot access registrar-owned academic catalog resources' => [
                'role' => User::StaffRoleFaculty,
                'canCreate' => false,
                'visibleResources' => [],
                'hiddenResources' => $catalogResources,
            ],
            'system super admin remains outside operational catalog resources' => [
                'role' => User::StaffRoleSystemSuperAdmin,
                'canCreate' => false,
                'visibleResources' => [],
                'hiddenResources' => $catalogResources,
            ],
        ];
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
