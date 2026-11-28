<?php

namespace Tests\Feature;

use App\Actions\AcademicSetup\CurriculumVersionLifecycleService;
use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Enrollment\PrepareStandardRegistration;
use App\Actions\Scheduling\PrepareTermClassProposal;
use App\Filament\Pages\SchoolResources;
use App\Filament\Pages\TermClasses;
use App\Filament\Pages\TermResources;
use App\Filament\Resources\CurriculumVersions\Pages\ReviewCurriculumVersion;
use App\Filament\Resources\Programs\Tables\ProgramsTable;
use App\Models\AcademicYear;
use App\Models\CandidateScheduleRow;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\CurriculumVersionBatch;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingCommitment;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class Issue93DefenseSectionBatchAndCrudTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_add_student_section_resolves_program_year_intake_batch_scopes_demand_and_displays_single_batch_in_tables(): void
    {
        $this->actingAsRegistrar();

        $ay2024 = AcademicYear::factory()->create(['label' => 'AY 2024-2025', 'starts_on' => '2024-08-01', 'ends_on' => '2025-05-31', 'state' => AcademicYear::StateArchived, 'is_operational' => false]);
        $ay2025 = AcademicYear::factory()->create(['label' => 'AY 2025-2026', 'starts_on' => '2025-08-01', 'ends_on' => '2026-05-31', 'state' => AcademicYear::StateArchived, 'is_operational' => false]);
        $ay2026 = AcademicYear::factory()->create(['label' => 'AY 2026-2027', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'state' => AcademicYear::StateActive, 'is_operational' => true]);

        $term = Term::factory()->for($ay2026)->create([
            'type' => Term::TypeFirstSemester,
            'label' => 'First Semester',
            'starts_on' => '2026-08-17',
            'ends_on' => '2026-12-18',
            'state' => Term::StateActive,
        ]);
        TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);

        $dbm = Program::factory()->create(['code' => 'DBM', 'name' => 'Diploma in Business Management', 'duration_years' => 3, 'is_active' => true]);
        $otherProgram = Program::factory()->create(['code' => 'DIT', 'name' => 'Diploma in Information Technology', 'duration_years' => 3, 'is_active' => true]);

        $sharedCurriculum = CurriculumVersion::factory()->for($dbm)->create([
            'name' => 'DBM Standard Curriculum',
            'state' => CurriculumVersion::StateActive,
        ]);
        CurriculumVersionBatch::query()->create(['program_id' => $dbm->id, 'curriculum_version_id' => $sharedCurriculum->id, 'academic_year_id' => $ay2024->id]);
        CurriculumVersionBatch::query()->create(['program_id' => $dbm->id, 'curriculum_version_id' => $sharedCurriculum->id, 'academic_year_id' => $ay2025->id]);
        CurriculumVersionBatch::query()->create(['program_id' => $dbm->id, 'curriculum_version_id' => $sharedCurriculum->id, 'academic_year_id' => $ay2026->id]);

        $otherCurriculum = CurriculumVersion::factory()->for($otherProgram)->create(['name' => 'DIT Standard', 'state' => CurriculumVersion::StateActive]);

        $this->createCurriculumSubject($sharedCurriculum, 'DBM-201', 'Business Operations', 2, $term->type);
        $this->createCurriculumSubject($sharedCurriculum, 'DBM-301', 'Strategic Capstone', 3, $term->type);

        // 4 continuing students in Batch 2024-25 (Year 3), 7 in Batch 2025-26 (Year 2), plus 5 unrelated DIT students.
        StudentProfile::factory()->count(4)->create([
            'program_id' => $dbm->id,
            'curriculum_version_id' => $sharedCurriculum->id,
            'entry_academic_year_id' => $ay2024->id,
            'lifecycle_status' => StudentProfile::LifecycleActive,
        ]);
        StudentProfile::factory()->count(7)->create([
            'program_id' => $dbm->id,
            'curriculum_version_id' => $sharedCurriculum->id,
            'entry_academic_year_id' => $ay2025->id,
            'lifecycle_status' => StudentProfile::LifecycleActive,
        ]);
        StudentProfile::factory()->count(5)->create([
            'program_id' => $otherProgram->id,
            'curriculum_version_id' => $otherCurriculum->id,
            'entry_academic_year_id' => $ay2025->id,
            'lifecycle_status' => StudentProfile::LifecycleActive,
        ]);

        // 1. Add student section resolves Program + Year 2 -> Batch 2025-26 + scoped continuing demand (7, not 11 or 16!).
        $component = Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])
            ->test(TermClasses::class)
            ->mountAction('recordCohort')
            ->set('mountedActions.0.data.program_id', $dbm->id)
            ->set('mountedActions.0.data.year_level', 2)
            ->assertSet('mountedActions.0.data.curriculum_version_id', $sharedCurriculum->id)
            ->assertSet('mountedActions.0.data.reference', 'DBM 2-1')
            ->assertMountedActionModalSee('Batch 2025–26')
            ->assertMountedActionModalSee('7 continuing students (Batch 2025–26)')
            ->set('mountedActions.0.data.confirmed_count', 22)
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('term_cohorts', [
            'term_id' => $term->id,
            'program_id' => $dbm->id,
            'curriculum_version_id' => $sharedCurriculum->id,
            'year_level' => 2,
            'reference' => 'DBM 2-1',
            'confirmed_count' => 22,
        ]);

        // 2. Cohorts table and Classes table show single intake batch (Batch 2025-26), NOT multi-batch range.
        $component->assertSee('Batch 2025–26')
            ->assertDontSee('Batch 2024–25 to 2026–27');

        Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'classes'])
            ->test(TermClasses::class)
            ->assertSee('Batch 2025–26')
            ->assertDontSee('Batch 2024–25 to 2026–27');

        // 3. Suggested sections never double-count 2024 & 2025 batches sharing one curriculum.
        $page = Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])->test(TermClasses::class)->instance();
        $suggestions = collect($page->suggestedSectionsForTerm($term, false))
            ->where('program_id', $dbm->id)
            ->keyBy('year_level');

        $this->assertSame(4, $suggestions[3]['active_students']);
        $this->assertSame('2024–25', $suggestions[3]['batch_short']);
        $this->assertFalse($suggestions->has(2));

        // Direct scoped demandEvidence also respects program + curriculum + intake batch.
        $year3Demand = app(PrepareTermClassProposal::class)->demandEvidence(
            $term,
            programId: $dbm->id,
            curriculumVersionId: $sharedCurriculum->id,
            intakeStartYear: 2024,
        );
        $this->assertSame(4, $year3Demand['continuing_profiles']);
    }

    public function test_pre_publish_edit_and_delete_work_safely_on_linked_draft_sections_and_block_post_publish_or_enrollment(): void
    {
        $registrar = $this->actingAsRegistrar();

        $ay2026 = AcademicYear::factory()->create(['label' => 'AY 2026-2027', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'state' => AcademicYear::StateActive, 'is_operational' => true]);
        $term = Term::factory()->for($ay2026)->create([
            'type' => Term::TypeFirstSemester,
            'label' => 'First Semester',
            'starts_on' => '2026-08-17',
            'ends_on' => '2026-12-18',
            'state' => Term::StateActive,
        ]);
        TermCalendarPackage::factory()->for($term)->create(['state' => TermCalendarPackage::StateActive, 'activated_at' => now()]);

        $program = Program::factory()->create(['code' => 'DHM', 'name' => 'Diploma in Hospitality Management', 'duration_years' => 3, 'is_active' => true]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['name' => 'DHM Standard', 'state' => CurriculumVersion::StateActive]);
        CurriculumVersionBatch::query()->create(['program_id' => $program->id, 'curriculum_version_id' => $curriculum->id, 'academic_year_id' => $ay2026->id]);

        $this->createCurriculumSubject($curriculum, 'DHM-101', 'Hospitality Basics', 1, $term->type);
        $this->createCurriculumSubject($curriculum, 'DHM-102', 'Food Hygiene', 1, $term->type);

        // Create section DHM 1-1 with auto-loaded confirmed classes.
        Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])
            ->test(TermClasses::class)
            ->callAction('recordCohort', [
                'curriculum_version_id' => $curriculum->id,
                'year_level' => 1,
                'reference' => 'DHM 1-1',
                'confirmed_count' => 24,
                'auto_create_classes' => true,
            ])
            ->assertHasNoActionErrors();

        $cohort = TermCohort::query()->where('term_id', $term->id)->where('reference', 'DHM 1-1')->firstOrFail();
        $linkedSections = $cohort->classOfferings()->with('deliveryGroups.schedulingDemands')->get();
        $this->assertCount(2, $linkedSections);

        // Create a draft schedule run + candidate row + commitment to ensure cascade cleanup works and draft is marked Stale.
        $draftRun = ScheduleGenerationRun::factory()->for($term)->create([
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'candidate_state' => 'Current',
        ]);
        $faculty = User::factory()->create(['status' => User::StatusActive]);
        $firstSection = $linkedSections->first();
        $firstDemand = $firstSection->deliveryGroups->first()->schedulingDemands->first();
        CandidateScheduleRow::query()->create([
            'schedule_run_id' => $draftRun->id,
            'scheduling_demand_id' => $firstDemand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $faculty->id,
            'room_id' => null,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:30:00',
            'status' => CandidateScheduleRow::StatusOk,
        ]);
        SchedulingCommitment::query()->create([
            'term_id' => $term->id,
            'section_id' => $firstSection->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:30:00',
            'scope' => 'SectionWindow',
            'reason' => 'Draft pin',
            'authority_reference' => 'REGISTRAR-PIN',
            'recorded_by' => $registrar->id,
        ]);

        // 1. Edit linked pre-publish section: rename + change expected count -> updates pivot, Section capacity, DeliveryGroup, and Stale run!
        Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])
            ->test(TermClasses::class)
            ->assertTableActionVisible('editCohort', $cohort)
            ->assertTableActionVisible('deleteCohort', $cohort)
            ->callTableAction('editCohort', $cohort, [
                'reference' => 'DHM 1-A',
                'confirmed_count' => 31,
            ])
            ->assertHasNoTableActionErrors();

        $cohort->refresh();
        $this->assertSame('DHM 1-A', $cohort->reference);
        $this->assertSame(31, (int) $cohort->confirmed_count);
        $this->assertSame('Stale', $draftRun->fresh()->candidate_state);

        foreach ($cohort->classOfferings()->with('deliveryGroups')->get() as $section) {
            $this->assertSame(31, (int) $section->capacity);
            $this->assertSame(31, (int) $section->pivot->expected_count);
            $this->assertSame(31, (int) $section->deliveryGroups->first()->expected_count);
        }

        // 2. Delete linked pre-publish section -> removes sole draft classes + candidate rows + commitments + demands, and allows re-adding same name cleanly!
        $draftRun->update(['candidate_state' => 'Current']);
        $deletedSectionIds = $cohort->classOfferings()->pluck('sections.id')->all();

        Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])
            ->test(TermClasses::class)
            ->callTableAction('deleteCohort', $cohort)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('term_cohorts', ['id' => $cohort->id]);
        foreach ($deletedSectionIds as $sectionId) {
            $this->assertDatabaseMissing('sections', ['id' => $sectionId]);
        }
        $this->assertSame('Stale', $draftRun->fresh()->candidate_state);

        // Immediately re-adding DHM 1-A with auto-load succeeds with zero code collisions!
        Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])
            ->test(TermClasses::class)
            ->callAction('recordCohort', [
                'curriculum_version_id' => $curriculum->id,
                'year_level' => 1,
                'reference' => 'DHM 1-A',
                'confirmed_count' => 28,
                'auto_create_classes' => true,
            ])
            ->assertHasNoActionErrors();

        $recreated = TermCohort::query()->where('term_id', $term->id)->where('reference', 'DHM 1-A')->firstOrFail();

        // 3. Guard: Once an enrollment references the section OR timetable is published, Edit and Delete disappear!
        $student = StudentProfile::factory()->create(['program_id' => $program->id, 'curriculum_version_id' => $curriculum->id]);
        Enrollment::factory()->for($student)->for($term)->create(['student_section_id' => $recreated->id]);

        Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])
            ->test(TermClasses::class)
            ->assertTableActionHidden('editCohort', $recreated)
            ->assertTableActionHidden('deleteCohort', $recreated);
    }

    public function test_prepare_standard_registration_matches_exact_curriculum_and_year_level_cohorts(): void
    {
        $ay2025 = AcademicYear::factory()->create(['label' => 'AY 2025-2026', 'starts_on' => '2025-08-01', 'ends_on' => '2026-05-31']);
        $ay2026 = AcademicYear::factory()->create(['label' => 'AY 2026-2027', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31']);
        $term = Term::factory()->for($ay2026)->create(['type' => Term::TypeFirstSemester, 'state' => Term::StateActive]);
        $timetable = PublishedTimetableVersion::factory()->for($term)->create(['state' => PublishedTimetableVersion::StatePublished]);

        $program = Program::factory()->create(['code' => 'BSIS', 'duration_years' => 4, 'is_active' => true]);
        $oldCurriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateSuperseded]);
        $newCurriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);

        $course = Course::factory()->create(['code' => 'IS-GEN', 'state' => Course::StateActive]);
        $spec = CourseSpecification::factory()->for($course)->create(['title' => 'Information Systems Core', 'credit_units' => 3, 'state' => CourseSpecification::StateActive]);

        $oldEntry = CurriculumEntry::factory()->for($oldCurriculum)->for($spec, 'courseSpecification')->create(['year_level' => 'Second Year', 'term_label' => 'First Semester', 'term_type' => $term->type, 'sequence' => 1]);
        $newEntry = CurriculumEntry::factory()->for($newCurriculum)->for($spec, 'courseSpecification')->create(['year_level' => 'First Year', 'term_label' => 'First Semester', 'term_type' => $term->type, 'sequence' => 1]);

        $oldOffering = TermOffering::factory()->for($term)->for($oldEntry, 'curriculumEntry')->create(['state' => TermOffering::StateScheduled]);
        $newOffering = TermOffering::factory()->for($term)->for($newEntry, 'curriculumEntry')->create(['state' => TermOffering::StateScheduled]);

        $oldCohort = TermCohort::factory()->for($term)->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $oldCurriculum->id,
            'year_level' => 2,
            'reference' => 'BSIS 2-1',
            'state' => 'Confirmed',
            'confirmed_at' => now(),
        ]);
        $newCohort = TermCohort::factory()->for($term)->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $newCurriculum->id,
            'year_level' => 1,
            'reference' => 'BSIS 1-1',
            'state' => 'Confirmed',
            'confirmed_at' => now(),
        ]);

        // Deliberately give the Year 1 new-curriculum section larger capacity so old buggy block selection would pick it!
        $oldSection = Section::factory()->for($oldOffering, 'termOffering')->create(['code' => 'ISGEN-2A', 'state' => Section::StateOpen, 'capacity' => 25]);
        $oldSection->cohorts()->attach($oldCohort->id, ['expected_count' => 25]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create(['section_id' => $oldSection->id]);

        $newSection = Section::factory()->for($newOffering, 'termOffering')->create(['code' => 'ISGEN-1A', 'state' => Section::StateOpen, 'capacity' => 45]);
        $newSection->cohorts()->attach($newCohort->id, ['expected_count' => 45]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create(['section_id' => $newSection->id]);

        $student = StudentProfile::factory()->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $oldCurriculum->id,
            'entry_academic_year_id' => $ay2025->id,
        ]);
        $case = Enrollment::factory()->for($student)->for($term)->create();

        $this->assertSame([$oldSection->id], app(PrepareStandardRegistration::class)->sectionIds($case));
    }

    public function test_shared_two_batch_curriculum_plus_third_batch_curriculum_discovers_all_three_year_levels_and_exposes_per_batch_options(): void
    {
        $this->actingAsRegistrar();

        $ay2024 = AcademicYear::factory()->create(['label' => 'AY 2024-2025', 'starts_on' => '2024-08-01', 'ends_on' => '2025-05-31', 'state' => AcademicYear::StateArchived, 'is_operational' => false]);
        $ay2025 = AcademicYear::factory()->create(['label' => 'AY 2025-2026', 'starts_on' => '2025-08-01', 'ends_on' => '2026-05-31', 'state' => AcademicYear::StateArchived, 'is_operational' => false]);
        $ay2026 = AcademicYear::factory()->create(['label' => 'AY 2026-2027', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'state' => AcademicYear::StateActive, 'is_operational' => true]);

        $term = Term::factory()->for($ay2026)->create([
            'type' => Term::TypeFirstSemester,
            'label' => 'First Semester',
            'starts_on' => '2026-08-17',
            'ends_on' => '2026-12-18',
            'state' => Term::StateActive,
        ]);
        TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);

        $dit = Program::factory()->create(['code' => 'DIT', 'name' => 'Diploma in Information Technology', 'duration_years' => 3, 'is_active' => true]);

        $curriculumA = CurriculumVersion::factory()->for($dit)->create([
            'name' => 'Batch 2024–25 to 2025–26',
            'state' => CurriculumVersion::StateActive,
        ]);
        CurriculumVersionBatch::query()->create(['program_id' => $dit->id, 'curriculum_version_id' => $curriculumA->id, 'academic_year_id' => $ay2024->id]);
        CurriculumVersionBatch::query()->create(['program_id' => $dit->id, 'curriculum_version_id' => $curriculumA->id, 'academic_year_id' => $ay2025->id]);

        $curriculumB = CurriculumVersion::factory()->for($dit)->create([
            'name' => 'Batch 2026–27',
            'state' => CurriculumVersion::StateActive,
        ]);
        CurriculumVersionBatch::query()->create(['program_id' => $dit->id, 'curriculum_version_id' => $curriculumB->id, 'academic_year_id' => $ay2026->id]);

        $this->createCurriculumSubject($curriculumB, 'DIT-101', 'IT Fundamentals', 1, $term->type);
        $this->createCurriculumSubject($curriculumA, 'DIT-201', 'Systems Analysis', 2, $term->type);
        $this->createCurriculumSubject($curriculumA, 'DIT-301', 'IT Practicum', 3, $term->type);

        $pageComponent = Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])
            ->test(TermClasses::class);

        $suggestions = collect($pageComponent->instance()->suggestedSectionsForTerm($term, false))
            ->where('program_id', $dit->id)
            ->keyBy('year_level');

        $this->assertCount(3, $suggestions);
        $this->assertSame($curriculumB->id, $suggestions[1]['curriculum_version_id']);
        $this->assertSame($ay2026->id, $suggestions[1]['entry_academic_year_id']);
        $this->assertSame('Batch 2026–27', $suggestions[1]['curriculum_label']);

        $this->assertSame($curriculumA->id, $suggestions[2]['curriculum_version_id']);
        $this->assertSame($ay2025->id, $suggestions[2]['entry_academic_year_id']);
        $this->assertSame('Curriculum A.Y 2024–2025 to 2025–2026 – Batch 2025–26', $suggestions[2]['curriculum_label']);

        $this->assertSame($curriculumA->id, $suggestions[3]['curriculum_version_id']);
        $this->assertSame($ay2024->id, $suggestions[3]['entry_academic_year_id']);
        $this->assertSame('Curriculum A.Y 2024–2025 to 2025–2026 – Batch 2024–25', $suggestions[3]['curriculum_label']);

        // Suggest sections modal renders all 3 distinct batch labels and persists entry_academic_year_id.
        $pageComponent
            ->mountAction('suggestSectionsForTerm')
            ->assertMountedActionModalSee('Curriculum A.Y 2024–2025 to 2025–2026 – Batch 2025–26')
            ->assertMountedActionModalSee('Curriculum A.Y 2024–2025 to 2025–2026 – Batch 2024–25')
            ->assertMountedActionModalSee('Batch 2026–27')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('term_cohorts', [
            'term_id' => $term->id,
            'program_id' => $dit->id,
            'curriculum_version_id' => $curriculumB->id,
            'entry_academic_year_id' => $ay2026->id,
            'year_level' => 1,
        ]);
        $this->assertDatabaseHas('term_cohorts', [
            'term_id' => $term->id,
            'program_id' => $dit->id,
            'curriculum_version_id' => $curriculumA->id,
            'entry_academic_year_id' => $ay2025->id,
            'year_level' => 2,
        ]);
        $this->assertDatabaseHas('term_cohorts', [
            'term_id' => $term->id,
            'program_id' => $dit->id,
            'curriculum_version_id' => $curriculumA->id,
            'entry_academic_year_id' => $ay2024->id,
            'year_level' => 3,
        ]);

        // Add student section exposes distinct per-batch options and syncs year_level <-> entry_academic_year_id.
        $recordModal = Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])
            ->test(TermClasses::class)
            ->mountAction('recordCohort')
            ->set('mountedActions.0.data.program_id', $dit->id);

        $recordOptions = $this->mountedActionFieldComponent($recordModal->instance(), 'curriculum_batch_key')?->getOptions() ?? [];
        $this->assertSame('DIT – Curriculum A.Y 2024–2025 to 2025–2026 – Batch 2025–26', $recordOptions["{$curriculumA->id}:{$ay2025->id}"] ?? null);
        $this->assertSame('DIT – Curriculum A.Y 2024–2025 to 2025–2026 – Batch 2024–25', $recordOptions["{$curriculumA->id}:{$ay2024->id}"] ?? null);
        $this->assertSame('DIT – Batch 2026–27', $recordOptions["{$curriculumB->id}:{$ay2026->id}"] ?? null);

        $recordModal
            ->set('mountedActions.0.data.curriculum_batch_key', "{$curriculumA->id}:{$ay2024->id}")
            ->assertSet('mountedActions.0.data.entry_academic_year_id', $ay2024->id)
            ->assertSet('mountedActions.0.data.year_level', 3);

        // Edit student section also exposes distinct per-batch options and preserves selected entry_academic_year_id.
        $year2Cohort = TermCohort::query()
            ->where('term_id', $term->id)
            ->where('curriculum_version_id', $curriculumA->id)
            ->where('entry_academic_year_id', $ay2025->id)
            ->firstOrFail();

        $editModal = Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])
            ->test(TermClasses::class)
            ->mountTableAction('editCohort', $year2Cohort)
            ->assertSet('mountedActions.0.data.curriculum_batch_key', "{$curriculumA->id}:{$ay2025->id}")
            ->assertSet('mountedActions.0.data.entry_academic_year_id', $ay2025->id);

        $editOptions = $this->mountedActionFieldComponent($editModal->instance(), 'curriculum_batch_key')?->getOptions() ?? [];
        $this->assertSame('DIT – Curriculum A.Y 2024–2025 to 2025–2026 – Batch 2025–26', $editOptions["{$curriculumA->id}:{$ay2025->id}"] ?? null);
        $this->assertSame('DIT – Curriculum A.Y 2024–2025 to 2025–2026 – Batch 2024–25', $editOptions["{$curriculumA->id}:{$ay2024->id}"] ?? null);
    }

    public function test_student_linked_batch_discovery_counts_accurately_across_review_curriculum_programs_table_and_suggest_sections(): void
    {
        $this->actingAsRegistrar();

        $ay2024 = AcademicYear::factory()->create(['label' => 'AY 2024-2025', 'starts_on' => '2024-08-01', 'ends_on' => '2025-05-31', 'state' => AcademicYear::StateArchived, 'is_operational' => false]);
        $ay2025 = AcademicYear::factory()->create(['label' => 'AY 2025-2026', 'starts_on' => '2025-08-01', 'ends_on' => '2026-05-31', 'state' => AcademicYear::StateArchived, 'is_operational' => false]);
        $ay2026 = AcademicYear::factory()->create(['label' => 'AY 2026-2027', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'state' => AcademicYear::StateActive, 'is_operational' => true]);

        $term = Term::factory()->for($ay2026)->create([
            'type' => Term::TypeFirstSemester,
            'label' => 'First Semester',
            'starts_on' => '2026-08-17',
            'ends_on' => '2026-12-18',
            'state' => Term::StateActive,
        ]);

        $dit = Program::factory()->create(['code' => 'DIT', 'name' => 'Diploma in Information Technology', 'duration_years' => 3, 'is_active' => true]);

        $curriculumA = CurriculumVersion::factory()->for($dit)->create([
            'name' => 'Batch 2024–25 to 2025–26',
            'state' => CurriculumVersion::StateActive,
        ]);
        CurriculumVersionBatch::query()->create(['program_id' => $dit->id, 'curriculum_version_id' => $curriculumA->id, 'academic_year_id' => $ay2024->id]);
        CurriculumVersionBatch::query()->create(['program_id' => $dit->id, 'curriculum_version_id' => $curriculumA->id, 'academic_year_id' => $ay2025->id]);

        // Students imported/onboarded with entry_academic_year_id only (entry_term_id = null).
        StudentProfile::factory()->count(3)->create([
            'program_id' => $dit->id,
            'curriculum_version_id' => $curriculumA->id,
            'entry_academic_year_id' => $ay2025->id,
            'entry_term_id' => null,
            'lifecycle_status' => StudentProfile::LifecycleActive,
        ]);
        StudentProfile::factory()->count(5)->create([
            'program_id' => $dit->id,
            'curriculum_version_id' => $curriculumA->id,
            'entry_academic_year_id' => $ay2024->id,
            'entry_term_id' => null,
            'lifecycle_status' => StudentProfile::LifecycleActive,
        ]);

        // 1. ReviewCurriculumVersion::buildAssignedBatches() counts per batch accurately and blocks removal.
        $reviewPage = Livewire::test(ReviewCurriculumVersion::class, ['record' => $curriculumA->getRouteKey()]);
        $assignedBatches = collect($reviewPage->viewData('assignedBatches'))->keyBy('academic_year_id');

        $this->assertSame(5, $assignedBatches[$ay2024->id]['students_count']);
        $this->assertTrue($assignedBatches[$ay2024->id]['in_use']);
        $this->assertFalse($assignedBatches[$ay2024->id]['can_remove']);

        $this->assertSame(3, $assignedBatches[$ay2025->id]['students_count']);
        $this->assertTrue($assignedBatches[$ay2025->id]['in_use']);
        $this->assertFalse($assignedBatches[$ay2025->id]['can_remove']);

        $reviewPage->callAction('removeBatch', arguments: ['academicYearId' => $ay2025->id])
            ->assertNotified('Batch cannot be removed');
        $this->assertDatabaseHas('curriculum_version_batches', [
            'curriculum_version_id' => $curriculumA->id,
            'academic_year_id' => $ay2025->id,
        ]);

        // 2. ProgramsTable counts 2 distinct batches and 8 students.
        $programCounts = ProgramsTable::continuingBatchAndStudentCountsByProgram([$dit->id]);
        $this->assertSame(['batches' => 2, 'students' => 8], $programCounts[$dit->id]);

        // 3. Suggest sections discovers and counts both batches accurately.
        $suggestions = collect(
            Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])
                ->test(TermClasses::class)
                ->instance()
                ->suggestedSectionsForTerm($term, false),
        )
            ->where('program_id', $dit->id)
            ->keyBy('year_level');

        $this->assertSame(3, $suggestions[2]['active_students']);
        $this->assertSame($ay2025->id, $suggestions[2]['entry_academic_year_id']);
        $this->assertSame(5, $suggestions[3]['active_students']);
        $this->assertSame($ay2024->id, $suggestions[3]['entry_academic_year_id']);
    }

    public function test_add_batch_refreshes_auto_generated_name_and_copy_to_draft_clears_stale_draft_suffix_and_entry_term(): void
    {
        $registrar = $this->actingAsRegistrar();

        $ay2024 = AcademicYear::factory()->create(['label' => 'AY 2024-2025', 'starts_on' => '2024-08-01', 'ends_on' => '2025-05-31', 'state' => AcademicYear::StateArchived, 'is_operational' => false]);
        $ay2025 = AcademicYear::factory()->create(['label' => 'AY 2025-2026', 'starts_on' => '2025-08-01', 'ends_on' => '2026-05-31', 'state' => AcademicYear::StateArchived, 'is_operational' => false]);
        $ay2026 = AcademicYear::factory()->create(['label' => 'AY 2026-2027', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'state' => AcademicYear::StateActive, 'is_operational' => true]);
        $ay2027 = AcademicYear::factory()->create(['label' => 'AY 2027-2028', 'starts_on' => '2027-08-01', 'ends_on' => '2028-05-31', 'state' => AcademicYear::StateDraft, 'is_operational' => false]);

        $term2024 = Term::factory()->for($ay2024)->create([
            'type' => Term::TypeFirstSemester,
            'label' => 'First Semester',
            'starts_on' => '2024-08-17',
            'ends_on' => '2024-12-18',
        ]);

        $program = Program::factory()->create(['code' => 'BSIT', 'name' => 'BS Information Technology', 'duration_years' => 4, 'is_active' => true]);
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'BSIT-2024',
            'name' => 'Batch 2024–25',
            'effective_entry_term_id' => $term2024->id,
            'state' => CurriculumVersion::StateActive,
        ]);
        CurriculumVersionBatch::query()->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $curriculum->id,
            'academic_year_id' => $ay2024->id,
        ]);

        $lifecycle = app(CurriculumVersionLifecycleService::class);
        $lifecycle->addBatch($registrar, $curriculum, $ay2025);

        $curriculum->refresh();
        $this->assertSame('Batch 2024–25 to 2025–26', $curriculum->name);

        // Copying to a new batch strips stale "(Draft)" suffix and old effective_entry_term_id from AY 2024-25.
        $copiedDraft = $lifecycle->copyToDraft(
            actor: $registrar,
            source: $curriculum,
            versionCode: 'BSIT-2026',
            name: 'Batch 2024–25 to 2025–26 (Draft)',
            effectiveEntryTermId: $term2024->id,
            startingBatch: $ay2026,
        );

        $this->assertSame('Batch 2026–27', $copiedDraft->name);
        $this->assertNull($copiedDraft->effective_entry_term_id);

        // UI copyToDraft modal also pre-fills blank name and null effective_entry_term_id.
        Livewire::test(ReviewCurriculumVersion::class, ['record' => $curriculum->getRouteKey()])
            ->mountAction('copyToDraft')
            ->assertSet('mountedActions.0.data.name', '')
            ->assertSet('mountedActions.0.data.effective_entry_term_id', null)
            ->set('mountedActions.0.data.starting_academic_year_id', $ay2027->id)
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $uiDraft = CurriculumVersion::query()
            ->where('program_id', $program->id)
            ->where('copied_from_curriculum_version_id', $curriculum->id)
            ->where('id', '!=', $copiedDraft->id)
            ->firstOrFail();

        $this->assertSame('Batch 2027–28', $uiDraft->name);
        $this->assertNull($uiDraft->effective_entry_term_id);
    }

    public function test_term_resources_and_school_resources_expose_per_batch_filter_options_and_bulk_toggleable_assign_teacher(): void
    {
        $this->actingAsRegistrar();

        $ay2024 = AcademicYear::factory()->create(['label' => 'AY 2024-2025', 'starts_on' => '2024-08-01', 'ends_on' => '2025-05-31', 'state' => AcademicYear::StateArchived, 'is_operational' => false]);
        $ay2025 = AcademicYear::factory()->create(['label' => 'AY 2025-2026', 'starts_on' => '2025-08-01', 'ends_on' => '2026-05-31', 'state' => AcademicYear::StateActive, 'is_operational' => true]);

        $term = Term::factory()->for($ay2025)->create([
            'type' => Term::TypeFirstSemester,
            'label' => 'First Semester',
            'starts_on' => '2025-08-17',
            'ends_on' => '2025-12-18',
            'state' => Term::StateActive,
        ]);
        TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);

        $dit = Program::factory()->create(['code' => 'DIT', 'name' => 'Diploma in Information Technology', 'duration_years' => 3, 'is_active' => true]);
        $curriculumA = CurriculumVersion::factory()->for($dit)->create([
            'name' => 'Batch 2024–25 to 2025–26',
            'state' => CurriculumVersion::StateActive,
        ]);
        CurriculumVersionBatch::query()->create(['program_id' => $dit->id, 'curriculum_version_id' => $curriculumA->id, 'academic_year_id' => $ay2024->id]);
        CurriculumVersionBatch::query()->create(['program_id' => $dit->id, 'curriculum_version_id' => $curriculumA->id, 'academic_year_id' => $ay2025->id]);

        $entry = $this->createCurriculumSubject($curriculumA, 'DIT-101', 'Intro to Computing', 1, $term->type);
        $courseId = $entry->courseSpecification->course_id;

        $facultyRole = Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $facultyOne = User::factory()->create(['name' => 'Faculty Alpha', 'status' => User::StatusActive]);
        $facultyOne->assignRole($facultyRole);
        $facultyTwo = User::factory()->create(['name' => 'Faculty Beta', 'status' => User::StatusActive]);
        $facultyTwo->assignRole($facultyRole);

        // 1. TermResources: Filter subjects by program exposes both batch keys & labels.
        $termResources = Livewire::withQueryParams(['termId' => $term->id, 'resourceView' => 'faculty'])
            ->test(TermResources::class);

        $termResources
            ->mountAction('assignSubjects')
            ->set('mountedActions.0.data.faculty_user_id', $facultyOne->id)
            ->assertMountedActionModalSee("curriculum_batch:{$curriculumA->id}:{$ay2025->id}")
            ->assertMountedActionModalSee("curriculum_batch:{$curriculumA->id}:{$ay2024->id}")
            ->assertMountedActionModalSee('DIT – Curriculum A.Y 2024–2025 to 2025–2026 – Batch 2025–26')
            ->assertMountedActionModalSee('DIT – Curriculum A.Y 2024–2025 to 2025–2026 – Batch 2024–25');

        // Verify TermResources::assignTeacherForSubjectAction has bulkToggleable() enabled and saves multiple faculty.
        $teacherActionModal = Livewire::withQueryParams(['termId' => $term->id, 'resourceView' => 'faculty'])
            ->test(TermResources::class)
            ->mountAction('assignTeacherForSubject', arguments: ['courseId' => $courseId])
            ->assertMountedActionModalSee('Select all')
            ->assertMountedActionModalSee('Deselect all');

        /** @var CheckboxList|null $facultyCheckboxList */
        $facultyCheckboxList = $this->mountedActionFieldComponent($teacherActionModal->instance(), 'faculty_user_ids');
        $this->assertInstanceOf(CheckboxList::class, $facultyCheckboxList);
        $this->assertTrue($facultyCheckboxList->isBulkToggleable());

        $teacherActionModal
            ->set('mountedActions.0.data.faculty_user_ids', [$facultyOne->id, $facultyTwo->id])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('faculty_qualifications', [
            'course_id' => $courseId,
            'faculty_user_id' => $facultyOne->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('faculty_qualifications', [
            'course_id' => $courseId,
            'faculty_user_id' => $facultyTwo->id,
            'is_active' => true,
        ]);

        // 2. SchoolResources: Filter subjects by program also exposes both batch keys & labels.
        Livewire::withQueryParams(['tab' => 'faculty'])
            ->test(SchoolResources::class)
            ->mountAction('assignSubjects')
            ->set('mountedActions.0.data.faculty_user_id', $facultyOne->id)
            ->assertMountedActionModalSee("curriculum_batch:{$curriculumA->id}:{$ay2025->id}")
            ->assertMountedActionModalSee("curriculum_batch:{$curriculumA->id}:{$ay2024->id}")
            ->assertMountedActionModalSee('DIT – Curriculum A.Y 2024–2025 to 2025–2026 – Batch 2025–26')
            ->assertMountedActionModalSee('DIT – Curriculum A.Y 2024–2025 to 2025–2026 – Batch 2024–25');
    }

    private function mountedActionFieldComponent(object $livewirePage, string $fieldName): mixed
    {
        $method = new \ReflectionMethod($livewirePage, 'getMountedActionSchema');
        $method->setAccessible(true);

        return $method->invoke($livewirePage)?->getComponent($fieldName);
    }

    private function actingAsRegistrar(): User
    {
        $role = Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $role->syncPermissions([
            Permission::findOrCreate('manage-schedules', 'web'),
            Permission::findOrCreate('approve-documents', 'web'),
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole($role);
        $this->actingAs($registrar)->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleRegistrar]);

        return $registrar;
    }

    private function createCurriculumSubject(CurriculumVersion $curriculum, string $code, string $title, int $yearLevel, string $termType): CurriculumEntry
    {
        $course = Course::factory()->create(['code' => $code, 'state' => Course::StateActive]);
        $spec = CourseSpecification::factory()->for($course)->create([
            'title' => $title,
            'credit_units' => 3,
            'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
            'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
            'state' => CourseSpecification::StateActive,
        ]);
        CourseComponent::factory()->for($spec)->create([
            'component_type' => CourseComponent::TypeLecture,
            'meeting_pattern' => '2x90',
            'weekly_contact_hours' => 3,
            'room_type_default' => CourseComponent::RoomTypeLectureRoom,
        ]);

        return CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create([
            'year_level' => (string) $yearLevel,
            'term_label' => $termType === Term::TypeFirstSemester ? 'First Semester' : 'Second Semester',
            'term_type' => $termType,
        ]);
    }
}
