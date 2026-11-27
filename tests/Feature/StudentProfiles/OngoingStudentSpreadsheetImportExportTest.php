<?php

namespace Tests\Feature\StudentProfiles;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\StudentProfiles\CohortGradeMatrixService;
use App\Actions\StudentProfiles\OngoingStudentSpreadsheetExportService;
use App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService;
use App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser;
use App\Filament\Resources\StudentProfiles\Pages\ImportOngoingStudentsSpreadsheet;
use App\Models\AcademicDecision;
use App\Models\AcademicYear;
use App\Models\ApplicantIntake;
use App\Models\Course;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\CurriculumVersionBatch;
use App\Models\Hold;
use App\Models\PriorAcademicCredit;
use App\Models\Program;
use App\Models\StudentImportBatch;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Notifications\StudentAccountActivationNotification;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class OngoingStudentSpreadsheetImportExportTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
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

    public function test_lean_cohort_templates_retire_prior_subjects_sheet_and_include_reference_codes(): void
    {
        $this->staff(User::StaffRoleRegistrar);
        $fixtures = $this->createProgramCurriculumFixture();

        $exporter = app(OngoingStudentSpreadsheetExportService::class);
        $xlsxPath = $exporter->exportTemplate('xlsx');
        $csvPath = $exporter->exportTemplate('csv');
        $this->tempPaths[] = $xlsxPath;
        $this->tempPaths[] = $csvPath;

        $this->assertFileExists($xlsxPath);
        $this->assertFileExists($csvPath);

        $reader = new XlsxReader;
        $reader->open($xlsxPath);
        $sheetNames = [];
        $studentHeaders = [];
        $referenceContents = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheetNames[] = $sheet->getName();

                if ($sheet->getName() === 'Students') {
                    foreach ($sheet->getRowIterator() as $row) {
                        $studentHeaders = array_map('strval', $row->toArray());
                        break;
                    }
                }

                if ($sheet->getName() === 'Reference Codes') {
                    foreach ($sheet->getRowIterator() as $row) {
                        $referenceContents[] = implode('|', array_map('strval', $row->toArray()));
                    }
                }
            }
        } finally {
            $reader->close();
        }

        // Only 2 sheets: legacy Sheet 2 (`Prior Subjects`) is completely retired.
        $this->assertSame(['Students', 'Reference Codes'], $sheetNames);
        $this->assertNotContains('Prior Completed Subjects', $studentHeaders);
        $this->assertNotContains('Program Code*', $studentHeaders);
        $this->assertSame([
            'Student Number*',
            'First Name*',
            'Middle Name',
            'Last Name*',
            'Email*',
            'Academic Standing',
            'Lifecycle Status',
            'Financial Hold Note',
            'LRN',
            'Birth Date',
            'Phone',
        ], $studentHeaders);

        $joinedRef = implode("\n", $referenceContents);
        $this->assertStringContainsString($fixtures['program']->code, $joinedRef);
        $this->assertStringContainsString($fixtures['curriculum']->version_code, $joinedRef);

        $parser = app(OngoingStudentSpreadsheetParser::class);
        $inspected = $parser->inspectWorkbook($xlsxPath, 'xlsx');
        $this->assertSame(0, $inspected['students_sheet_index']);
        $this->assertCount(2, $inspected['sheets']);

        $extracted = $parser->extractSheet($xlsxPath, 0, 'xlsx');
        $this->assertTrue($extracted['all_required_mapped']);
    }

    public function test_cohort_scoped_import_auto_skips_mapping_credits_regular_students_defaults_activation_off_and_supports_undo_batch(): void
    {
        Notification::fake();

        $this->staff(User::StaffRoleRegistrar);
        $fixtures = $this->createProgramCurriculumFixture(isActiveProgram: false);

        // Lean 5-column core + optional Standing & Financial Hold Note (no Program/Curriculum/Prior Subjects columns).
        $workbookPath = $this->createSyntheticRosterWorkbook([
            ['Student Number', 'First Name', 'Middle Name', 'Last Name', 'Email', 'Academic Standing', 'Financial Hold Note'],
            ['SIA-2023-8001', 'Carlo', 'Santos', 'Dizon', 'carlo.dizon.c3@example.test', 'Regular', ''],
            ['SIA-2024-8002', 'Bianca', '', 'Soriano', 'bianca.soriano.c3@example.test', 'Irregular', 'Prior lab fee balance'],
            ['SIA-2024-8003', 'Broken', '', 'Row', 'not-an-email', 'Regular', ''],
        ]);

        $upload = UploadedFile::fake()->createWithContent(
            'cohort-roster.xlsx',
            (string) file_get_contents($workbookPath),
        );

        $component = Livewire::test(ImportOngoingStudentsSpreadsheet::class)
            // Teach-out inactive program with curriculum is selectable in Step 1 scope bar.
            ->set('programId', $fixtures['program']->id)
            ->set('curriculumVersionId', $fixtures['curriculum']->id)
            ->set('entryAcademicYearId', $fixtures['academicYear']->id)
            ->set('completedThroughKey', '1|'.Term::TypeSecondSemester)
            ->set('uploadedFile', $upload)
            // Auto-skipped Step 2 straight to Step 3 review!
            ->assertSet('activeStep', 3)
            ->assertSet('autoSkippedMapping', true)
            ->assertSet('reviewTab', 'fix_required')
            // Activation emails default OFF!
            ->assertSet('sendActivationEmails', false)
            // Power user can still click "Adjust column mapping" to inspect Step 2 and return to Step 3.
            ->call('goToStep', 2)
            ->assertSet('activeStep', 2)
            ->call('goToStep', 3)
            ->assertSet('activeStep', 3)
            ->call('commitImport');

        // Persistent completion summary stays rendered (no redirect) with StudentImportBatch ID.
        $summary = $component->get('lastImportSummary');
        $this->assertIsArray($summary);
        $this->assertNotNull($summary['import_batch_id']);
        $this->assertSame(2, $summary['created_students']);
        $this->assertSame(2, $summary['prior_credits_recorded']);
        $this->assertSame(0, $summary['activation_emails_sent']);
        $this->assertSame(1, $summary['skipped_rows_count']);

        // Zero activation emails sent because default is OFF.
        Notification::assertNothingSent();

        $carlo = StudentProfile::query()->where('student_number', 'SIA-2023-8001')->first();
        $bianca = StudentProfile::query()->where('student_number', 'SIA-2024-8002')->first();
        $broken = StudentProfile::query()->where('student_number', 'SIA-2024-8003')->first();

        $this->assertNotNull($carlo);
        $this->assertNotNull($bianca);
        $this->assertNull($broken);

        // Cohort metadata stored cleanly without fake historical Term.
        $this->assertSame($fixtures['program']->id, $carlo->program_id);
        $this->assertSame($fixtures['curriculum']->id, $carlo->curriculum_version_id);
        $this->assertSame($fixtures['academicYear']->id, $carlo->entry_academic_year_id);
        $this->assertSame($summary['import_batch_id'], $carlo->import_batch_id);

        // Regular student Carlo received both Year 1 Sem 1 & Sem 2 curriculum credits (`CREDIT`).
        $this->assertSame(2, PriorAcademicCredit::query()->active()->where('student_profile_id', $carlo->id)->count());
        // Irregular student Bianca did NOT get blanket cohort quick-credit, and received Advising Required + both financial holds.
        $this->assertSame(0, PriorAcademicCredit::query()->active()->where('student_profile_id', $bianca->id)->count());
        $this->assertDatabaseHas('academic_decisions', [
            'student_profile_id' => $bianca->id,
            'effect' => AcademicDecision::EffectAdvisingRequired,
            'state' => AcademicDecision::StateActive,
        ]);
        $this->assertDatabaseHas('holds', [
            'student_profile_id' => $bianca->id,
            'blocking_level' => Hold::BlockingEnrollment,
            'status' => Hold::StatusActive,
        ]);
        $this->assertDatabaseHas('holds', [
            'student_profile_id' => $bianca->id,
            'blocking_level' => Hold::BlockingRecordRelease,
            'status' => Hold::StatusActive,
        ]);

        // Skipped CSV contains broken row.
        $skippedCsvPath = $component->get('lastSkippedImportCsvPath');
        $this->assertIsString($skippedCsvPath);
        $this->assertFileExists($skippedCsvPath);
        $this->tempPaths[] = $skippedCsvPath;
        $this->assertStringContainsString('SIA-2024-8003', (string) file_get_contents($skippedCsvPath));

        // 1-Click Undo Import Batch cleanly removes created profiles & untouched users.
        $batchId = (int) $summary['import_batch_id'];
        $component->call('undoImportBatch', $batchId);

        $batch = StudentImportBatch::query()->findOrFail($batchId);
        $this->assertTrue($batch->isReverted());
        $this->assertNull(StudentProfile::query()->where('student_number', 'SIA-2023-8001')->first());
        $this->assertNull(StudentProfile::query()->where('student_number', 'SIA-2024-8002')->first());
    }

    public function test_activation_email_opt_in_sends_seven_day_student_activation_notification(): void
    {
        Notification::fake();

        $this->staff(User::StaffRoleRegistrar);
        $fixtures = $this->createProgramCurriculumFixture();

        $workbookPath = $this->createSyntheticRosterWorkbook([
            ['Student Number', 'First Name', 'Middle Name', 'Last Name', 'Email'],
            ['SIA-2023-8099', 'Elena', 'Cruz', 'Ramos', 'elena.ramos.act@example.test'],
        ]);

        $upload = UploadedFile::fake()->createWithContent(
            'activation-opt-in.xlsx',
            (string) file_get_contents($workbookPath),
        );

        Livewire::test(ImportOngoingStudentsSpreadsheet::class)
            ->set('programId', $fixtures['program']->id)
            ->set('curriculumVersionId', $fixtures['curriculum']->id)
            ->set('entryAcademicYearId', $fixtures['academicYear']->id)
            ->set('uploadedFile', $upload)
            ->assertSet('activeStep', 3)
            ->assertSet('sendActivationEmails', false)
            ->set('sendActivationEmails', true)
            ->call('commitImport');

        $elena = StudentProfile::query()->with('user')->where('student_number', 'SIA-2023-8099')->firstOrFail();
        Notification::assertSentTo($elena->user, StudentAccountActivationNotification::class);
    }

    public function test_strict_standing_lifecycle_validation_applicant_collision_opt_in_and_soft_duplicate_warnings(): void
    {
        Notification::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $fixtures = $this->createProgramCurriculumFixture();

        // Pre-existing student for soft Name+DOB & LRN duplicate warnings.
        $existingStudentUser = User::factory()->create(['email' => 'existing.dup@example.test', 'status' => User::StatusActive]);
        StudentProfile::factory()->create([
            'user_id' => $existingStudentUser->id,
            'student_number' => 'SIA-2022-0010',
            'first_name' => 'Miguel',
            'last_name' => 'Torres',
            'birth_date' => '2004-03-15',
            'prior_identifier' => 'LRN-777888999',
            'program_id' => $fixtures['program']->id,
            'curriculum_version_id' => $fixtures['curriculum']->id,
        ]);

        // Pre-existing applicant-only user with open draft intake.
        Role::findOrCreate('applicant', 'web');
        $applicantUser = User::factory()->create(['email' => 'applicant.collision@example.test', 'status' => User::StatusActive]);
        $applicantUser->assignRole('applicant');
        $intake = ApplicantIntake::factory()->create([
            'user_id' => $applicantUser->id,
            'program_id' => $fixtures['program']->id,
            'email' => 'applicant.collision@example.test',
            'status' => ApplicantIntake::StatusDraft,
            'handed_over_at' => null,
        ]);

        // Pre-existing staff user (must remain strictly blocked).
        $staffUser = User::factory()->create(['email' => 'staff.collision@example.test', 'status' => User::StatusActive]);
        $staffUser->assignRole(User::StaffRoleRegistrar);

        $importer = app(OngoingStudentSpreadsheetImportService::class);
        $scope = [
            'program_id' => $fixtures['program']->id,
            'curriculum_version_id' => $fixtures['curriculum']->id,
            'entry_academic_year_id' => $fixtures['academicYear']->id,
        ];

        $parsed = [
            [
                'row_key' => 'student_row_2',
                'source_row' => 2,
                'student_number' => 'SIA-2024-9001',
                'first_name' => 'BadStanding',
                'middle_name' => null,
                'last_name' => 'Test',
                'email' => 'bad.standing@example.test',
                'academic_standing' => 'DeanList',
                'lifecycle_status' => 'SUSPENDED',
            ],
            [
                'row_key' => 'student_row_3',
                'source_row' => 3,
                'student_number' => 'SIA-2024-9002',
                'first_name' => 'Applicant',
                'middle_name' => null,
                'last_name' => 'Convert',
                'email' => 'applicant.collision@example.test',
                'academic_standing' => 'Regular',
                'lifecycle_status' => 'ACTIVE',
            ],
            [
                'row_key' => 'student_row_4',
                'source_row' => 4,
                'student_number' => 'SIA-2024-9003',
                'first_name' => 'Staff',
                'middle_name' => null,
                'last_name' => 'Blocked',
                'email' => 'staff.collision@example.test',
                'academic_standing' => 'Regular',
                'lifecycle_status' => 'ACTIVE',
            ],
            [
                'row_key' => 'student_row_5',
                'source_row' => 5,
                'student_number' => 'SIA-2024-9004',
                'first_name' => 'Miguel',
                'middle_name' => null,
                'last_name' => 'Torres',
                'email' => 'miguel.new@example.test',
                'birth_date' => '2004-03-15',
                'prior_identifier' => 'LRN-777888999',
                'academic_standing' => 'Regular',
                'lifecycle_status' => 'ACTIVE',
            ],
        ];

        $preview = $importer->buildPreview($parsed, [], $scope);
        $rowsByKey = collect($preview['rows'])->keyBy('row_key');

        // Row 2: unknown standing & lifecycle are BOTH strictly blocked (no silent coercion).
        $this->assertSame(OngoingStudentSpreadsheetImportService::StatusBlocked, $rowsByKey['student_row_2']['status']);
        $this->assertArrayHasKey('academic_standing', $rowsByKey['student_row_2']['field_errors']);
        $this->assertArrayHasKey('lifecycle_status', $rowsByKey['student_row_2']['field_errors']);

        // Row 3: applicant collision blocked until explicit opt-in.
        $this->assertSame(OngoingStudentSpreadsheetImportService::StatusBlocked, $rowsByKey['student_row_3']['status']);
        $this->assertTrue($rowsByKey['student_row_3']['is_applicant_collision']);
        $this->assertStringContainsString('Existing applicant account', $rowsByKey['student_row_3']['status_label']);

        // Row 4: staff email strictly blocked without applicant opt-in flag.
        $this->assertSame(OngoingStudentSpreadsheetImportService::StatusBlocked, $rowsByKey['student_row_4']['status']);
        $this->assertFalse($rowsByKey['student_row_4']['is_applicant_collision']);

        // Row 5: soft duplicate warning (Name+DOB + LRN) is non-blocking!
        $this->assertSame(OngoingStudentSpreadsheetImportService::StatusReady, $rowsByKey['student_row_5']['status']);
        $this->assertTrue($rowsByKey['student_row_5']['has_duplicate_warning']);
        $this->assertCount(2, $rowsByKey['student_row_5']['warnings']);

        // Opt-in linking the existing applicant account resolves Row 3 to Ready and archives draft intake on commit!
        $previewWithOptIn = $importer->buildPreview($parsed, [
            'student_row_3' => ['row_key' => 'student_row_3', 'link_existing_applicant' => true],
        ], $scope);
        $optInRows = collect($previewWithOptIn['rows'])->keyBy('row_key');
        $this->assertSame(OngoingStudentSpreadsheetImportService::StatusReady, $optInRows['student_row_3']['status']);

        $commit = $importer->commitImport($registrar, $previewWithOptIn['rows'], sendActivationEmails: false, cohortScope: $scope);
        $this->assertSame(2, $commit['created_students']);
        $this->assertSame(1, $commit['linked_existing_applicants']);

        $linkedProfile = StudentProfile::query()->where('student_number', 'SIA-2024-9002')->firstOrFail();
        $this->assertSame($applicantUser->id, $linkedProfile->user_id);
        $this->assertSame(ApplicantIntake::StatusWithdrawn, $intake->fresh()->status);
    }

    public function test_safe_upsert_updates_unactivated_profile_while_leaving_activated_student_unchanged(): void
    {
        Notification::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $fixtures = $this->createProgramCurriculumFixture();

        // Unactivated student (never signed in, no official enrollment).
        $unactivatedUser = User::factory()->create([
            'email' => 'old.unactivated@example.test',
            'password' => null,
            'last_successful_sign_in_at' => null,
            'status' => User::StatusActive,
        ]);
        $unactivatedProfile = StudentProfile::factory()->create([
            'user_id' => $unactivatedUser->id,
            'student_number' => 'SIA-2023-8100',
            'first_name' => 'OldFirst',
            'last_name' => 'Student',
            'email' => 'old.unactivated@example.test',
            'program_id' => $fixtures['program']->id,
            'curriculum_version_id' => $fixtures['curriculum']->id,
            'academic_standing' => StudentProfile::StandingRegular,
        ]);

        // Activated student (already signed in).
        $activatedUser = User::factory()->create([
            'email' => 'signed.in@example.test',
            'last_successful_sign_in_at' => now()->subDay(),
            'status' => User::StatusActive,
        ]);
        $activatedProfile = StudentProfile::factory()->create([
            'user_id' => $activatedUser->id,
            'student_number' => 'SIA-2023-8101',
            'first_name' => 'OriginalActive',
            'last_name' => 'Student',
            'email' => 'signed.in@example.test',
            'program_id' => $fixtures['program']->id,
            'curriculum_version_id' => $fixtures['curriculum']->id,
            'academic_standing' => StudentProfile::StandingRegular,
        ]);

        $importer = app(OngoingStudentSpreadsheetImportService::class);
        $scope = [
            'program_id' => $fixtures['program']->id,
            'curriculum_version_id' => $fixtures['curriculum']->id,
            'entry_academic_year_id' => $fixtures['academicYear']->id,
            'completed_through_year_level' => 1,
            'completed_through_term_type' => Term::TypeFirstSemester,
        ];

        $preview = $importer->buildPreview([
            [
                'row_key' => 'student_row_2',
                'source_row' => 2,
                'student_number' => 'SIA-2023-8100',
                'first_name' => 'CorrectedFirst',
                'middle_name' => 'M',
                'last_name' => 'Student',
                'email' => 'corrected.unactivated@example.test',
                'academic_standing' => 'Regular',
                'lifecycle_status' => 'ACTIVE',
            ],
            [
                'row_key' => 'student_row_3',
                'source_row' => 3,
                'student_number' => 'SIA-2023-8101',
                'first_name' => 'OVERWRITTEN',
                'middle_name' => null,
                'last_name' => 'Student',
                'email' => 'signed.in@example.test',
                'academic_standing' => 'Irregular',
                'lifecycle_status' => 'ACTIVE',
            ],
        ], [], $scope);

        $this->assertSame(0, $preview['summary']['ready_new']);
        $this->assertSame(1, $preview['summary']['update_unactivated']);
        $this->assertSame(1, $preview['summary']['already_onboarded']);

        $commit = $importer->commitImport($registrar, $preview['rows'], sendActivationEmails: false, cohortScope: $scope);

        $this->assertSame(0, $commit['created_students']);
        $this->assertSame(1, $commit['updated_students']);
        $this->assertSame(1, $commit['skipped_existing_students']);
        $this->assertSame(1, $commit['prior_credits_recorded']);

        $unactivatedProfile->refresh();
        $unactivatedUser->refresh();
        $this->assertSame('CorrectedFirst', $unactivatedProfile->first_name);
        $this->assertSame('corrected.unactivated@example.test', $unactivatedProfile->email);
        $this->assertSame('corrected.unactivated@example.test', $unactivatedUser->email);
        $this->assertSame(1, PriorAcademicCredit::query()->active()->where('student_profile_id', $unactivatedProfile->id)->count());

        $activatedProfile->refresh();
        $this->assertSame('OriginalActive', $activatedProfile->first_name);
        $this->assertSame(StudentProfile::StandingRegular, $activatedProfile->academic_standing);
    }

    public function test_wide_cohort_grade_matrix_export_diff_preview_and_upsert_commit(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $fixtures = $this->createProgramCurriculumFixture();

        // Add a second subject in Year 1 First Semester so the matrix has 2 subject columns.
        $course3 = Course::factory()->create(['code' => 'IMPIT 102']);
        $spec3 = CourseSpecification::factory()->for($course3)->create([
            'title' => 'Discrete Structures',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);
        CurriculumEntry::factory()->for($fixtures['curriculum'])->for($spec3, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
            'term_label' => 'First Semester',
            'sequence' => 2,
        ]);

        $studentA = StudentProfile::factory()->create([
            'user_id' => User::factory()->create(['status' => User::StatusActive])->id,
            'student_number' => 'SIA-2023-9201',
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'program_id' => $fixtures['program']->id,
            'curriculum_version_id' => $fixtures['curriculum']->id,
            'entry_academic_year_id' => $fixtures['academicYear']->id,
        ]);

        $studentB = StudentProfile::factory()->create([
            'user_id' => User::factory()->create(['status' => User::StatusActive])->id,
            'student_number' => 'SIA-2023-9202',
            'first_name' => 'Ben',
            'last_name' => 'Cruz',
            'program_id' => $fixtures['program']->id,
            'curriculum_version_id' => $fixtures['curriculum']->id,
            'entry_academic_year_id' => $fixtures['academicYear']->id,
        ]);

        // Pre-populate Student A with a quick-credit `CREDIT` row on spec1.
        PriorAcademicCredit::query()->create([
            'student_profile_id' => $studentA->id,
            'course_specification_id' => $fixtures['spec1']->id,
            'academic_year_label' => 'AY 2023-2024',
            'term_label' => 'First Semester',
            'year_level' => 1,
            'units' => 3,
            'final_grade' => 'CREDIT',
            'result_status' => PriorAcademicCredit::ResultCredited,
            'is_external_transfer' => false,
            'state' => PriorAcademicCredit::StateActive,
            'recorded_by' => $registrar->id,
            'recorded_at' => now(),
        ]);

        $matrixService = app(CohortGradeMatrixService::class);
        $exportedPath = $matrixService->exportMatrix(
            program: $fixtures['program'],
            curriculum: $fixtures['curriculum'],
            yearLevel: 1,
            termType: Term::TypeFirstSemester,
            entryAcademicYearId: $fixtures['academicYear']->id,
        );
        $this->tempPaths[] = $exportedPath;
        $this->assertFileExists($exportedPath);

        // Inspect exported `.xlsx`: 2 sheets, pre-filled CREDIT cell.
        $reader = new XlsxReader;
        $reader->open($exportedPath);
        $sheetNames = [];
        $matrixRows = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheetNames[] = $sheet->getName();
                if ($sheet->getName() === CohortGradeMatrixService::SheetMatrix) {
                    foreach ($sheet->getRowIterator() as $row) {
                        $matrixRows[] = array_map('strval', $row->toArray());
                    }
                }
            }
        } finally {
            $reader->close();
        }

        $this->assertSame([CohortGradeMatrixService::SheetMatrix, CohortGradeMatrixService::SheetLegend], $sheetNames);
        $this->assertCount(3, $matrixRows); // Header + 2 students
        $this->assertStringStartsWith($fixtures['course1']->code.' — ', $matrixRows[0][3]);
        $this->assertStringStartsWith($course3->code.' — ', $matrixRows[0][4]);
        $this->assertSame('CREDIT', $matrixRows[1][3]); // Student A pre-filled CREDIT

        // Edit the matrix:
        // Student A: upgrade CREDIT -> 1.75 on course1, record new INC on course3
        // Student B: record new 5.00 on course1, record external transfer TC on course3
        $editedMatrixPath = $this->createSyntheticMatrixWorkbook([
            $matrixRows[0],
            ['SIA-2023-9201', 'Reyes, Ana', 'Regular', '1.75', 'INC'],
            ['SIA-2023-9202', 'Cruz, Ben', 'Regular', '5.00', 'TC'],
            ['SIA-2023-9202', 'Cruz, Ben', 'Regular', 'BADGRADE', ''],
        ]);

        $preview = $matrixService->previewMatrix(
            path: $editedMatrixPath,
            program: $fixtures['program'],
            curriculum: $fixtures['curriculum'],
            yearLevel: 1,
            termType: Term::TypeFirstSemester,
            extension: 'xlsx',
            academicYearLabel: 'AY 2023-2024',
            sourceSchool: 'External Polytechnic',
        );

        $this->assertSame(1, $preview['summary']['upgrades_count']);
        $this->assertSame(3, $preview['summary']['new_credits_count']);
        $this->assertSame(4, $preview['summary']['changes_total']);
        $this->assertSame(1, $preview['summary']['error_count']);

        // Commit through the Livewire page in `matrix` mode!
        $upload = UploadedFile::fake()->createWithContent(
            'edited-grade-matrix.xlsx',
            (string) file_get_contents($editedMatrixPath),
        );

        Livewire::test(ImportOngoingStudentsSpreadsheet::class)
            ->call('setWorkspaceMode', 'matrix')
            ->assertSet('workspaceMode', 'matrix')
            ->set('matrixProgramId', $fixtures['program']->id)
            ->set('matrixCurriculumVersionId', $fixtures['curriculum']->id)
            ->set('matrixTermKey', '1|'.Term::TypeFirstSemester)
            ->set('matrixAcademicYearLabel', 'AY 2023-2024')
            ->set('matrixSourceSchool', 'External Polytechnic')
            ->set('matrixUploadedFile', $upload)
            ->call('commitGradeMatrix')
            ->assertSet('lastMatrixSummary.total_applied', 4)
            ->assertSet('lastMatrixSummary.updated_cells', 1)
            ->assertSet('lastMatrixSummary.created_cells', 3)
            ->assertSet('lastMatrixSummary.students_affected', 2);

        // Verify database state: CREDIT upgraded -> 1.75, INC, 5.00, and TC with is_external_transfer = true!
        $this->assertDatabaseHas('prior_academic_credits', [
            'student_profile_id' => $studentA->id,
            'course_specification_id' => $fixtures['spec1']->id,
            'final_grade' => '1.75',
            'result_status' => PriorAcademicCredit::ResultPassed,
            'is_external_transfer' => false,
            'state' => PriorAcademicCredit::StateActive,
        ]);
        $this->assertDatabaseHas('prior_academic_credits', [
            'student_profile_id' => $studentA->id,
            'course_specification_id' => $spec3->id,
            'final_grade' => 'INC',
            'result_status' => PriorAcademicCredit::ResultInc,
            'is_external_transfer' => false,
            'state' => PriorAcademicCredit::StateActive,
        ]);
        $this->assertDatabaseHas('prior_academic_credits', [
            'student_profile_id' => $studentB->id,
            'course_specification_id' => $fixtures['spec1']->id,
            'final_grade' => '5.00',
            'result_status' => PriorAcademicCredit::ResultFailed,
            'is_external_transfer' => false,
            'state' => PriorAcademicCredit::StateActive,
        ]);
        $this->assertDatabaseHas('prior_academic_credits', [
            'student_profile_id' => $studentB->id,
            'course_specification_id' => $spec3->id,
            'final_grade' => 'TC',
            'result_status' => PriorAcademicCredit::ResultCredited,
            'is_external_transfer' => true,
            'source_school' => 'External Polytechnic',
            'state' => PriorAcademicCredit::StateActive,
        ]);
    }

    /**
     * @param  list<list<float|int|string>>  $studentRows
     */
    private function createSyntheticRosterWorkbook(array $studentRows): string
    {
        $dir = storage_path('app/private/exports');
        File::ensureDirectoryExists($dir);
        $path = $dir.DIRECTORY_SEPARATOR.'test-cohort-roster-'.uniqid('', true).'.xlsx';
        $this->tempPaths[] = $path;

        $writer = new XlsxWriter;
        $writer->openToFile($path);

        try {
            $writer->getCurrentSheet()->setName('Students');
            foreach ($studentRows as $values) {
                $writer->addRow(Row::fromValues($values));
            }
        } finally {
            $writer->close();
        }

        return $path;
    }

    /**
     * @param  list<list<float|int|string>>  $matrixRows
     */
    private function createSyntheticMatrixWorkbook(array $matrixRows): string
    {
        $dir = storage_path('app/private/exports');
        File::ensureDirectoryExists($dir);
        $path = $dir.DIRECTORY_SEPARATOR.'test-grade-matrix-'.uniqid('', true).'.xlsx';
        $this->tempPaths[] = $path;

        $writer = new XlsxWriter;
        $writer->openToFile($path);

        try {
            $writer->getCurrentSheet()->setName(CohortGradeMatrixService::SheetMatrix);
            foreach ($matrixRows as $values) {
                $writer->addRow(Row::fromValues($values));
            }
        } finally {
            $writer->close();
        }

        return $path;
    }

    /**
     * @return array{
     *     program: Program,
     *     curriculum: CurriculumVersion,
     *     academicYear: AcademicYear,
     *     course1: Course,
     *     course2: Course,
     *     spec1: CourseSpecification,
     *     spec2: CourseSpecification
     * }
     */
    private function createProgramCurriculumFixture(bool $isActiveProgram = true): array
    {
        $academicYear = AcademicYear::factory()->create([
            'label' => 'AY 2023-2024',
            'starts_on' => '2023-08-01',
            'ends_on' => '2024-06-30',
            'state' => AcademicYear::StateClosed,
        ]);

        $program = Program::factory()->create([
            'code' => 'DIT-IMP',
            'name' => 'Diploma in Information Technology (Import)',
            'duration_years' => 3,
            'is_active' => $isActiveProgram,
        ]);

        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'DIT-IMP-2023',
            'state' => CurriculumVersion::StateActive,
        ]);

        CurriculumVersionBatch::query()->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $curriculum->id,
            'academic_year_id' => $academicYear->id,
        ]);

        $course1 = Course::factory()->create(['code' => 'IMPGE 1']);
        $spec1 = CourseSpecification::factory()->for($course1)->create([
            'title' => 'Purposive Communication',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);
        CurriculumEntry::factory()->for($curriculum)->for($spec1, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
            'term_label' => 'First Semester',
            'sequence' => 1,
        ]);

        $course2 = Course::factory()->create(['code' => 'IMPIT 101']);
        $spec2 = CourseSpecification::factory()->for($course2)->create([
            'title' => 'Computer Programming 1',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);
        CurriculumEntry::factory()->for($curriculum)->for($spec2, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeSecondSemester,
            'term_label' => 'Second Semester',
            'sequence' => 1,
        ]);

        return [
            'program' => $program,
            'curriculum' => $curriculum,
            'academicYear' => $academicYear,
            'course1' => $course1,
            'course2' => $course2,
            'spec1' => $spec1,
            'spec2' => $spec2,
        ];
    }

    private function staff(string $roleName): User
    {
        Role::findOrCreate('student', 'web');
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
