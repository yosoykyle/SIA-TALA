<?php

namespace Tests\Feature;

use App\Actions\AcademicSetup\AcademicReadinessService;
use App\Actions\AcademicSetup\CurriculumWorkbenchService;
use App\Actions\Authentication\WorkspaceContextResolver;
use App\Filament\Pages\SchoolResources;
use App\Filament\Pages\TermCalendar;
use App\Filament\Pages\TermClasses;
use App\Filament\Pages\TermOverview;
use App\Filament\Resources\CurriculumVersions\Pages\ReviewCurriculumVersion;
use App\Filament\Resources\Programs\Pages\ViewProgram;
use App\Filament\Resources\Terms\Pages\CreateTerm;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\CurriculumVersionBatch;
use App\Models\FacultyQualification;
use App\Models\Program;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class Issue93AcademicYearBatchAndMultiCurriculumTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_historical_inline_intake_batch_is_non_operational_and_hidden_from_term_overview(): void
    {
        $this->actingAsRegistrar();

        $today = CarbonImmutable::today(config('app.display_timezone', 'Asia/Manila'));
        $currentStart = $today->subMonths(2)->toDateString();
        $currentEnd = $today->addMonths(6)->toDateString();

        $operationalAy = AcademicYear::factory()->create([
            'label' => 'AY 2090-2091',
            'starts_on' => $currentStart,
            'ends_on' => $currentEnd,
            'state' => AcademicYear::StateActive,
            'is_operational' => true,
        ]);

        $program = Program::factory()->create(['code' => 'DIT93', 'duration_years' => 3, 'is_active' => true]);
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'name' => 'DIT Historical Plan',
            'state' => CurriculumVersion::StateActive,
        ]);

        $pastStart = '2010-08-01';
        $pastEnd = '2011-05-31';

        // Inline historical batch creation in ReviewCurriculumVersion must mark is_operational = false.
        Livewire::test(ReviewCurriculumVersion::class, ['record' => $curriculum->getRouteKey()])
            ->mountAction('addBatch')
            ->mountAction(TestAction::make('createOption')->schemaComponent('academic_year_id', schema: 'mountedActionSchema0'))
            ->setActionData([
                'label' => 'AY 2010-2011',
                'starts_on' => $pastStart,
                'ends_on' => $pastEnd,
                'state' => AcademicYear::StateDraft,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $historicalAy = AcademicYear::query()->where('label', 'AY 2010-2011')->sole();
        $this->assertFalse($historicalAy->is_operational);

        // TermOverview operational Academic Years directory must show operationalAy but NOT historicalAy.
        Livewire::test(TermOverview::class)
            ->assertCanSeeTableRecords([$operationalAy])
            ->assertCanNotSeeTableRecords([$historicalAy]);

        // Normalized-label duplicate ("2090-2091" vs "AY 2090-2091") must be rejected on inline creation.
        Livewire::test(ReviewCurriculumVersion::class, ['record' => $curriculum->getRouteKey()])
            ->mountAction('addBatch')
            ->mountAction(TestAction::make('createOption')->schemaComponent('academic_year_id', schema: 'mountedActionSchema0'))
            ->setActionData([
                'label' => '2090-2091',
                'starts_on' => '2092-08-01',
                'ends_on' => '2093-05-31',
                'state' => AcademicYear::StateDraft,
            ])
            ->callMountedAction()
            ->assertHasActionErrors(['label']);

        // Overlapping dates must be rejected on inline creation too.
        Livewire::test(ReviewCurriculumVersion::class, ['record' => $curriculum->getRouteKey()])
            ->mountAction('addBatch')
            ->mountAction(TestAction::make('createOption')->schemaComponent('academic_year_id', schema: 'mountedActionSchema0'))
            ->setActionData([
                'label' => 'AY 2091-2092',
                'starts_on' => $today->toDateString(),
                'ends_on' => $today->addMonths(10)->toDateString(),
                'state' => AcademicYear::StateDraft,
            ])
            ->callMountedAction()
            ->assertHasActionErrors(['ends_on']);
    }

    public function test_operational_term_planning_blocks_past_end_dates_while_allowing_in_progress_periods_and_detects_current_ay(): void
    {
        $this->actingAsRegistrar();

        $today = CarbonImmutable::today(config('app.display_timezone', 'Asia/Manila'));

        // Clear/archive any pre-existing active operational AYs so detection assertion is deterministic.
        AcademicYear::query()->update(['state' => AcademicYear::StateArchived]);

        // Creating an operational AY whose ends_on < today must fail.
        Livewire::test(TermOverview::class)
            ->callAction(TestAction::make('createAcademicYear')->table(), data: [
                'label' => 'AY 2012-2013',
                'starts_on' => '2012-08-01',
                'ends_on' => '2013-05-31',
                'state' => AcademicYear::StateDraft,
            ])
            ->assertHasActionErrors(['ends_on']);

        // Creating an in-progress operational AY (starts_on < today <= ends_on) must succeed.
        Livewire::test(TermOverview::class)
            ->callAction(TestAction::make('createAcademicYear')->table(), data: [
                'label' => 'AY 2094-2095',
                'starts_on' => $today->subMonths(3)->toDateString(),
                'ends_on' => $today->addMonths(6)->toDateString(),
                'state' => AcademicYear::StateActive,
            ])
            ->assertHasNoActionErrors();

        $currentAy = AcademicYear::query()->where('label', 'AY 2094-2095')->sole();
        $this->assertTrue($currentAy->is_operational);

        // A further-future AY must NOT override the currently running AY in resolveCurrentOperational().
        AcademicYear::factory()->create([
            'label' => 'AY 2095-2096',
            'starts_on' => $today->addMonths(7)->toDateString(),
            'ends_on' => $today->addMonths(18)->toDateString(),
            'state' => AcademicYear::StateActive,
            'is_operational' => true,
        ]);

        $this->assertSame($currentAy->id, AcademicYear::resolveCurrentOperational($today)?->id);

        // Creating a new operational Term whose ends_on < today must fail; an in-progress term must succeed.
        Livewire::withQueryParams(['academicYearId' => $currentAy->id])
            ->test(TermOverview::class)
            ->callAction(TestAction::make('createTerm')->table(), data: [
                'academic_year_id' => $currentAy->id,
                'type' => Term::TypeFirstSemester,
                'starts_on' => $today->subMonths(2)->toDateString(),
                'ends_on' => $today->subDays(5)->toDateString(),
            ])
            ->assertHasActionErrors(['ends_on']);

        Livewire::withQueryParams(['academicYearId' => $currentAy->id])
            ->test(TermOverview::class)
            ->callAction(TestAction::make('createTerm')->table(), data: [
                'academic_year_id' => $currentAy->id,
                'type' => Term::TypeFirstSemester,
                'starts_on' => $today->subMonths(2)->toDateString(),
                'ends_on' => $today->addMonths(2)->toDateString(),
            ])
            ->assertHasNoActionErrors();
    }

    public function test_chronological_multi_batch_resolution_prefers_latest_batch_and_school_resources_filters_by_batch(): void
    {
        $registrar = $this->actingAsRegistrar();

        $program = Program::factory()->create(['code' => 'DIT93A', 'name' => 'Diploma in IT', 'duration_years' => 3, 'is_active' => true]);

        $ay2044 = AcademicYear::factory()->create([
            'label' => 'AY 2044-2045',
            'starts_on' => '2044-08-01',
            'ends_on' => '2045-05-31',
            'state' => AcademicYear::StateActive,
            'is_operational' => false,
        ]);
        $ay2045 = AcademicYear::factory()->create([
            'label' => 'AY 2045-2046',
            'starts_on' => '2045-08-01',
            'ends_on' => '2046-05-31',
            'state' => AcademicYear::StateActive,
            'is_operational' => false,
        ]);
        $ay2046 = AcademicYear::factory()->create([
            'label' => 'AY 2046-2047',
            'starts_on' => '2046-08-01',
            'ends_on' => '2047-05-31',
            'state' => AcademicYear::StateActive,
            'is_operational' => true,
        ]);

        // Create Batch 2046-47 FIRST (lower id), then Batch 2044-45 to 2045-46 SECOND (higher id) to reproduce QA order!
        $cvNew = CurriculumVersion::factory()->for($program)->create([
            'name' => 'DIT Batch 2046-47',
            'state' => CurriculumVersion::StateActive,
        ]);
        CurriculumVersionBatch::query()->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $cvNew->id,
            'academic_year_id' => $ay2046->id,
            'added_by' => $registrar->id,
        ]);

        $cvOld = CurriculumVersion::factory()->for($program)->create([
            'name' => 'DIT Batch 2044-45 to 2045-46',
            'state' => CurriculumVersion::StateActive,
        ]);
        foreach ([$ay2044, $ay2045] as $oldAy) {
            CurriculumVersionBatch::query()->create([
                'program_id' => $program->id,
                'curriculum_version_id' => $cvOld->id,
                'academic_year_id' => $oldAy->id,
                'added_by' => $registrar->id,
            ]);
        }

        // Retired continuing curriculum must still map to DIT93A in SchoolResources (not misclassified as Shared / GE).
        $ay2043 = AcademicYear::factory()->create([
            'label' => 'AY 2043-2044',
            'starts_on' => '2043-08-01',
            'ends_on' => '2044-05-31',
            'state' => AcademicYear::StateClosed,
            'is_operational' => false,
        ]);
        $cvRetired = CurriculumVersion::factory()->for($program)->create([
            'name' => 'DIT Legacy 2043',
            'state' => CurriculumVersion::StateSuperseded,
        ]);
        CurriculumVersionBatch::query()->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $cvRetired->id,
            'academic_year_id' => $ay2043->id,
            'added_by' => $registrar->id,
        ]);

        // AcademicReadinessService must pick Batch 2046-47 (latest batch year), NOT cvOld (higher id).
        $current = app(AcademicReadinessService::class)->currentCurriculum($program->fresh());
        $this->assertSame($cvNew->id, $current?->id);

        // ViewProgram must list Batch 2046-47 before Batch 2044-45 to 2045-46.
        $viewProgram = Livewire::test(ViewProgram::class, ['record' => $program->getRouteKey()]);
        $rows = $viewProgram->viewData('curriculaRows');
        $activeIds = collect($rows)->where('state', CurriculumVersion::StateActive)->pluck('id')->values()->all();
        $this->assertSame([$cvNew->id, $cvOld->id], $activeIds);

        // Create batch-specific subjects for cvNew, cvOld, and cvRetired.
        $courseNew = $this->createSubjectInCurriculum($cvNew, 'DIT46-101', 'Modern Cloud Foundations');
        $courseOld = $this->createSubjectInCurriculum($cvOld, 'DIT44-101', 'Legacy Desktop Systems');
        $courseRetired = $this->createSubjectInCurriculum($cvRetired, 'DIT43-101', 'Classic Systems Admin');

        $faculty = User::factory()->create(['status' => User::StatusActive]);
        Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $faculty->assignRole(User::StaffRoleFaculty);

        // Verify SchoolResources exposes curriculum_batch:<cvId>:<ayId> filter and includes retired curricula under DIT93A!
        $component = Livewire::test(SchoolResources::class);
        $page = $component->instance();
        $reflection = new \ReflectionMethod($page, 'catalogSubjectsPayload');
        $reflection->setAccessible(true);
        /** @var array<string, mixed> $payload */
        $payload = $reflection->invoke($page);

        $this->assertArrayHasKey($cvNew->id, $payload['curricula']);
        $this->assertArrayHasKey($cvOld->id, $payload['curricula']);
        $this->assertArrayHasKey($cvRetired->id, $payload['curricula']);
        $this->assertArrayHasKey("curriculum_batch:{$cvNew->id}:{$ay2046->id}", $payload['batch_filters']);
        $this->assertContains($courseNew->id, $payload['curriculum_course_ids'][$cvNew->id]);
        $this->assertNotContains($courseOld->id, $payload['curriculum_course_ids'][$cvNew->id]);
        $this->assertContains($courseRetired->id, $payload['program_course_ids'][$program->id]);
        $this->assertStringContainsString('Batch 2046–47', $payload['descriptions'][$courseNew->id]);
        $this->assertStringContainsString('DIT93A', $payload['descriptions'][$courseRetired->id]);
        $this->assertStringNotContainsString('Shared / GE', $payload['descriptions'][$courseRetired->id]);

        // Assigning courseNew to faculty creates a canonical Course-scoped FacultyQualification.
        $component->callAction(TestAction::make('assignSubjects')->table(), data: [
            'faculty_user_id' => $faculty->id,
            'program_filter' => "curriculum_batch:{$cvNew->id}:{$ay2046->id}",
            'course_ids' => [(string) $courseNew->id],
            'retained_course_ids' => [],
        ])->assertHasNoActionErrors();

        $this->assertTrue(
            FacultyQualification::query()
                ->where('faculty_user_id', $faculty->id)
                ->where('course_id', $courseNew->id)
                ->where('is_active', true)
                ->exists(),
        );
    }

    public function test_dynamic_duration_lookback_and_smart_term_classes_section_rollover_and_mismatch_advisory(): void
    {
        $registrar = $this->actingAsRegistrar();

        $dit = Program::factory()->create(['code' => 'DIT93B', 'name' => 'Diploma in IT', 'duration_years' => 3, 'is_active' => true]);
        $bsit = Program::factory()->create(['code' => 'BSIT93B', 'name' => 'BS Information Technology', 'duration_years' => 4, 'is_active' => true]);

        $this->assertSame(2, $dit->intakeBatchLookbackYears());
        $this->assertSame(3, $bsit->intakeBatchLookbackYears());

        $ay2053 = AcademicYear::factory()->create(['label' => 'AY 2053-2054', 'starts_on' => '2053-08-01', 'ends_on' => '2054-05-31', 'is_operational' => false]);
        $ay2054 = AcademicYear::factory()->create(['label' => 'AY 2054-2055', 'starts_on' => '2054-08-01', 'ends_on' => '2055-05-31', 'is_operational' => false]);
        $ay2055 = AcademicYear::factory()->create(['label' => 'AY 2055-2056', 'starts_on' => '2055-08-01', 'ends_on' => '2056-05-31', 'is_operational' => false]);
        $ay2056 = AcademicYear::factory()->create(['label' => 'AY 2056-2057', 'starts_on' => '2056-08-01', 'ends_on' => '2057-05-31', 'state' => AcademicYear::StateActive, 'is_operational' => true]);

        $this->assertSame([1 => 2056, 2 => 2055, 3 => 2054], $dit->expectedIntakeYearsForAcademicYear($ay2056));
        $this->assertSame([1 => 2056, 2 => 2055, 3 => 2054, 4 => 2053], $bsit->expectedIntakeYearsForAcademicYear($ay2056));

        $ditNew = CurriculumVersion::factory()->for($dit)->create(['state' => CurriculumVersion::StateActive]);
        CurriculumVersionBatch::query()->create(['program_id' => $dit->id, 'curriculum_version_id' => $ditNew->id, 'academic_year_id' => $ay2056->id]);

        $ditOld = CurriculumVersion::factory()->for($dit)->create(['state' => CurriculumVersion::StateSuperseded]);
        foreach ([$ay2054, $ay2055] as $ay) {
            CurriculumVersionBatch::query()->create(['program_id' => $dit->id, 'curriculum_version_id' => $ditOld->id, 'academic_year_id' => $ay->id]);
        }

        $bsitPlan = CurriculumVersion::factory()->for($bsit)->create(['state' => CurriculumVersion::StateActive]);
        foreach ([$ay2053, $ay2054, $ay2055, $ay2056] as $ay) {
            CurriculumVersionBatch::query()->create(['program_id' => $bsit->id, 'curriculum_version_id' => $bsitPlan->id, 'academic_year_id' => $ay->id]);
        }

        // Put a 1st-sem subject in ditNew so ditOld (0 subjects) appears in curriculumReadinessGapsForTerm!
        $this->createSubjectInCurriculum($ditNew, 'DIT93B-101', 'Intro to Computing', yearLevel: 1);

        $term = Term::factory()->for($ay2056)->create([
            'type' => Term::TypeFirstSemester,
            'label' => 'First Semester',
            'starts_on' => '2056-08-17',
            'ends_on' => '2056-12-19',
            'state' => Term::StateActive,
        ]);
        TermCalendarPackage::factory()->for($term)->create(['state' => TermCalendarPackage::StateActive, 'activated_at' => now()]);

        $component = Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])->test(TermClasses::class);
        $page = $component->instance();

        // Expected curriculum matching & mismatch advisory.
        $this->assertSame($ditNew->id, $page->resolveExpectedCurriculumForYearLevel($term, $dit, 1)?->id);
        $this->assertSame($ditOld->id, $page->resolveExpectedCurriculumForYearLevel($term, $dit, 2)?->id);
        $this->assertSame($ditOld->id, $page->resolveExpectedCurriculumForYearLevel($term, $dit, 3)?->id);

        $this->assertNull($page->batchYearMismatchAdvisory($term, $ditNew, 1));
        $mismatch = $page->batchYearMismatchAdvisory($term, $ditNew, 2);
        $this->assertNotNull($mismatch);
        $this->assertStringContainsString('Batch 2055–56', $mismatch['title']);

        // curriculumReadinessGapsForTerm must check continuing running batches (ditOld with 0 subjects) too!
        $gaps = $component->viewData('curriculumReadinessGaps');
        $this->assertTrue(collect($gaps)->contains('curriculum_id', $ditOld->id));

        // Suggested sections must map DIT Year 1->ditNew, Year 2->ditOld, Year 3->ditOld and BSIT Year 1..4->bsitPlan.
        $suggestions = collect($page->suggestedSectionsForTerm($term, false));
        $ditSuggestions = $suggestions->where('program_id', $dit->id)->keyBy('year_level');
        $bsitSuggestions = $suggestions->where('program_id', $bsit->id)->keyBy('year_level');

        $this->assertSame($ditNew->id, $ditSuggestions[1]['curriculum_version_id']);
        $this->assertSame($ditOld->id, $ditSuggestions[2]['curriculum_version_id']);
        $this->assertSame($ditOld->id, $ditSuggestions[3]['curriculum_version_id']);
        $this->assertSame([1, 2, 3, 4], $bsitSuggestions->keys()->sort()->values()->all());

        // Execute suggestSectionsForTermAction for DIT Years 1, 2, 3.
        $selectedKeys = [
            $ditSuggestions[1]['key'],
            $ditSuggestions[2]['key'],
            $ditSuggestions[3]['key'],
        ];

        $component->callAction(TestAction::make('suggestSectionsForTerm')->table(), data: [
            'include_older_batches' => false,
            'selected_keys' => $selectedKeys,
            'default_count' => 25,
            'auto_create_classes' => false,
        ])->assertHasNoActionErrors();

        $this->assertDatabaseHas('term_cohorts', [
            'term_id' => $term->id,
            'program_id' => $dit->id,
            'curriculum_version_id' => $ditNew->id,
            'year_level' => 1,
        ]);
        $this->assertDatabaseHas('term_cohorts', [
            'term_id' => $term->id,
            'program_id' => $dit->id,
            'curriculum_version_id' => $ditOld->id,
            'year_level' => 2,
        ]);
        $this->assertDatabaseHas('term_cohorts', [
            'term_id' => $term->id,
            'program_id' => $dit->id,
            'curriculum_version_id' => $ditOld->id,
            'year_level' => 3,
        ]);

        // Re-running suggestions must skip already-created DIT years 1..3.
        $remainingDit = collect($page->suggestedSectionsForTerm($term, false))->where('program_id', $dit->id);
        $this->assertTrue($remainingDit->isEmpty());
    }

    public function test_curriculum_workbench_assign_specification_supports_explicit_draft_selection_when_multiple_drafts_exist(): void
    {
        $registrar = $this->actingAsRegistrar();
        $program = Program::factory()->create(['code' => 'DITX', 'duration_years' => 3, 'is_active' => true]);

        $draftA = CurriculumVersion::factory()->for($program)->create(['name' => 'Draft A', 'state' => CurriculumVersion::StateDraft]);
        $draftB = CurriculumVersion::factory()->for($program)->create(['name' => 'Draft B', 'state' => CurriculumVersion::StateDraft]);

        $course = Course::factory()->create(['code' => 'IT930', 'state' => Course::StateActive]);
        $spec = CourseSpecification::factory()->for($course)->create([
            'title' => 'Target Draft Subject',
            'credit_units' => 3,
            'state' => CourseSpecification::StateDraft,
        ]);

        $result = app(CurriculumWorkbenchService::class)->assignSpecificationToCurricula($registrar, $spec, [
            'apply_to' => 'selected',
            'curriculum_version_ids' => [$draftA->id],
            'placement_year_level' => 1,
            'placement_term_type' => Term::TypeFirstSemester,
        ]);

        $this->assertSame(1, $result['added_curricula']);
        $this->assertDatabaseHas('curriculum_entries', [
            'curriculum_version_id' => $draftA->id,
            'course_specification_id' => $spec->id,
        ]);
        $this->assertDatabaseMissing('curriculum_entries', [
            'curriculum_version_id' => $draftB->id,
            'course_specification_id' => $spec->id,
        ]);
    }

    public function test_academic_year_enforces_unique_term_types_across_ui_and_backend(): void
    {
        $this->actingAsRegistrar();

        $ay = AcademicYear::factory()->create([
            'label' => 'AY 2096-2097',
            'starts_on' => '2096-08-01',
            'ends_on' => '2097-07-31',
            'state' => AcademicYear::StateActive,
            'is_operational' => true,
        ]);

        $firstTerm = Term::factory()->for($ay)->create([
            'type' => Term::TypeFirstSemester,
            'label' => 'First Semester',
            'starts_on' => '2096-08-10',
            'ends_on' => '2096-12-10',
            'state' => Term::StateDraft,
        ]);

        // Dropdown filtering hides already-created term type while preserving remaining types.
        $this->assertSame(
            [Term::TypeSecondSemester => 'Second Semester', Term::TypeSpecialTerm => 'Special Term'],
            Term::availableTypeOptionsForAcademicYear($ay->id),
        );
        $this->assertSame(Term::typeOptions(), Term::availableTypeOptionsForAcademicYear($ay->id, $firstTerm->id));

        // Direct server-side guard rejects duplicate (academic_year_id, type) even on non-overlapping dates.
        Livewire::withQueryParams(['academicYearId' => $ay->id])
            ->test(TermOverview::class)
            ->callAction(TestAction::make('createTerm')->table(), data: [
                'academic_year_id' => $ay->id,
                'type' => Term::TypeFirstSemester,
                'starts_on' => '2097-01-10',
                'ends_on' => '2097-05-15',
            ])
            ->assertHasActionErrors(['type']);

        // Creating a distinct term type succeeds.
        Livewire::withQueryParams(['academicYearId' => $ay->id])
            ->test(TermOverview::class)
            ->callAction(TestAction::make('createTerm')->table(), data: [
                'academic_year_id' => $ay->id,
                'type' => Term::TypeSecondSemester,
                'starts_on' => '2097-01-10',
                'ends_on' => '2097-05-15',
            ])
            ->assertHasNoActionErrors();

        $secondTerm = Term::query()
            ->where('academic_year_id', $ay->id)
            ->where('type', Term::TypeSecondSemester)
            ->sole();

        // Term Settings must reject changing Second Semester to First Semester on the same AY.
        Livewire::withQueryParams(['termId' => $secondTerm->id])
            ->test(TermCalendar::class)
            ->callAction('termSettings', data: [
                'academic_year_id' => $ay->id,
                'type' => Term::TypeFirstSemester,
                'starts_on' => '2097-01-10',
                'ends_on' => '2097-05-15',
                'default_max_units' => 24,
            ])
            ->assertHasActionErrors(['type']);

        // CreateTerm resource page must reject duplicate type and accept the remaining Special Term.
        Livewire::test(CreateTerm::class)
            ->fillForm([
                'academic_year_id' => $ay->id,
                'type' => Term::TypeSecondSemester,
                'starts_on' => '2097-06-01',
                'ends_on' => '2097-07-15',
            ])
            ->call('create')
            ->assertHasFormErrors(['type']);

        Livewire::test(CreateTerm::class)
            ->fillForm([
                'academic_year_id' => $ay->id,
                'type' => Term::TypeSpecialTerm,
                'starts_on' => '2097-06-01',
                'ends_on' => '2097-07-15',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // Once all 3 term types exist, availableTypeOptionsForAcademicYear returns empty and TermOverview shows helper hint.
        $this->assertSame([], Term::availableTypeOptionsForAcademicYear($ay->id));
        $this->assertNotNull(Term::duplicateTypeError($ay->id, Term::TypeFirstSemester));

        Livewire::withQueryParams(['academicYearId' => $ay->id])
            ->test(TermOverview::class)
            ->mountAction(TestAction::make('createTerm')->table())
            ->assertFormFieldDisabled('type')
            ->assertFormFieldExists('type', checkFieldUsing: fn ($field): bool => $field->getOptions() === []
                && $field->getPlaceholder() === 'All 3 term types already exist for this academic year');

        Livewire::test(CreateTerm::class)
            ->fillForm(['academic_year_id' => $ay->id])
            ->assertFormFieldDisabled('type')
            ->assertFormFieldExists('type', checkFieldUsing: fn ($field): bool => $field->getOptions() === []
                && $field->getPlaceholder() === 'All 3 term types already exist for this academic year')
            ->assertSee('This academic year already has First Semester, Second Semester, and Special Term.');
    }

    private function createSubjectInCurriculum(
        CurriculumVersion $curriculum,
        string $code,
        string $title,
        int $yearLevel = 1,
    ): Course {
        $course = Course::factory()->create(['code' => $code, 'state' => Course::StateActive]);
        $spec = CourseSpecification::factory()->for($course)->create([
            'title' => $title,
            'credit_units' => 3.0,
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
        ]);
        CourseComponent::factory()->for($spec, 'courseSpecification')->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 3.0,
            'meeting_pattern' => '2x90',
            'room_type_default' => CourseComponent::RoomTypeLectureRoom,
        ]);
        CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create([
            'year_level' => (string) $yearLevel,
            'term_type' => Term::TypeFirstSemester,
            'term_label' => 'First Semester',
        ]);

        return $course;
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
}
