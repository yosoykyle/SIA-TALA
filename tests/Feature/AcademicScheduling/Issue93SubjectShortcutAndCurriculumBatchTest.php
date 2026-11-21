<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\AcademicSetup\CurriculumVersionLifecycleService;
use App\Actions\AcademicSetup\CurriculumWorkbenchService;
use App\Actions\Authentication\WorkspaceContextResolver;
use App\Filament\Resources\Courses\CourseResource;
use App\Filament\Resources\Courses\Pages\ListCourses;
use App\Filament\Resources\Courses\Pages\ViewCourse;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\CurriculumVersions\Pages\ListCurriculumVersions;
use App\Filament\Resources\CurriculumVersions\Pages\ReviewCurriculumVersion;
use App\Filament\Resources\Programs\Pages\ViewProgram;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class Issue93SubjectShortcutAndCurriculumBatchTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_subject_creation_assigns_to_all_active_program_drafts_without_mutating_active_or_inactive_curricula(): void
    {
        $this->staff(User::StaffRoleRegistrar);

        $bsit = Program::factory()->create(['code' => 'BSIT', 'name' => 'BS Information Technology', 'duration_years' => 4, 'is_active' => true]);
        $bscs = Program::factory()->create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'duration_years' => 4, 'is_active' => true]);
        $bsba = Program::factory()->create(['code' => 'BSBA', 'name' => 'BS Business Administration', 'duration_years' => 4, 'is_active' => true]);
        $bsed = Program::factory()->create(['code' => 'BSED', 'name' => 'BS Education', 'duration_years' => 4, 'is_active' => false]);

        $bsitDraft = CurriculumVersion::factory()->for($bsit)->create(['state' => CurriculumVersion::StateDraft, 'name' => 'BSIT 2026 Draft']);
        $bscsDraft = CurriculumVersion::factory()->for($bscs)->create(['state' => CurriculumVersion::StateDraft, 'name' => 'BSCS 2026 Draft']);
        $bsbaActive = CurriculumVersion::factory()->for($bsba)->create(['state' => CurriculumVersion::StateActive, 'name' => 'BSBA 2025 Active']);
        $bsedDraft = CurriculumVersion::factory()->for($bsed)->create(['state' => CurriculumVersion::StateDraft, 'name' => 'BSED Archived Draft']);

        Livewire::test(ListCourses::class)
            ->callAction('create', data: [
                ...$this->subjectPayload('GE101', 'Understanding the Self'),
                'apply_to' => 'all_active',
                'placement_year_level' => 1,
                'placement_term_type' => Term::TypeFirstSemester,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Subject created and added to 2 curricula');

        $course = Course::query()->where('code', 'GE101')->sole();
        $spec = $course->specifications()->sole();

        $this->assertDatabaseHas('curriculum_entries', [
            'curriculum_version_id' => $bsitDraft->id,
            'course_specification_id' => $spec->id,
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
        ]);
        $this->assertDatabaseHas('curriculum_entries', [
            'curriculum_version_id' => $bscsDraft->id,
            'course_specification_id' => $spec->id,
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
        ]);
        $this->assertDatabaseMissing('curriculum_entries', [
            'curriculum_version_id' => $bsbaActive->id,
            'course_specification_id' => $spec->id,
        ]);
        $this->assertDatabaseMissing('curriculum_entries', [
            'curriculum_version_id' => $bsedDraft->id,
            'course_specification_id' => $spec->id,
        ]);
    }

    public function test_subject_creation_supports_selected_programs_and_defaults_to_do_not_assign(): void
    {
        $this->staff(User::StaffRoleRegistrar);

        $bsit = Program::factory()->create(['code' => 'BSIT', 'duration_years' => 4, 'is_active' => true]);
        $bscs = Program::factory()->create(['code' => 'BSCS', 'duration_years' => 4, 'is_active' => true]);

        $bsitDraft = CurriculumVersion::factory()->for($bsit)->create(['state' => CurriculumVersion::StateDraft]);
        $bscsDraft = CurriculumVersion::factory()->for($bscs)->create(['state' => CurriculumVersion::StateDraft]);

        // Default ("Do not assign yet") leaves curricula untouched.
        Livewire::test(ListCourses::class)
            ->callAction('create', data: $this->subjectPayload('CAT100', 'Catalog Only Subject'))
            ->assertHasNoActionErrors()
            ->assertNotified('Draft subject created');

        $this->assertSame(0, $bsitDraft->entries()->count());
        $this->assertSame(0, $bscsDraft->entries()->count());

        // Selected programs places only into the selected program's draft.
        Livewire::test(ListCourses::class)
            ->callAction('create', data: [
                ...$this->subjectPayload('IT202', 'Systems Integration'),
                'apply_to' => 'selected',
                'program_ids' => [$bsit->id],
                'placement_year_level' => 2,
                'placement_term_type' => Term::TypeSecondSemester,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Subject created and added to 1 curriculum');

        $itSpec = Course::query()->where('code', 'IT202')->sole()->specifications()->sole();

        $this->assertDatabaseHas('curriculum_entries', [
            'curriculum_version_id' => $bsitDraft->id,
            'course_specification_id' => $itSpec->id,
            'year_level' => '2',
            'term_type' => Term::TypeSecondSemester,
        ]);
        $this->assertDatabaseMissing('curriculum_entries', [
            'curriculum_version_id' => $bscsDraft->id,
            'course_specification_id' => $itSpec->id,
        ]);
    }

    public function test_courses_table_shows_all_programs_badge_and_year_and_term_summary_without_duplicate_entries(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $bsit = Program::factory()->create(['code' => 'BSIT', 'duration_years' => 4, 'is_active' => true]);
        $bscs = Program::factory()->create(['code' => 'BSCS', 'duration_years' => 4, 'is_active' => true]);

        $bsitDraft = CurriculumVersion::factory()->for($bsit)->create(['state' => CurriculumVersion::StateDraft]);
        $bscsDraft = CurriculumVersion::factory()->for($bscs)->create(['state' => CurriculumVersion::StateDraft]);

        // Subject placed across every active program at Yr 1 · 1st Sem.
        $genEd = app(CurriculumWorkbenchService::class)->createDraftSubjectAndAssignToCurricula($registrar, [
            ...$this->subjectPayload('GE102', 'Purposive Communication'),
            'apply_to' => 'all_active',
            'placement_year_level' => 1,
            'placement_term_type' => Term::TypeFirstSemester,
        ]);
        $this->assertSame(2, $genEd['added_curricula']);

        // Subject placed in only BSIT at Yr 2 · 2nd Sem.
        app(CurriculumWorkbenchService::class)->createDraftSubjectAndAssignToCurricula($registrar, [
            ...$this->subjectPayload('IT204', 'Database Administration'),
            'apply_to' => 'selected',
            'program_ids' => [$bsit->id],
            'placement_year_level' => 2,
            'placement_term_type' => Term::TypeSecondSemester,
        ]);

        // Subject placed across multiple year/term combinations.
        $multiCourse = Course::factory()->create(['code' => 'IT305']);
        $multiSpec = CourseSpecification::factory()->for($multiCourse)->create([
            'title' => 'Capstone Seminar',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);
        CurriculumEntry::factory()->for($bsitDraft)->for($multiSpec, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
            'term_label' => 'First Semester',
        ]);
        CurriculumEntry::factory()->for($bscsDraft)->for($multiSpec, 'courseSpecification')->create([
            'year_level' => '3',
            'term_type' => Term::TypeSpecialTerm,
            'term_label' => 'Special Term',
        ]);

        // Guard: calling createEntry manually or verifying existing placement doesn't duplicate across repeated calls.
        $this->assertSame(1, $bsitDraft->entries()->where('course_specification_id', $genEd['specification']->id)->count());

        Livewire::test(ListCourses::class)
            ->assertSee('All programs')
            ->assertSee('Year 1 · 1st Sem')
            ->assertSee('Year 2 · 2nd Sem')
            ->assertDontSee('Yr 1 · 1st Sem')
            ->assertSee('Multiple');
    }

    public function test_curriculum_versions_table_shows_student_counts_semantic_badges_and_direct_duplicate_action(): void
    {
        $this->staff(User::StaffRoleRegistrar);

        $program = Program::factory()->create(['code' => 'BSIT', 'name' => 'BS Information Technology', 'duration_years' => 4]);
        $intake = Term::factory()->for(AcademicYear::factory()->create(['label' => '2026-2027']))->create(['label' => 'First Semester']);

        $supersededUlid = (string) Str::ulid();
        $activeUlid = (string) Str::ulid();

        $oldCurriculum = CurriculumVersion::factory()->for($program)->create([
            'name' => 'BSIT Curriculum — Batch 2022',
            'version_code' => $supersededUlid,
            'state' => CurriculumVersion::StateSuperseded,
        ]);
        $activeCurriculum = CurriculumVersion::factory()->for($program)->create([
            'name' => 'BSIT Curriculum — Batch 2025',
            'version_code' => $activeUlid,
            'state' => CurriculumVersion::StateActive,
            'effective_entry_term_id' => $intake->id,
        ]);
        $draftCurriculum = CurriculumVersion::factory()->for($program)->create([
            'name' => 'BSIT Curriculum — Batch 2026 Draft',
            'state' => CurriculumVersion::StateDraft,
        ]);

        $spec = CourseSpecification::factory()->create(['state' => CourseSpecification::StateActive]);
        CurriculumEntry::factory()->for($activeCurriculum)->for($spec, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
        ]);

        StudentProfile::factory()->count(3)->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $oldCurriculum->id,
        ]);
        StudentProfile::factory()->count(2)->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $activeCurriculum->id,
        ]);

        Livewire::test(ListCurriculumVersions::class)
            ->assertSee('BSIT Curriculum — Batch 2022')
            ->assertSee('BSIT Curriculum — Batch 2025')
            ->assertSee('BSIT Curriculum — Batch 2026 Draft')
            ->assertSee('3 students')
            ->assertSee('2 students')
            ->assertSee('0 students')
            ->assertSee('Active')
            ->assertSee('Draft')
            ->assertSee('Inactive')
            ->assertDontSee($supersededUlid)
            ->assertDontSee($activeUlid)
            ->assertTableActionVisible('duplicate', $activeCurriculum)
            ->mountAction('create')
            ->assertMountedActionModalSeeHtml('placeholder="e.g. BSIT Curriculum — Batch 2026"');

        $duplicateCall = Livewire::test(ListCurriculumVersions::class)
            ->callTableAction('duplicate', $activeCurriculum, data: [
                'name' => 'BSIT Curriculum — Batch 2027',
                'effective_entry_term_id' => $intake->id,
            ])
            ->assertHasNoTableActionErrors();

        $copiedDraft = CurriculumVersion::query()
            ->where('program_id', $program->id)
            ->where('name', 'BSIT Curriculum — Batch 2027')
            ->sole();

        $this->assertSame(CurriculumVersion::StateDraft, $copiedDraft->state);
        $this->assertSame(1, $copiedDraft->entries()->count());
        $this->assertSame(2, $activeCurriculum->studentProfiles()->count());

        $duplicateCall->assertRedirect(
            CurriculumVersionResource::getUrl('review', [
                'record' => $copiedDraft,
                'programId' => $program->id,
            ]),
        );

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $activeCurriculum->getRouteKey()])
            ->assertSee('Duplicate curriculum');

        // Read-only Academic Head cannot duplicate curricula.
        $this->staff(User::StaffRoleAcademicHead);
        Livewire::test(ListCurriculumVersions::class)
            ->assertTableActionHidden('duplicate', $activeCurriculum);
    }

    public function test_subject_creation_and_assignment_support_custom_year_and_sem_per_program_without_duplication(): void
    {
        $this->staff(User::StaffRoleRegistrar);

        $bsit = Program::factory()->create(['code' => 'BSIT', 'name' => 'BS Information Technology', 'duration_years' => 4, 'is_active' => true]);
        $dbm = Program::factory()->create(['code' => 'DBM', 'name' => 'Diploma in Business Management', 'duration_years' => 3, 'is_active' => true]);

        $bsitDraft = CurriculumVersion::factory()->for($bsit)->create(['state' => CurriculumVersion::StateDraft, 'name' => 'BSIT 2026 Draft']);
        $dbmDraft = CurriculumVersion::factory()->for($dbm)->create(['state' => CurriculumVersion::StateDraft, 'name' => 'DBM 2026 Draft']);

        // Create ONE single RIZAL subject and place it into BSIT Year 3 · 2nd Sem AND DBM Year 2 · 1st Sem in 1 screen.
        Livewire::test(ListCourses::class)
            ->callAction('create', data: [
                ...$this->subjectPayload('RIZAL', 'Life and Works of Rizal'),
                'apply_to' => 'custom',
                'placements' => [
                    [
                        'program_id' => $bsit->id,
                        'year_level' => 3,
                        'term_type' => Term::TypeSecondSemester,
                    ],
                    [
                        'program_id' => $dbm->id,
                        'year_level' => 2,
                        'term_type' => Term::TypeFirstSemester,
                    ],
                ],
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Subject created and added to 2 curricula');

        $course = Course::query()->where('code', 'RIZAL')->sole();
        $spec = $course->specifications()->sole();

        $this->assertDatabaseHas('curriculum_entries', [
            'curriculum_version_id' => $bsitDraft->id,
            'course_specification_id' => $spec->id,
            'year_level' => '3',
            'term_type' => Term::TypeSecondSemester,
        ]);
        $this->assertDatabaseHas('curriculum_entries', [
            'curriculum_version_id' => $dbmDraft->id,
            'course_specification_id' => $spec->id,
            'year_level' => '2',
            'term_type' => Term::TypeFirstSemester,
        ]);

        // Existing subject can also be assigned across multiple programs with custom year/term via table action.
        $peCourse = Course::factory()->create(['code' => 'PE1']);
        $peSpec = CourseSpecification::factory()->for($peCourse)->create([
            'title' => 'Physical Education 1',
            'credit_units' => 2,
            'state' => CourseSpecification::StateActive,
        ]);

        Livewire::test(ListCourses::class)
            ->callTableAction('assignToCurricula', $peCourse, data: [
                'apply_to' => 'custom',
                'placements' => [
                    [
                        'program_id' => $bsit->id,
                        'year_level' => 1,
                        'term_type' => Term::TypeFirstSemester,
                    ],
                    [
                        'program_id' => $dbm->id,
                        'year_level' => 1,
                        'term_type' => Term::TypeSecondSemester,
                    ],
                ],
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Subject added to 2 curricula');

        $this->assertDatabaseHas('curriculum_entries', [
            'curriculum_version_id' => $bsitDraft->id,
            'course_specification_id' => $peSpec->id,
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
        ]);
        $this->assertDatabaseHas('curriculum_entries', [
            'curriculum_version_id' => $dbmDraft->id,
            'course_specification_id' => $peSpec->id,
            'year_level' => '1',
            'term_type' => Term::TypeSecondSemester,
        ]);
    }

    public function test_program_active_prospectus_and_review_curriculum_render_year_tabs_and_semester_total_units(): void
    {
        $this->staff(User::StaffRoleRegistrar);

        $program = Program::factory()->create(['code' => 'BSIT', 'name' => 'BS Information Technology', 'duration_years' => 4]);
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'name' => 'BSIT Prospectus 2026',
            'state' => CurriculumVersion::StateActive,
        ]);

        $it101 = Course::factory()->create(['code' => 'IT 101']);
        $it101Spec = CourseSpecification::factory()->for($it101)->create([
            'title' => 'Introduction to Computing',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);

        $ge101 = Course::factory()->create(['code' => 'GE 101']);
        $ge101Spec = CourseSpecification::factory()->for($ge101)->create([
            'title' => 'Understanding the Self',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);

        $it102 = Course::factory()->create(['code' => 'IT 102']);
        $it102Spec = CourseSpecification::factory()->for($it102)->create([
            'title' => 'Computer Programming 1',
            'credit_units' => 4,
            'state' => CourseSpecification::StateActive,
        ]);
        CourseRequirement::factory()->create([
            'course_specification_id' => $it102Spec->id,
            'related_course_id' => $it101->id,
            'rule_type' => CourseRequirement::TypePrerequisite,
            'state' => CourseRequirement::StateActive,
        ]);

        CurriculumEntry::factory()->for($curriculum)->for($it101Spec, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
            'sequence' => 1,
        ]);
        CurriculumEntry::factory()->for($curriculum)->for($ge101Spec, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
            'sequence' => 2,
        ]);
        CurriculumEntry::factory()->for($curriculum)->for($it102Spec, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeSecondSemester,
            'sequence' => 1,
        ]);

        // ViewProgram renders instant Alpine year tabs (without redundant '6u'/'0u' badges) and open semester prospectus tables with Total units footer rows.
        Livewire::test(ViewProgram::class, ['record' => $program->id])
            ->assertSee('All years')
            ->assertSee('1st Year')
            ->assertSee('2nd Year')
            ->assertSee('3rd Year')
            ->assertSee('4th Year')
            ->assertDontSee('6u')
            ->assertDontSee('0u')
            ->assertSee('First Semester')
            ->assertSee('Second Semester')
            ->assertSee('Subject code')
            ->assertSee('Prerequisites')
            ->assertSee('IT 101')
            ->assertSee('GE 101')
            ->assertSee('IT 102')
            ->assertSee('Total')
            ->assertSee('6 units')
            ->assertSee('4 units')
            ->assertSeeHtml('x-data="{ activeYearTab:');

        // ReviewCurriculumVersion also renders the bold Total units footer inside open semester cards.
        Livewire::test(ReviewCurriculumVersion::class, ['record' => $curriculum->getRouteKey()])
            ->assertSee('First Semester')
            ->assertSee('Second Semester')
            ->assertSee('Total')
            ->assertSee('6 units')
            ->assertSee('4 units');
    }

    public function test_curricula_table_displays_terms_and_intake_and_safely_discards_only_unused_drafts(): void
    {
        $this->staff(User::StaffRoleRegistrar);

        $program = Program::factory()->create(['code' => 'BSIT', 'duration_years' => 4]);
        $academicYear = AcademicYear::factory()->create(['label' => '2026-2027']);
        $usedTerm = Term::factory()->for($academicYear)->create(['label' => '1st Semester 2026–2027']);
        $intakeTerm = Term::factory()->for($academicYear)->create(['label' => '2nd Semester 2026–2027']);

        $activeWithOffering = CurriculumVersion::factory()->for($program)->create([
            'name' => 'BSIT Active With Term',
            'state' => CurriculumVersion::StateActive,
        ]);
        $spec = CourseSpecification::factory()->create(['state' => CourseSpecification::StateActive]);
        $activeEntry = CurriculumEntry::factory()->for($activeWithOffering)->for($spec, 'courseSpecification')->create();
        TermOffering::factory()->for($usedTerm)->for($activeEntry, 'curriculumEntry')->create();

        $draftWithIntake = CurriculumVersion::factory()->for($program)->create([
            'name' => 'BSIT Draft With Intake',
            'state' => CurriculumVersion::StateDraft,
            'effective_entry_term_id' => $intakeTerm->id,
        ]);

        $unusedDraft = CurriculumVersion::factory()->for($program)->create([
            'name' => 'BSIT Disposable Draft',
            'state' => CurriculumVersion::StateDraft,
        ]);
        $unusedEntry = CurriculumEntry::factory()->for($unusedDraft)->for($spec, 'courseSpecification')->create();

        $draftWithStudents = CurriculumVersion::factory()->for($program)->create([
            'name' => 'BSIT Referenced Draft',
            'state' => CurriculumVersion::StateDraft,
        ]);
        StudentProfile::factory()->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $draftWithStudents->id,
        ]);

        Livewire::test(ListCurriculumVersions::class)
            ->assertSee('1st Semester 2026–2027')
            ->assertSee('First intake:')
            ->assertSee('2nd Semester 2026–2027')
            ->assertSee('Not used in a term yet')
            ->assertTableActionHidden('discardDraft', $activeWithOffering)
            ->assertTableActionHidden('discardDraft', $draftWithStudents)
            ->assertTableActionVisible('discardDraft', $unusedDraft)
            ->callTableAction('discardDraft', $unusedDraft)
            ->assertHasNoTableActionErrors()
            ->assertNotified('Draft curriculum discarded');

        $this->assertDatabaseMissing('curriculum_versions', ['id' => $unusedDraft->id]);
        $this->assertDatabaseMissing('curriculum_entries', ['id' => $unusedEntry->id]);

        // Discard draft also works from ReviewCurriculumVersion and redirects to Curricula index.
        Livewire::test(ReviewCurriculumVersion::class, ['record' => $draftWithIntake->getRouteKey()])
            ->assertActionVisible('discardDraft')
            ->callAction('discardDraft')
            ->assertHasNoActionErrors()
            ->assertNotified('Draft curriculum discarded')
            ->assertRedirect(CurriculumVersionResource::getUrl('index'));

        $this->assertDatabaseMissing('curriculum_versions', ['id' => $draftWithIntake->id]);

        // Academic Head cannot discard drafts.
        $this->staff(User::StaffRoleAcademicHead);
        $headCheckDraft = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateDraft]);
        Livewire::test(ListCurriculumVersions::class)
            ->assertTableActionHidden('discardDraft', $headCheckDraft);
    }

    public function test_curriculum_readiness_errors_hide_revision_codes_and_use_plain_registrar_copy(): void
    {
        $this->staff(User::StaffRoleRegistrar);

        $program = Program::factory()->create(['code' => 'BSIT']);
        $draft = CurriculumVersion::factory()->for($program)->create([
            'name' => 'BSIT Plain Errors Draft',
            'state' => CurriculumVersion::StateDraft,
        ]);

        $course = Course::factory()->create(['code' => 'IT 102']);
        $spec = CourseSpecification::factory()->for($course)->create([
            'title' => 'Computer Programming 1',
            'revision_code' => 'REV-2026-11',
            'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
            'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
            'state' => CourseSpecification::StateActive,
        ]);
        CurriculumEntry::factory()->for($draft)->for($spec, 'courseSpecification')->create();

        $errors = app(CurriculumVersionLifecycleService::class)->readinessErrors($draft);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('IT 102 · Computer Programming 1', $errors[0]);
        $this->assertStringContainsString('Missing weekly class meetings (Lecture or Lab).', $errors[0]);
        $this->assertStringNotContainsString('REV-2026-11', $errors[0]);
        $this->assertStringNotContainsString('Course Component', $errors[0]);

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $draft->getRouteKey()])
            ->mountAction('activateCurriculum')
            ->assertMountedActionModalSee('IT 102 · Computer Programming 1')
            ->assertMountedActionModalSee('Missing weekly class meetings (Lecture or Lab).')
            ->assertMountedActionModalDontSee('REV-2026-11');
    }

    public function test_courses_table_allows_deleting_unused_subjects_and_protects_used_subjects(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $unusedCourse = Course::factory()->create(['code' => 'TEMP 101']);
        $unusedSpec = CourseSpecification::factory()->for($unusedCourse)->create([
            'title' => 'Temporary Subject',
            'state' => CourseSpecification::StateDraft,
        ]);
        $unusedComponent = CourseComponent::factory()->for($unusedSpec)->create();

        $usedCourse = Course::factory()->create(['code' => 'IT 101']);
        $usedSpec = CourseSpecification::factory()->for($usedCourse)->create([
            'title' => 'Introduction to Computing',
            'state' => CourseSpecification::StateActive,
        ]);
        $curriculum = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateDraft]);
        CurriculumEntry::factory()->for($curriculum)->for($usedSpec, 'courseSpecification')->create();

        Livewire::test(ListCourses::class)
            ->assertTableActionVisible('delete', $unusedCourse)
            ->assertTableActionHidden('delete', $usedCourse)
            ->callTableAction('delete', $unusedCourse)
            ->assertHasNoTableActionErrors()
            ->assertNotified('Subject deleted');

        $this->assertDatabaseMissing('courses', ['id' => $unusedCourse->id]);
        $this->assertDatabaseMissing('course_specifications', ['id' => $unusedSpec->id]);
        $this->assertDatabaseMissing('course_components', ['id' => $unusedComponent->id]);
        $this->assertDatabaseHas('courses', ['id' => $usedCourse->id]);

        // Academic Head cannot delete unused subjects.
        $stillUnused = Course::factory()->create(['code' => 'TEMP 102']);
        CourseSpecification::factory()->for($stillUnused)->create(['state' => CourseSpecification::StateDraft]);

        $this->staff(User::StaffRoleAcademicHead);
        Livewire::test(ListCourses::class)
            ->assertTableActionHidden('delete', $stillUnused);

        // Service guard rejects deleting a used subject even if invoked directly.
        $this->actingAs($registrar);
        $this->assertFalse(CourseResource::canDeleteUnusedSubject($usedCourse));
    }

    public function test_subject_form_uses_selectable_cards_plain_labels_and_conditional_constraints(): void
    {
        $this->staff(User::StaffRoleRegistrar);

        $course = Course::factory()->create(['code' => 'IT 101']);
        CourseSpecification::factory()->for($course)->create([
            'title' => 'Introduction to Computing',
            'state' => CourseSpecification::StateActive,
        ]);

        // Create Subject modal has clear Section cards, plain Registrar labels, no Consecutive block, and selectable option cards.
        $createModal = Livewire::test(ListCourses::class)
            ->mountAction('create')
            ->assertMountedActionModalSee('Subject basics')
            ->assertMountedActionModalSee('Weekly classes & teaching')
            ->assertMountedActionModalSee('Prerequisites & equivalents (optional)')
            ->assertMountedActionModalSee('Add to curricula (optional)')
            ->assertMountedActionModalSee('Subject type')
            ->assertMountedActionModalSee('Regular subject')
            ->assertMountedActionModalSee('Schedule type')
            ->assertMountedActionModalSee('Weekly scheduled class')
            ->assertMountedActionModalSee('Arranged / flexible schedule')
            ->assertMountedActionModalSee('Weekly sessions')
            ->assertMountedActionModalSee('2 sessions/week · 1.5 hrs each (3 hrs total)')
            ->assertMountedActionModalSeeHtml('tala-choice-options')
            ->assertMountedActionModalDontSee('Ordinary academic course')
            ->assertMountedActionModalDontSee('Recurring master-timetable meetings')
            ->assertMountedActionModalDontSee('Room and teaching constraints')
            ->assertMountedActionModalDontSee('Consecutive block')
            ->assertMountedActionModalDontSee('Same teacher for Lecture & Lab')
            ->assertMountedActionModalDontSee('Meeting delivery mode');

        // Selecting both Face-to-Face and Online reveals Meeting delivery mode; adding a 2nd meeting reveals Same teacher for Lecture & Lab.
        $components = $createModal->get('mountedActions.0.data.components');
        $components['second-item'] = [
            'component_type' => CourseComponent::TypeLaboratory,
            'room_type_default' => CourseComponent::RoomTypeLaboratory,
            'meeting_pattern' => '1x180',
            'weekly_contact_hours' => 3,
            'required_room_feature_keys' => [],
            'modality_restriction' => null,
            'requires_consecutive_block' => false,
            'same_faculty' => false,
            'sequence' => 2,
        ];

        $createModal
            ->set('mountedActions.0.data.allowed_modalities', [
                CourseSpecification::ModalityFaceToFace,
                CourseSpecification::ModalityOnline,
            ])
            ->set('mountedActions.0.data.components', $components)
            ->assertMountedActionModalSee('Same teacher for Lecture & Lab')
            ->assertMountedActionModalSee('Keep one teacher assigned to both the Lecture and Lab meetings of a section')
            ->assertMountedActionModalSee('Meeting delivery mode');

        // Assign to curricula modal also renders selectable option cards (.tala-choice-options).
        Livewire::test(ListCourses::class)
            ->mountTableAction('assignToCurricula', $course)
            ->assertMountedActionModalSeeHtml('tala-choice-options')
            ->assertMountedActionModalSee('All active programs (same year & term)')
            ->assertMountedActionModalSee('Custom per program');
    }

    public function test_view_course_updates_code_and_status_clarifies_actions_and_renders_structured_detail_view(): void
    {
        $this->staff(User::StaffRoleRegistrar);

        $program = Program::factory()->create(['code' => 'BSIT', 'name' => 'BS Information Technology']);
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'name' => 'BSIT 2026 Curriculum',
            'state' => CurriculumVersion::StateActive,
        ]);

        $prereq = Course::factory()->create(['code' => 'IT 100']);
        CourseSpecification::factory()->for($prereq)->create([
            'title' => 'Computing Fundamentals',
            'state' => CourseSpecification::StateActive,
        ]);

        $other = Course::factory()->create(['code' => 'IT 999']);

        $course = Course::factory()->create(['code' => 'IT 201', 'state' => Course::StateActive]);
        $activeSpec = CourseSpecification::factory()->for($course)->create([
            'title' => 'Object-Oriented Programming',
            'credit_units' => 3,
            'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
            'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
            'state' => CourseSpecification::StateActive,
        ]);
        CourseComponent::factory()->for($activeSpec)->create([
            'component_type' => CourseComponent::TypeLecture,
            'meeting_pattern' => '2x90',
            'weekly_contact_hours' => 3,
            'room_type_default' => CourseComponent::RoomTypeLectureRoom,
        ]);
        CourseRequirement::factory()->create([
            'course_specification_id' => $activeSpec->id,
            'related_course_id' => $prereq->id,
            'rule_type' => CourseRequirement::TypePrerequisite,
            'state' => CourseRequirement::StateActive,
        ]);
        CurriculumEntry::factory()->for($curriculum)->for($activeSpec, 'courseSpecification')->create([
            'year_level' => 'First Year',
            'term_type' => Term::TypeFirstSemester,
            'term_label' => 'First Semester',
        ]);

        // Active subject view shows clear header actions, 4-cell summary strip, prerequisite title, and structured placement table.
        $view = Livewire::test(ViewCourse::class, ['record' => $course->id])
            ->assertSee('Edit teaching details')
            ->assertSee('Update code & status')
            ->assertDontSee('Prepare update')
            ->assertDontSee('Edit code and status')
            ->assertSee('Subject overview')
            ->assertSee('Weekly meetings')
            ->assertSee('Room requirement')
            ->assertSee('Delivery modes')
            ->assertSee('Prerequisites & equivalents')
            ->assertSee('IT 100')
            ->assertSee('Computing Fundamentals')
            ->assertSee('Used in programs & curricula')
            ->assertSee('1st Year · First Semester')
            ->assertSee('Open curriculum');

        // Rejects duplicate code when updating code & status.
        $view->callAction('editCourseDetails', data: [
            'code' => 'IT 999',
            'state' => Course::StateActive,
        ])->assertHasActionErrors(['code']);
        $this->assertSame('IT 201', $course->fresh()->code);

        // Updates code & status cleanly and shows toast.
        $view->fillForm([
            'code' => 'it 201a',
            'state' => Course::StateRetired,
        ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Subject code & status updated');

        $this->assertSame('IT 201A', $course->fresh()->code);
        $this->assertSame(Course::StateRetired, $course->fresh()->state);

        // Creating a draft switches header button label to "Edit subject".
        $view->callAction('reviseSubject')
            ->assertHasNoActionErrors()
            ->assertSee('Edit subject');
    }

    public function test_review_curriculum_version_inspector_fixes_year_zero_and_exposes_draft_edit_move_replace_remove(): void
    {
        $this->staff(User::StaffRoleRegistrar);

        $program = Program::factory()->create(['code' => 'BSIT', 'duration_years' => 4]);
        $activeCurriculum = CurriculumVersion::factory()->for($program)->create([
            'name' => 'BSIT Active Plan',
            'state' => CurriculumVersion::StateActive,
        ]);
        $draftCurriculum = CurriculumVersion::factory()->for($program)->create([
            'name' => 'BSIT Draft Plan',
            'state' => CurriculumVersion::StateDraft,
        ]);

        $course1 = Course::factory()->create(['code' => 'IT 101']);
        $spec1 = CourseSpecification::factory()->for($course1)->create([
            'title' => 'Introduction to Computing',
            'state' => CourseSpecification::StateActive,
        ]);
        $course2 = Course::factory()->create(['code' => 'IT 102']);
        $spec2 = CourseSpecification::factory()->for($course2)->create([
            'title' => 'Computer Programming 1',
            'state' => CourseSpecification::StateActive,
        ]);
        $course3 = Course::factory()->create(['code' => 'GE 101']);
        $spec3 = CourseSpecification::factory()->for($course3)->create([
            'title' => 'Purposive Communication',
            'state' => CourseSpecification::StateActive,
        ]);

        // Entry stored with 'First Year' string must NOT render as "Year 0" in the inspector.
        $activeEntry = CurriculumEntry::factory()->for($activeCurriculum)->for($spec1, 'courseSpecification')->create([
            'year_level' => 'First Year',
            'term_type' => Term::TypeFirstSemester,
            'term_label' => 'First Semester',
        ]);

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $activeCurriculum->getRouteKey()])
            ->call('selectCurriculumSubject', $activeEntry->id)
            ->assertSee('1st Year · First Semester')
            ->assertDontSee('Year 0')
            ->assertSee('Type')
            ->assertSee('Weekly schedule')
            ->assertSee('Open subject')
            ->assertDontSee('Open in Subjects');

        // Draft curriculum inspector exposes Edit, Move, Replace, and Remove actions cleanly.
        $draftEntry1 = CurriculumEntry::factory()->for($draftCurriculum)->for($spec1, 'courseSpecification')->create([
            'year_level' => 'First Year',
            'term_type' => Term::TypeFirstSemester,
            'term_label' => 'First Semester',
            'sequence' => 1,
        ]);
        $draftEntry2 = CurriculumEntry::factory()->for($draftCurriculum)->for($spec2, 'courseSpecification')->create([
            'year_level' => 'First Year',
            'term_type' => Term::TypeFirstSemester,
            'term_label' => 'First Semester',
            'sequence' => 2,
        ]);

        $draftPage = Livewire::test(ReviewCurriculumVersion::class, ['record' => $draftCurriculum->getRouteKey()])
            ->assertSeeHtml('tala-curriculum-heading')
            ->assertDontSee('First intake not set')
            ->call('selectCurriculumSubject', $draftEntry1->id)
            ->assertSee('Edit teaching details')
            ->assertSee('Open subject')
            ->assertSee('Replace subject')
            ->assertSee('Remove from plan')
            ->assertDontSee('Move up')
            ->assertDontSee('Move down')
            ->assertFormSet(['year_level' => 1], form: 'subjectForm');

        // Drag reorder handler reorders sequence within the semester.
        $draftPage->call('sortSemesterEntry', $draftEntry1->id, 1);
        $this->assertSame(2, $draftEntry1->fresh()->sequence);
        $this->assertSame(1, $draftEntry2->fresh()->sequence);

        // Replace subject swaps the course specification cleanly.
        $draftPage->callAction('replaceSelectedSubject', data: [
            'course_specification_id' => $spec3->id,
        ])
            ->assertHasNoActionErrors()
            ->assertNotified('Subject replaced in curriculum');

        $this->assertSame($spec3->id, $draftEntry1->fresh()->course_specification_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function subjectPayload(string $code, string $title): array
    {
        return [
            'code' => $code,
            'title' => $title,
            'credit_units' => 3,
            'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
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
            'prerequisite_ids' => [],
            'corequisite_ids' => [],
            'equivalent_ids' => [],
        ];
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
