<?php

namespace Tests\Feature\AcademicSetup;

use App\Actions\AcademicSetup\CurriculumSpreadsheetExportService;
use App\Actions\AcademicSetup\CurriculumSpreadsheetImportService;
use App\Actions\AcademicSetup\CurriculumSpreadsheetParser;
use App\Actions\AcademicSetup\CurriculumVersionLifecycleService;
use App\Actions\Authentication\WorkspaceContextResolver;
use App\Filament\Resources\Courses\Pages\ImportCatalogSubjects;
use App\Filament\Resources\Courses\Pages\ListCourses;
use App\Filament\Resources\CurriculumVersions\Pages\ImportCurriculumSpreadsheet;
use App\Filament\Resources\CurriculumVersions\Pages\ReviewCurriculumVersion;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class CurriculumSpreadsheetImportExportTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    private array $tempPaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->tempPaths = [];

        parent::tearDown();
    }

    public function test_servitech_multi_sheet_workbook_parsing_classification_practicum_and_prerequisite_normalization(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $parser = app(CurriculumSpreadsheetParser::class);
        $importService = app(CurriculumSpreadsheetImportService::class);

        $program = Program::factory()->create([
            'code' => 'DHM-SERV',
            'name' => 'Diploma in Hospitality Management',
            'duration_years' => 3,
            'is_active' => true,
        ]);
        $draft = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'DHM-2025-DRAFT',
            'state' => CurriculumVersion::StateDraft,
        ]);

        // Pre-seed an existing catalog course to verify whitespace-normalized prerequisite & duplicate-in-curriculum checks.
        $existingCourse = Course::factory()->create(['code' => 'GE 100']);
        $existingSpec = CourseSpecification::factory()->for($existingCourse)->create([
            'title' => 'Purposive Communication',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);
        CurriculumEntry::factory()->for($draft)->for($existingSpec, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
            'term_label' => 'First Semester',
            'sequence' => 1,
        ]);

        $xlsxPath = $this->createSyntheticServitechWorkbook();

        // 1. Multi-sheet workbook inspection (DHM, DBM, DIT).
        $workbook = $parser->inspectWorkbook($xlsxPath);
        $this->assertCount(3, $workbook['sheets']);
        $this->assertSame(['DHM', 'DBM', 'DIT'], array_column($workbook['sheets'], 'name'));
        $this->assertSame(0, $workbook['default_sheet_index']);

        // 2. Extract DHM sheet (sheet 0: uses "CODE" header, banners, Resultant NC, Grade, Remarks).
        $dhmExtracted = $parser->extractSheet($xlsxPath, 0);
        $this->assertTrue($dhmExtracted['detected_banners']);
        $this->assertNotNull($dhmExtracted['suggested_mapping'][CurriculumSpreadsheetParser::FieldCode]);
        $this->assertNotNull($dhmExtracted['suggested_mapping'][CurriculumSpreadsheetParser::FieldTitle]);
        $this->assertNotNull($dhmExtracted['suggested_mapping'][CurriculumSpreadsheetParser::FieldCreditUnits]);
        $this->assertNotNull($dhmExtracted['suggested_mapping'][CurriculumSpreadsheetParser::FieldLecHours]);
        $this->assertNotNull($dhmExtracted['suggested_mapping'][CurriculumSpreadsheetParser::FieldLabHours]);
        $this->assertNotNull($dhmExtracted['suggested_mapping'][CurriculumSpreadsheetParser::FieldPrerequisites]);
        $this->assertNotNull($dhmExtracted['suggested_mapping'][CurriculumSpreadsheetParser::FieldAcademicClassification]);
        // "Subject Description" maps to title, NOT description; extra columns "Grade" and "Remarks" remain unmapped.
        $this->assertNull($dhmExtracted['suggested_mapping'][CurriculumSpreadsheetParser::FieldDescription]);

        // Extract DIT sheet (sheet 2: uses "Subject Code" header).
        $ditExtracted = $parser->extractSheet($xlsxPath, 2);
        $this->assertTrue($ditExtracted['detected_banners']);
        $this->assertNotNull($ditExtracted['suggested_mapping'][CurriculumSpreadsheetParser::FieldCode]);

        // 3. Parse DHM rows and verify banner tracking, skipped summary/formula/bridging/footer rows, and classifications.
        $parsedRows = $parser->parseRows($xlsxPath, 0, $dhmExtracted['suggested_mapping']);
        $parsedCollection = collect($parsedRows);

        // Non-subject rows (TOTAL UNITS, =SUM(...), BRIDGING SUBJECT, ASSESSMENT EVALUATION, Prepared By:) are skipped.
        $allTitles = array_column($parsedRows, 'title');
        $this->assertNotContains('TOTAL UNITS', $allTitles);
        $this->assertNotContains('BRIDGING SUBJECT', $allTitles);
        $this->assertNotContains('Prepared By: Registrar', $allTitles);

        // THC 2: First Year - First Semester, Lec 3, Lab 0, Total Units 3, Resultant NC "x" ignored -> ORDINARY.
        $thc2 = $parsedCollection->firstWhere('code', 'THC 2');
        $this->assertNotNull($thc2);
        $this->assertSame(1, $thc2['year_level']);
        $this->assertSame(Term::TypeFirstSemester, $thc2['term_type']);
        $this->assertSame(3.0, $thc2['lec_hours']);
        $this->assertSame(0.0, $thc2['lab_hours']);
        $this->assertSame(3.0, $thc2['credit_units']);
        $this->assertSame(CourseSpecification::AcademicClassificationOrdinary, $thc2['academic_classification']);

        // HPC 2: First Year - Second Semester, Lec 2 + Lab 3 -> Total Units 3, Resultant NC "FBS NC II" -> TESDA_COMPETENCY, Prereq "THC2" (whitespace normalized) + "1st Year Completer" (non-subject note).
        $hpc2 = $parsedCollection->firstWhere('code', 'HPC 2');
        $this->assertNotNull($hpc2);
        $this->assertSame(1, $hpc2['year_level']);
        $this->assertSame(Term::TypeSecondSemester, $hpc2['term_type']);
        $this->assertSame(2.0, $hpc2['lec_hours']);
        $this->assertSame(3.0, $hpc2['lab_hours']);
        $this->assertSame(3.0, $hpc2['credit_units']);
        $this->assertSame(CourseSpecification::AcademicClassificationTesdaCompetency, $hpc2['academic_classification']);
        $this->assertSame(CourseSpecification::SchedulingRecurring, $hpc2['scheduling_treatment']);

        // CC 102: Resultant NC "MR. NICASIO" & "TAKEN" ignored -> ORDINARY, self-prerequisite "CC 102" warned & ignored.
        $cc102 = $parsedCollection->firstWhere('code', 'CC 102');
        $this->assertNotNull($cc102);
        $this->assertSame(CourseSpecification::AcademicClassificationOrdinary, $cc102['academic_classification']);

        // PRAC 1: 2nd Year - SUMMER TERM, Lab "300 hours", Total Units 6 -> EXTERNALLY_ARRANGED, 0 weekly recurring hours.
        $prac1 = $parsedCollection->firstWhere('code', 'PRAC 1');
        $this->assertNotNull($prac1);
        $this->assertSame(2, $prac1['year_level']);
        $this->assertSame(Term::TypeSpecialTerm, $prac1['term_type']);
        $this->assertSame(300.0, $prac1['practicum_hours']);
        $this->assertSame(0.0, $prac1['lec_hours']);
        $this->assertSame(0.0, $prac1['lab_hours']);
        $this->assertSame(6.0, $prac1['credit_units']);
        $this->assertSame(CourseSpecification::SchedulingExternallyArranged, $prac1['scheduling_treatment']);

        // 4. Build preview against the Draft curriculum and verify blocked rows & warnings.
        $preview = $importService->buildPreview($parsedRows, $draft);
        $previewRows = collect($preview['rows']);

        // Missing code row -> blocked.
        $missingCodeRow = $previewRows->first(fn (array $row): bool => $row['title'] === 'Mystery Subject Without Code');
        $this->assertNotNull($missingCodeRow);
        $this->assertSame(CurriculumSpreadsheetImportService::StatusBlocked, $missingCodeRow['status']);
        $this->assertContains('Missing subject code.', $missingCodeRow['errors']);

        // Missing units row (0 lec, 0 lab, blank units) -> blocked.
        $missingUnitsRow = $previewRows->firstWhere('code', 'HM 999');
        $this->assertNotNull($missingUnitsRow);
        $this->assertSame(CurriculumSpreadsheetImportService::StatusBlocked, $missingUnitsRow['status']);
        $this->assertContains('Missing units.', $missingUnitsRow['errors']);

        // Duplicate code in same file (second THC 2 row) -> blocked.
        $duplicateInFileRow = $previewRows
            ->filter(fn (array $row): bool => $row['code'] === 'THC 2')
            ->last();
        $this->assertNotNull($duplicateInFileRow);
        $this->assertSame(CurriculumSpreadsheetImportService::StatusBlocked, $duplicateInFileRow['status']);
        $this->assertTrue(collect($duplicateInFileRow['errors'])->contains(fn (string $err): bool => str_contains($err, 'Duplicate subject code')));

        // Already in curriculum (GE 100) -> calm non-blocking already_in_draft skip in add_only mode.
        $alreadyInCurriculumRow = $previewRows->firstWhere('code', 'GE 100');
        $this->assertNotNull($alreadyInCurriculumRow);
        $this->assertSame(CurriculumSpreadsheetImportService::StatusAlreadyInDraft, $alreadyInCurriculumRow['status']);
        $this->assertTrue($alreadyInCurriculumRow['skip']);
        $this->assertSame(1, $preview['summary']['already_in_draft_count']);

        // Self-prerequisite on CC 102 -> ready_new with warning, not linked in resolved_prerequisites.
        $cc102Preview = $previewRows->firstWhere('code', 'CC 102');
        $this->assertNotNull($cc102Preview);
        $this->assertSame(CurriculumSpreadsheetImportService::StatusReadyNew, $cc102Preview['status']);
        $this->assertCount(0, $cc102Preview['resolved_prerequisites']);
        $this->assertTrue(collect($cc102Preview['warnings'])->contains(fn (string $w): bool => str_contains(strtolower($w), 'listed itself as a prerequisite')));

        // Whitespace-normalized prerequisite ("THC2" -> "THC 2") + non-subject prerequisite ("1st Year Completer", "NCII") on HPC 2.
        $hpc2Preview = $previewRows->firstWhere('code', 'HPC 2');
        $this->assertNotNull($hpc2Preview);
        $this->assertSame(CurriculumSpreadsheetImportService::StatusReadyNew, $hpc2Preview['status']);
        $this->assertSame(['THC 2'], array_column($hpc2Preview['resolved_prerequisites'], 'code'));
        $this->assertTrue(collect($hpc2Preview['warnings'])->contains(fn (string $w): bool => str_contains($w, '1st Year Completer')));
        $this->assertTrue(collect($hpc2Preview['warnings'])->contains(fn (string $w): bool => str_contains($w, 'NCII')));

        // 5. Commit valid rows (skipping blocked rows) and verify created Course, CourseSpecification, CourseComponent, and CourseRequirement rows.
        $commitResult = $importService->commitImport($registrar, $preview['rows'], $draft, skipBlocked: true);
        $this->assertSame(4, $commitResult['created_subjects']);
        $this->assertSame(4, $commitResult['placed_entries']);
        $this->assertCount(4, $commitResult['skipped_rows']);

        // Verify HPC 2 has LECTURE (2h) + LABORATORY (3h), total credit_units = 3, TESDA_COMPETENCY, and prerequisite link to THC 2.
        $hpc2Course = Course::query()->where('code', 'HPC 2')->sole();
        $thc2Course = Course::query()->where('code', 'THC 2')->sole();
        $hpc2Spec = $hpc2Course->specifications()->sole();

        $this->assertEquals(3.0, (float) $hpc2Spec->credit_units);
        $this->assertSame(CourseSpecification::AcademicClassificationTesdaCompetency, $hpc2Spec->academic_classification);
        $this->assertSame(CourseSpecification::SchedulingRecurring, $hpc2Spec->scheduling_treatment);

        $components = $hpc2Spec->components()->orderBy('sequence')->get();
        $this->assertCount(2, $components);
        $this->assertSame(CourseComponent::TypeLecture, $components[0]->component_type);
        $this->assertEquals(2.0, (float) $components[0]->weekly_contact_hours);
        $this->assertSame(CourseComponent::TypeLaboratory, $components[1]->component_type);
        $this->assertEquals(3.0, (float) $components[1]->weekly_contact_hours);

        $prereqIds = $hpc2Spec->requirements()
            ->where('rule_type', CourseRequirement::TypePrerequisite)
            ->pluck('related_course_id')
            ->all();
        $this->assertSame([$thc2Course->id], $prereqIds);

        // Verify CC 102 did NOT self-link.
        $cc102Course = Course::query()->where('code', 'CC 102')->sole();
        $this->assertCount(0, $cc102Course->specifications()->sole()->requirements);

        // Verify PRAC 1 is EXTERNALLY_ARRANGED in Year 2 Summer with 0 recurring components.
        $prac1Course = Course::query()->where('code', 'PRAC 1')->sole();
        $prac1Spec = $prac1Course->specifications()->sole();
        $this->assertSame(CourseSpecification::SchedulingExternallyArranged, $prac1Spec->scheduling_treatment);
        $this->assertCount(0, $prac1Spec->components);

        $prac1Entry = $draft->entries()->where('course_specification_id', $prac1Spec->id)->sole();
        $this->assertSame('2', (string) $prac1Entry->year_level);
        $this->assertSame(Term::TypeSpecialTerm, $prac1Entry->term_type);

        // Verify ImportModeReplaceDraft replaces all existing entries in the editable draft atomically.
        $replaceCsv = $this->createCsvFile([
            ['Subject Code', 'Subject Name', 'Units', 'Lec Hours/Week', 'Lab Hours/Week', 'Year Level', 'Semester'],
            ['REP 101', 'Replaced Foundation Subject', 3, 3, 0, 1, '1st Semester'],
        ]);
        $replaceParsed = $parser->parseRows($replaceCsv, 0, $parser->extractSheet($replaceCsv, 0)['suggested_mapping'], 'csv');
        $replacePreview = $importService->buildPreview(
            $replaceParsed,
            $draft,
            importMode: CurriculumSpreadsheetImportService::ImportModeReplaceDraft,
        );
        $this->assertSame(1, $replacePreview['summary']['ready_count']);
        $replaceCommit = $importService->commitImport(
            $registrar,
            $replacePreview['rows'],
            $draft,
            skipBlocked: true,
            importMode: CurriculumSpreadsheetImportService::ImportModeReplaceDraft,
        );
        $this->assertGreaterThanOrEqual(4, $replaceCommit['replaced_existing_entries']);
        $this->assertSame(['REP 101'], $draft->entries()->with('courseSpecification.course')->get()->pluck('courseSpecification.course.code')->all());
    }

    public function test_full_page_import_routes_and_cross_year_bulk_move_and_remove_work_for_draft_curricula(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $program = Program::factory()->create([
            'code' => 'DIT-BLK',
            'name' => 'Diploma in IT Bulk Test',
            'duration_years' => 3,
            'is_active' => true,
        ]);

        $draft = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateDraft, 'name' => 'Bulk Edit Draft']);
        $active = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive, 'name' => 'Locked Active']);

        // Full-page import routes load for Registrar on catalog and Draft curriculum, and 403 on Active curriculum.
        Livewire::test(ImportCatalogSubjects::class)->assertOk();
        Livewire::test(ImportCurriculumSpreadsheet::class, ['record' => $draft->id])->assertOk();
        Livewire::test(ImportCurriculumSpreadsheet::class, ['record' => $active->id])->assertForbidden();

        // Seed 2 entries across Year 1 and Year 2 for cross-year multi-select testing.
        $courseA = Course::factory()->create(['code' => 'BLK 101', 'state' => Course::StateActive]);
        $specA = CourseSpecification::factory()->for($courseA)->create(['state' => CourseSpecification::StateActive, 'title' => 'Block Subject One', 'credit_units' => 3]);
        $courseB = Course::factory()->create(['code' => 'BLK 201', 'state' => Course::StateActive]);
        $specB = CourseSpecification::factory()->for($courseB)->create(['state' => CourseSpecification::StateActive, 'title' => 'Block Subject Two', 'credit_units' => 3]);

        $entryA = CurriculumEntry::factory()->for($draft)->for($specA)->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
            'term_label' => 'First Semester',
            'sequence' => 1,
        ]);
        $entryB = CurriculumEntry::factory()->for($draft)->for($specB)->create([
            'year_level' => '2',
            'term_type' => Term::TypeFirstSemester,
            'term_label' => 'First Semester',
            'sequence' => 1,
        ]);

        // Select across Year 1 and Year 2, move both to Year 3 2nd Semester, then remove both.
        Livewire::test(ReviewCurriculumVersion::class, ['record' => $draft->id])
            ->call('toggleEntrySelection', $entryA->id)
            ->call('selectYear', 2)
            ->call('toggleEntrySelection', $entryB->id)
            ->assertSet('selectedEntryIds', [$entryA->id, $entryB->id])
            ->callAction('bulkMoveSelectedEntries', data: [
                'year_level' => 3,
                'term_type' => Term::TypeSecondSemester,
                'confirm_move' => true,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('3', (string) $entryA->fresh()->year_level);
        $this->assertSame(Term::TypeSecondSemester, $entryA->fresh()->term_type);
        $this->assertSame('3', (string) $entryB->fresh()->year_level);
        $this->assertSame(Term::TypeSecondSemester, $entryB->fresh()->term_type);

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $draft->id])
            ->call('toggleSemesterSelection', 3, Term::TypeSecondSemester)
            ->assertSet('selectedEntryIds', [$entryA->id, $entryB->id])
            ->callAction('bulkRemoveSelectedEntries')
            ->assertHasNoActionErrors()
            ->assertSet('selectedEntryIds', []);

        $this->assertSame(0, $draft->entries()->count());

        // Read-only Academic Head gets 403 on both full-page import routes.
        $this->staff(User::StaffRoleAcademicHead);
        Livewire::test(ImportCatalogSubjects::class)->assertForbidden();
        Livewire::test(ImportCurriculumSpreadsheet::class, ['record' => $draft->id])->assertForbidden();
    }

    public function test_inline_row_overrides_fix_blocked_rows_and_skip_flow_exports_formula_safe_skipped_csv(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $importService = app(CurriculumSpreadsheetImportService::class);

        $program = Program::factory()->create(['code' => 'DBM-FIX', 'duration_years' => 3, 'is_active' => true]);
        $draft = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateDraft]);

        $parsedRows = [
            [
                'row_key' => 'row_2',
                'source_row' => 2,
                'code' => 'BM 101',
                'normalized_code' => 'BM101',
                'title' => 'Principles of Management',
                'credit_units' => 3.0,
                'units_inferred' => false,
                'lec_hours' => 3.0,
                'lab_hours' => 0.0,
                'practicum_hours' => null,
                'year_level' => 1,
                'term_type' => Term::TypeFirstSemester,
                'raw_prerequisites' => [],
                'prerequisites_text' => '',
                'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
                'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
                'description' => null,
                'parser_warnings' => [],
            ],
            [
                'row_key' => 'row_3',
                'source_row' => 3,
                'code' => '',
                'normalized_code' => '',
                'title' => 'Financial Accounting 1',
                'credit_units' => null,
                'units_inferred' => false,
                'lec_hours' => 3.0,
                'lab_hours' => 0.0,
                'practicum_hours' => null,
                'year_level' => null,
                'term_type' => null,
                'raw_prerequisites' => ['BM 101'],
                'prerequisites_text' => 'BM 101',
                'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
                'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
                'description' => null,
                'parser_warnings' => [],
            ],
            [
                'row_key' => 'row_4',
                'source_row' => 4,
                'code' => '',
                'normalized_code' => '',
                'title' => '=CMD("calc.exe")',
                'credit_units' => null,
                'units_inferred' => false,
                'lec_hours' => 0.0,
                'lab_hours' => 0.0,
                'practicum_hours' => null,
                'year_level' => 1,
                'term_type' => Term::TypeFirstSemester,
                'raw_prerequisites' => [],
                'prerequisites_text' => '',
                'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
                'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
                'description' => null,
                'parser_warnings' => [],
            ],
        ];

        $initialPreview = $importService->buildPreview($parsedRows, $draft);
        $this->assertSame(1, $initialPreview['summary']['ready_new']);
        $this->assertSame(2, $initialPreview['summary']['blocked_count']);

        // When skipBlocked is false, committing with blocked rows throws ValidationException.
        try {
            $importService->commitImport($registrar, $initialPreview['rows'], $draft, skipBlocked: false);
            $this->fail('Expected commitImport with skipBlocked=false to reject unresolved blocked rows.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('rows', $e->errors());
        }

        // Fix row_3 via inline overrides (providing code, credit_units, year_level, term_type).
        $fixedPreview = $importService->buildPreview($parsedRows, $draft, [
            [
                'row_key' => 'row_3',
                'code' => 'BM 102',
                'title' => 'Financial Accounting 1',
                'credit_units' => 3,
                'year_level' => 1,
                'term_type' => Term::TypeSecondSemester,
                'skip' => false,
            ],
        ]);

        $this->assertSame(2, $fixedPreview['summary']['ready_new']);
        $this->assertSame(1, $fixedPreview['summary']['blocked_count']);

        // Commit with skipBlocked=true: BM 101 and fixed BM 102 import; row_4 is skipped and exported to a formula-safe CSV.
        $result = $importService->commitImport($registrar, $fixedPreview['rows'], $draft, skipBlocked: true);
        $this->assertSame(2, $result['created_subjects']);
        $this->assertSame(2, $result['placed_entries']);
        $this->assertSame(1, $result['linked_prerequisites']);
        $this->assertCount(1, $result['skipped_rows']);

        $skippedCsvPath = $importService->exportSkippedRowsCsv($result['skipped_rows']);
        $this->tempPaths[] = $skippedCsvPath;
        $this->assertFileExists($skippedCsvPath);

        $csvContents = (string) file_get_contents($skippedCsvPath);
        $this->assertStringContainsString("'=CMD(\"\"calc.exe\"\")", $csvContents);
        $this->assertStringContainsString('Missing subject code.', $csvContents);
    }

    public function test_livewire_list_courses_and_review_curriculum_version_import_template_and_export_actions(): void
    {
        $this->staff(User::StaffRoleRegistrar);

        // 1. ListCourses (Subjects Catalog): template download, importSubjects, skipped CSV download, and export (.xlsx / .csv).
        $catalogCsvPath = $this->createCsvFile([
            ['Subject Code', 'Subject Name', 'Total Units', 'Lec Hours/Week', 'Lab Hours/Week', 'Prerequisites', 'Subject Type', 'Schedule Type', 'Description'],
            ['CAT-201', 'Catalog Imported Subject 1', 3, 3, 0, 'None', 'Regular', 'Recurring', 'First imported catalog subject'],
            ['CAT-202', 'Catalog Imported Lab Subject', 3, 2, 3, 'CAT-201', 'TESDA Competency', 'Recurring', 'Second imported catalog subject'],
            ['', 'Blocked Row Without Code', '', 0, 0, '', 'Regular', 'Recurring', 'Should be skipped'],
        ]);

        $catalogUpload = UploadedFile::fake()->createWithContent(
            'catalog-subjects.csv',
            (string) file_get_contents($catalogCsvPath),
        );

        $listCourses = Livewire::test(ListCourses::class)
            ->assertActionVisible('importSubjects')
            ->assertActionVisible('exportXlsx')
            ->assertActionVisible('exportCsv')
            ->callAction('importSubjects', data: [
                'file' => $catalogUpload,
                'skip_blocked' => true,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('courses', ['code' => 'CAT-201']);
        $this->assertDatabaseHas('courses', ['code' => 'CAT-202']);
        $this->assertNotNull($listCourses->get('lastSkippedImportCsvPath'));
        $this->tempPaths[] = (string) $listCourses->get('lastSkippedImportCsvPath');

        $templateResponse = $listCourses->instance()->downloadTemplate('xlsx');
        $this->tempPaths[] = $templateResponse->getFile()->getPathname();
        $this->assertSame('subjects-import-template.xlsx', $templateResponse->getFile()->getFilename());

        $exportResponse = $listCourses->instance()->exportCatalogSubjects('csv');
        $this->tempPaths[] = $exportResponse->getFile()->getPathname();
        $this->assertStringEndsWith('.csv', $exportResponse->getFile()->getFilename());

        // 2. ReviewCurriculumVersion on Draft vs Active vs Retired curriculum.
        $program = Program::factory()->create(['code' => 'DIT-LIVE', 'duration_years' => 3, 'is_active' => true]);
        $draftCurriculum = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'DIT-DRAFT-1',
            'state' => CurriculumVersion::StateDraft,
        ]);

        $curriculumCsvPath = $this->createCsvFile([
            ['Subject Code', 'Subject Name', 'Year Level', 'Semester', 'Total Units', 'Lec Hours/Week', 'Lab Hours/Week', 'Prerequisites', 'Subject Type', 'Schedule Type'],
            ['CAT-201', 'Catalog Imported Subject 1', 1, 'First Semester', 3, 3, 0, 'None', 'Regular', 'Recurring'],
            ['DIT-301', 'Systems Integration & Architecture', 2, 'Summer', 3, 2, 3, 'CAT-201', 'Regular', 'Recurring'],
        ]);

        $curriculumUpload = UploadedFile::fake()->createWithContent(
            'dit-curriculum.csv',
            (string) file_get_contents($curriculumCsvPath),
        );

        $draftPage = Livewire::test(ReviewCurriculumVersion::class, ['record' => $draftCurriculum->getRouteKey()])
            ->assertActionVisible('importSpreadsheet')
            ->assertActionVisible('exportXlsx')
            ->assertActionVisible('exportCsv')
            ->callAction('importSpreadsheet', data: [
                'file' => $curriculumUpload,
                'skip_blocked' => true,
            ])
            ->assertHasNoActionErrors();

        $draftCurriculum->refresh();
        $this->assertCount(2, $draftCurriculum->entries);
        $this->assertContains(2, $draftPage->get('enabledSummerYears'));

        $curriculumExportXlsx = $draftPage->instance()->exportCurriculumSpreadsheet('xlsx');
        $this->tempPaths[] = $curriculumExportXlsx->getFile()->getPathname();
        $this->assertStringEndsWith('.xlsx', $curriculumExportXlsx->getFile()->getFilename());

        // Activate the curriculum -> importSpreadsheet MUST be hidden on Active curriculum, while Export remains available.
        $activeCurriculum = app(CurriculumVersionLifecycleService::class)->activate(
            actor: auth()->user(),
            curriculumVersion: $draftCurriculum,
        );

        $activePage = Livewire::test(ReviewCurriculumVersion::class, ['record' => $activeCurriculum->getRouteKey()])
            ->assertActionHidden('importSpreadsheet')
            ->assertActionVisible('exportXlsx')
            ->assertActionVisible('exportCsv');

        $activeExportCsv = $activePage->instance()->exportCurriculumSpreadsheet('csv');
        $this->tempPaths[] = $activeExportCsv->getFile()->getPathname();
        $this->assertStringEndsWith('.csv', $activeExportCsv->getFile()->getFilename());

        // Retire the curriculum -> importSpreadsheet MUST remain hidden on Retired curriculum, while Export remains available.
        $retiredCurriculum = app(CurriculumVersionLifecycleService::class)->retire(
            actor: auth()->user(),
            curriculum: $activeCurriculum,
        );

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $retiredCurriculum->getRouteKey()])
            ->assertActionHidden('importSpreadsheet')
            ->assertActionVisible('exportXlsx')
            ->assertActionVisible('exportCsv');
    }

    public function test_authorization_guards_active_specification_preservation_and_formula_injection_neutralization(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $importService = app(CurriculumSpreadsheetImportService::class);
        $exportService = app(CurriculumSpreadsheetExportService::class);

        $program = Program::factory()->create(['code' => 'BSIT-SAFE', 'duration_years' => 4, 'is_active' => true]);
        $draftCurriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateDraft]);
        $activeCurriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $retiredCurriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateSuperseded]);

        // Existing catalog course with an Active specification MUST NEVER be overwritten when matched during import.
        $protectedCourse = Course::factory()->create(['code' => 'SAFE-101']);
        $protectedActiveSpec = CourseSpecification::factory()->for($protectedCourse)->create([
            'title' => 'Authoritative Active Title',
            'credit_units' => 3.0,
            'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
            'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
            'state' => CourseSpecification::StateActive,
        ]);
        CourseComponent::factory()->for($protectedActiveSpec)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 3,
            'meeting_pattern' => '2x90',
            'room_type_default' => CourseComponent::RoomTypeLectureRoom,
        ]);

        $attemptOverwriteRows = [
            [
                'row_key' => 'row_1',
                'source_row' => 1,
                'code' => 'SAFE-101',
                'normalized_code' => 'SAFE101',
                'title' => 'Attempted Spreadsheet Overwrite Title',
                'credit_units' => 5.0,
                'units_inferred' => false,
                'lec_hours' => 2.0,
                'lab_hours' => 6.0,
                'practicum_hours' => null,
                'year_level' => 1,
                'term_type' => Term::TypeFirstSemester,
                'raw_prerequisites' => [],
                'prerequisites_text' => '',
                'academic_classification' => CourseSpecification::AcademicClassificationTesdaCompetency,
                'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
                'description' => 'Should never overwrite active spec',
                'parser_warnings' => [],
            ],
        ];

        $preview = $importService->buildPreview($attemptOverwriteRows, $draftCurriculum);
        $this->assertSame(1, $preview['summary']['ready_existing']);
        $this->assertSame($protectedActiveSpec->id, $preview['rows'][0]['matched_specification_id']);

        $commitResult = $importService->commitImport($registrar, $preview['rows'], $draftCurriculum);
        $this->assertSame(0, $commitResult['created_subjects']);
        $this->assertSame(1, $commitResult['linked_existing']);
        $this->assertSame(1, $commitResult['placed_entries']);

        // Verify the Active specification was NOT mutated or forked on import.
        $protectedActiveSpec->refresh();
        $this->assertSame('Authoritative Active Title', $protectedActiveSpec->title);
        $this->assertEquals(3.0, (float) $protectedActiveSpec->credit_units);
        $this->assertSame(CourseSpecification::StateActive, $protectedActiveSpec->state);
        $this->assertSame(1, $protectedCourse->specifications()->count());
        $this->assertSame($protectedActiveSpec->id, $draftCurriculum->entries()->sole()->course_specification_id);

        // Registrar cannot import into an Active or Retired curriculum.
        try {
            $importService->commitImport($registrar, $preview['rows'], $activeCurriculum);
            $this->fail('Expected Active curriculum to reject commitImport.');
        } catch (AuthorizationException) {
            $this->assertSame(0, $activeCurriculum->entries()->count());
        }

        try {
            $importService->commitImport($registrar, $preview['rows'], $retiredCurriculum);
            $this->fail('Expected Retired curriculum to reject commitImport.');
        } catch (AuthorizationException) {
            $this->assertSame(0, $retiredCurriculum->entries()->count());
        }

        // Read-only Academic Head cannot import into catalog or draft curriculum (403).
        $academicHead = $this->staff(User::StaffRoleAcademicHead);

        Livewire::test(ListCourses::class)
            ->assertActionHidden('importSubjects')
            ->assertActionVisible('exportXlsx');

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $draftCurriculum->getRouteKey()])
            ->assertActionHidden('importSpreadsheet')
            ->assertActionVisible('exportXlsx');

        try {
            $importService->commitImport($academicHead, $preview['rows'], null);
            $this->fail('Expected Academic Head catalog import to throw AuthorizationException.');
        } catch (AuthorizationException) {
            // Expected 403.
        }

        try {
            $importService->commitImport($academicHead, $preview['rows'], $draftCurriculum);
            $this->fail('Expected Academic Head draft curriculum import to throw AuthorizationException.');
        } catch (AuthorizationException) {
            // Expected 403.
        }

        // Formula injection neutralization on export (`=CMD()`, `+1`, `-1`, `@SUM`).
        $dangerousCourse = Course::factory()->create(['code' => 'SAFE-INJ']);
        $dangerousSpec = CourseSpecification::factory()->for($dangerousCourse)->create([
            'title' => '=CMD("powershell")',
            'description' => '@SUM(1+1)',
            'credit_units' => 3.0,
            'state' => CourseSpecification::StateActive,
        ]);
        CurriculumEntry::factory()->for($draftCurriculum)->for($dangerousSpec, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeSecondSemester,
            'term_label' => 'Second Semester',
            'sequence' => 1,
        ]);

        $this->assertSame("'=CMD()", CurriculumSpreadsheetExportService::neutralizeFormulaInjection('=CMD()'));
        $this->assertSame("'+1", CurriculumSpreadsheetExportService::neutralizeFormulaInjection('+1'));
        $this->assertSame("'-1", CurriculumSpreadsheetExportService::neutralizeFormulaInjection('-1'));
        $this->assertSame("'@SUM", CurriculumSpreadsheetExportService::neutralizeFormulaInjection('@SUM'));

        $exportedCsvPath = $exportService->exportCurriculum($draftCurriculum->fresh(), 'csv');
        $this->tempPaths[] = $exportedCsvPath;
        $exportedCsvText = (string) file_get_contents($exportedCsvPath);
        $this->assertStringContainsString("'=CMD(\"\"powershell\"\")", $exportedCsvText);
        $this->assertStringContainsString("'@SUM(1+1)", $exportedCsvText);
    }

    public function test_round_trip_curriculum_export_and_reimport_for_xlsx_and_csv_preserves_subjects_components_placements_and_prerequisites(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $parser = app(CurriculumSpreadsheetParser::class);
        $importService = app(CurriculumSpreadsheetImportService::class);
        $exportService = app(CurriculumSpreadsheetExportService::class);

        $program = Program::factory()->create([
            'code' => 'DHM-RT',
            'name' => 'Diploma in Hospitality Management (Round Trip)',
            'duration_years' => 3,
            'is_active' => true,
        ]);

        $sourceCurriculum = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'DHM-RT-SOURCE',
            'state' => CurriculumVersion::StateDraft,
        ]);

        // Seed source curriculum via import so it has canonical subjects, Lec+Lab components, Summer practicum, and prerequisites.
        $seedRows = [
            [
                'row_key' => 'row_1',
                'source_row' => 1,
                'code' => 'RT GE 1',
                'normalized_code' => 'RTGE1',
                'title' => 'Understanding the Self',
                'credit_units' => 3.0,
                'units_inferred' => false,
                'lec_hours' => 3.0,
                'lab_hours' => 0.0,
                'practicum_hours' => null,
                'year_level' => 1,
                'term_type' => Term::TypeFirstSemester,
                'raw_prerequisites' => [],
                'prerequisites_text' => '',
                'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
                'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
                'description' => 'Core GE lecture',
                'parser_warnings' => [],
            ],
            [
                'row_key' => 'row_2',
                'source_row' => 2,
                'code' => 'RT HPC 2',
                'normalized_code' => 'RTHPC2',
                'title' => 'Kitchen Essentials & Food Preparation',
                'credit_units' => 3.0,
                'units_inferred' => false,
                'lec_hours' => 2.0,
                'lab_hours' => 3.0,
                'practicum_hours' => null,
                'year_level' => 1,
                'term_type' => Term::TypeSecondSemester,
                'raw_prerequisites' => ['RT GE 1'],
                'prerequisites_text' => 'RT GE 1',
                'academic_classification' => CourseSpecification::AcademicClassificationTesdaCompetency,
                'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
                'description' => 'Cookery NC II aligned',
                'parser_warnings' => [],
            ],
            [
                'row_key' => 'row_3',
                'source_row' => 3,
                'code' => 'RT PRAC 1',
                'normalized_code' => 'RTPRAC1',
                'title' => 'Supervised Work-Related Training (300 hours)',
                'credit_units' => 6.0,
                'units_inferred' => false,
                'lec_hours' => 0.0,
                'lab_hours' => 0.0,
                'practicum_hours' => 300.0,
                'year_level' => 2,
                'term_type' => Term::TypeSpecialTerm,
                'raw_prerequisites' => ['RT HPC 2'],
                'prerequisites_text' => 'RT HPC 2',
                'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
                'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
                'description' => 'Hospitality immersion',
                'parser_warnings' => [],
            ],
        ];

        $seedPreview = $importService->buildPreview($seedRows, $sourceCurriculum);
        $importService->commitImport($registrar, $seedPreview['rows'], $sourceCurriculum);
        $sourceCurriculum->refresh();

        // Export source curriculum in both .xlsx and .csv formats.
        $xlsxExportPath = $exportService->exportCurriculum($sourceCurriculum, 'xlsx');
        $csvExportPath = $exportService->exportCurriculum($sourceCurriculum, 'csv');
        $this->tempPaths[] = $xlsxExportPath;
        $this->tempPaths[] = $csvExportPath;

        // Re-import the .xlsx export into a brand-new Draft curriculum.
        $targetXlsxDraft = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'DHM-RT-XLSX',
            'state' => CurriculumVersion::StateDraft,
        ]);
        $xlsxParsed = $parser->parseRows($xlsxExportPath, 0);
        $xlsxPreview = $importService->buildPreview($xlsxParsed, $targetXlsxDraft);
        $this->assertSame(0, $xlsxPreview['summary']['blocked_count']);
        $xlsxResult = $importService->commitImport($registrar, $xlsxPreview['rows'], $targetXlsxDraft);
        $this->assertSame(3, $xlsxResult['placed_entries']);

        // Re-import the .csv export into another brand-new Draft curriculum.
        $targetCsvDraft = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'DHM-RT-CSV',
            'state' => CurriculumVersion::StateDraft,
        ]);
        $csvParsed = $parser->parseRows($csvExportPath, 0);
        $csvPreview = $importService->buildPreview($csvParsed, $targetCsvDraft);
        $this->assertSame(0, $csvPreview['summary']['blocked_count']);
        $csvResult = $importService->commitImport($registrar, $csvPreview['rows'], $targetCsvDraft);
        $this->assertSame(3, $csvResult['placed_entries']);

        // Assert identical curriculum snapshot across Source, XLSX Re-Import, and CSV Re-Import.
        $sourceSnapshot = $this->curriculumSnapshot($sourceCurriculum->fresh());
        $xlsxSnapshot = $this->curriculumSnapshot($targetXlsxDraft->fresh());
        $csvSnapshot = $this->curriculumSnapshot($targetCsvDraft->fresh());

        $this->assertSame($sourceSnapshot, $xlsxSnapshot);
        $this->assertSame($sourceSnapshot, $csvSnapshot);
    }

    /**
     * Build a canonical comparison snapshot of a curriculum's entries, units, components, classifications, and prerequisites.
     *
     * @return list<array<string, mixed>>
     */
    private function curriculumSnapshot(CurriculumVersion $curriculum): array
    {
        $curriculum->load([
            'entries.courseSpecification.course',
            'entries.courseSpecification.components',
            'entries.courseSpecification.requirements.relatedCourse',
        ]);

        return $curriculum->entries
            ->sortBy(fn (CurriculumEntry $e): array => [(int) $e->year_level, (string) $e->term_type, (int) $e->sequence])
            ->values()
            ->map(function (CurriculumEntry $entry): array {
                $spec = $entry->courseSpecification;

                return [
                    'code' => (string) $spec->course->code,
                    'title' => (string) $spec->title,
                    'year_level' => (int) $entry->year_level,
                    'term_type' => (string) $entry->term_type,
                    'credit_units' => (float) $spec->credit_units,
                    'academic_classification' => (string) $spec->academic_classification,
                    'scheduling_treatment' => (string) $spec->scheduling_treatment,
                    'components' => $spec->components
                        ->sortBy('sequence')
                        ->values()
                        ->map(fn (CourseComponent $c): array => [
                            'type' => (string) $c->component_type,
                            'hours' => (float) $c->weekly_contact_hours,
                        ])
                        ->all(),
                    'prerequisites' => $spec->requirements
                        ->where('rule_type', CourseRequirement::TypePrerequisite)
                        ->sortBy('sequence')
                        ->values()
                        ->map(fn (CourseRequirement $r): string => (string) $r->relatedCourse->code)
                        ->all(),
                ];
            })
            ->all();
    }

    /**
     * Generate a synthetic multi-sheet `.xlsx` file mirroring Servitech's real DHM/DBM/DIT curriculum workbook layout.
     */
    private function createSyntheticServitechWorkbook(): string
    {
        $dir = storage_path('app/private/exports');
        File::ensureDirectoryExists($dir);
        $path = $dir.DIRECTORY_SEPARATOR.'test-servitech-workbook-'.uniqid('', true).'.xlsx';
        $this->tempPaths[] = $path;

        $writer = new XlsxWriter;
        $writer->openToFile($path);

        // Sheet 1: DHM (uses "CODE" header, banners, Resultant NC, Grade, Remarks).
        $dhmSheet = $writer->getCurrentSheet();
        $dhmSheet->setName('DHM');

        $dhmRows = [
            ['SERVITECH INSTITUTE OF TECHNOLOGY'],
            ['PROGRAM CURRICULUM'],
            ['DIPLOMA IN HOSPITALITY MANAGEMENT (DHM)'],
            ['EFFECTIVE A.Y. 2025-2026'],
            [],
            ['First Year - First Semester'],
            ['CODE', 'Subject Description', 'Lec Units/No. of Hours Per Week', 'Lab Units/No. of Hours Per Week', 'Total No. of Units', 'Pre-Requisite', 'Resultant NC', 'Grade', 'Remarks'],
            ['THC 2', 'Risk Management as Applied to Safety, Security and Sanitation', 3, 0, 3, 'None', 'x', '1.25', 'PASSED'],
            ['GE 100', 'Purposive Communication', 3, 0, 3, 'None', 'TAKEN', '1.50', 'PASSED'],
            ['', 'Mystery Subject Without Code', 3, 0, 3, 'None', '', '', ''],
            ['HM 999', 'Subject With Missing Units', 0, 0, '', 'None', '', '', ''],
            ['', 'TOTAL UNITS', 9, 0, '=SUM(E8:E11)', '', '', '', ''],
            ['BRIDGING SUBJECT', 'Basic English Bridging', 3, 0, 3, '', '', '', ''],
            [],
            ['First Year - Second Semester'],
            ['CODE', 'Subject Description', 'Lec Units/No. of Hours Per Week', 'Lab Units/No. of Hours Per Week', 'Total No. of Units', 'Pre-Requisite', 'Resultant NC', 'Grade', 'Remarks'],
            ['HPC 2', 'Fundamentals in Food Service Operations', 2, 3, 3, 'THC2, 1st Year Completer, NCII', 'FBS NC II', '', ''],
            ['CC 102', 'Computer Productivity Applications', 2, 3, 3, 'CC 102', 'MR. NICASIO', '', ''],
            ['THC 2', 'Duplicate Risk Management Row', 3, 0, 3, 'None', '', '', ''],
            ['TOTAL UNITS', '', 7, 6, 9, '', '', '', ''],
            [],
            ['2nd Year - SUMMER TERM'],
            ['CODE', 'Subject Description', 'Lec Units/No. of Hours Per Week', 'Lab Units/No. of Hours Per Week', 'Total No. of Units', 'Pre-Requisite', 'Resultant NC', 'Grade', 'Remarks'],
            ['PRAC 1', 'Supervised Work Related Training 1', 0, '300 hours', 6, 'HPC 2', 'x', '', ''],
            ['', 'TOTAL UNITS', 0, 0, 6, '', '', '', ''],
            [],
            ['ASSESSMENT EVALUATION'],
            ['FBS NC II', 'Food and Beverage Services NC II', '', '', '', '', 'COMPETENT', '', ''],
            ['Prepared By: Registrar', '', '', '', 'Approved By: Academic Head'],
        ];

        foreach ($dhmRows as $rowValues) {
            $writer->addRow(Row::fromValues($rowValues));
        }

        // Sheet 2: DBM
        $dbmSheet = $writer->addNewSheetAndMakeItCurrent();
        $dbmSheet->setName('DBM');
        $writer->addRow(Row::fromValues(['First Year - First Semester']));
        $writer->addRow(Row::fromValues(['Subject Code', 'Subject Description', 'Lec', 'Lab', 'Total Units', 'Pre-Requisite']));
        $writer->addRow(Row::fromValues(['BM 111', 'Introduction to Business Management', 3, 0, 3, 'None']));

        // Sheet 3: DIT (uses "Subject Code" header)
        $ditSheet = $writer->addNewSheetAndMakeItCurrent();
        $ditSheet->setName('DIT');
        $writer->addRow(Row::fromValues(['First Year - First Semester']));
        $writer->addRow(Row::fromValues(['Subject Code', 'Subject Description', 'Lec', 'Lab', 'Total Units', 'Pre-Requisite', 'Resultant NC']));
        $writer->addRow(Row::fromValues(['CC 101', 'Introduction to Computing', 2, 3, 3, 'None', 'CSS NC II']));

        $writer->close();

        return $path;
    }

    /**
     * @param  list<list<float|int|string>>  $rows
     */
    private function createCsvFile(array $rows): string
    {
        $dir = storage_path('app/private/exports');
        File::ensureDirectoryExists($dir);
        $path = $dir.DIRECTORY_SEPARATOR.'test-import-'.uniqid('', true).'.csv';
        $this->tempPaths[] = $path;

        $writer = new CsvWriter;
        $writer->openToFile($path);

        foreach ($rows as $rowValues) {
            $writer->addRow(Row::fromValues($rowValues));
        }

        $writer->close();

        return $path;
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
