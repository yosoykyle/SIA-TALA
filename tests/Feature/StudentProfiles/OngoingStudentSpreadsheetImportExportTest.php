<?php

namespace Tests\Feature\StudentProfiles;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\StudentProfiles\OngoingStudentSpreadsheetExportService;
use App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService;
use App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser;
use App\Filament\Resources\StudentProfiles\Pages\ImportOngoingStudentsSpreadsheet;
use App\Models\Course;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\PriorAcademicCredit;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Filament\Auth\Notifications\ResetPassword;
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

    public function test_live_xlsx_and_csv_templates_contain_expected_sheets_headers_and_live_reference_codes(): void
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
        $referenceContents = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheetNames[] = $sheet->getName();

                if ($sheet->getName() === 'Reference Codes') {
                    foreach ($sheet->getRowIterator() as $row) {
                        $referenceContents[] = implode('|', array_map('strval', $row->toArray()));
                    }
                }
            }
        } finally {
            $reader->close();
        }

        $this->assertSame(['Students', 'Prior Subjects', 'Reference Codes'], $sheetNames);
        $joinedRef = implode("\n", $referenceContents);
        $this->assertStringContainsString($fixtures['program']->code, $joinedRef);
        $this->assertStringContainsString($fixtures['curriculum']->version_code, $joinedRef);
        $this->assertStringContainsString($fixtures['course1']->code, $joinedRef);

        // Parser lists workbook sheets and auto-detects Students (0) + Prior Subjects (1) while skipping Reference Codes.
        $inspected = app(OngoingStudentSpreadsheetParser::class)->inspectWorkbook($xlsxPath, 'xlsx');
        $this->assertSame(0, $inspected['students_sheet_index']);
        $this->assertSame(1, $inspected['prior_subjects_sheet_index']);
        $this->assertCount(3, $inspected['sheets']);
    }

    public function test_three_step_wizard_imports_students_and_prior_subjects_skips_blocked_and_exports_skipped_csv(): void
    {
        Notification::fake();

        $this->staff(User::StaffRoleRegistrar);
        $fixtures = $this->createProgramCurriculumFixture();

        $workbookPath = $this->createSyntheticOnboardingWorkbook(
            studentRows: [
                OngoingStudentSpreadsheetExportService::studentsHeaders(),
                [
                    'SIA-2023-8001', 'Carlo', 'Santos', 'Dizon', 'carlo.dizon.c3@example.test',
                    $fixtures['program']->code, '', '', 'Regular', 'ACTIVE',
                    '2004-05-10', '09170001111', 'Marikina', 'LRN-8001', '', '',
                ],
                [
                    'SIA-2024-8002', 'Bianca', '', 'Soriano', 'bianca.soriano.c3@example.test',
                    $fixtures['program']->code, $fixtures['curriculum']->version_code, '', 'Irregular', 'ACTIVE',
                    '2005-07-18', '09170002222', 'Rizal', 'LRN-8002', 'Prior lab fee balance',
                    $fixtures['course1']->code.':1.75',
                ],
                [
                    'SIA-2024-8003', 'Broken', '', 'Row', 'not-an-email',
                    $fixtures['program']->code, '', '', 'Regular', 'ACTIVE',
                    '', '', '', '', '', '',
                ],
            ],
            priorSubjectRows: [
                OngoingStudentSpreadsheetExportService::priorSubjectsHeaders(),
                ['SIA-2023-8001', $fixtures['course1']->code, 1, 'AY 2023-2024', 'First Semester', '1.50', 'passed', 'Servitech Institute'],
                ['SIA-2023-8001', $fixtures['course2']->code, 1, 'AY 2023-2024', 'First Semester', '1.75', 'passed', 'Servitech Institute'],
            ],
        );

        $upload = UploadedFile::fake()->createWithContent(
            'ongoing-students-batch.xlsx',
            (string) file_get_contents($workbookPath),
        );

        $component = Livewire::test(ImportOngoingStudentsSpreadsheet::class)
            ->set('uploadedFile', $upload)
            ->assertSet('activeStep', 1)
            ->call('goToStep', 2)
            ->assertSet('activeStep', 2)
            ->call('goToStep', 3)
            ->assertSet('activeStep', 3)
            ->assertSet('reviewTab', 'fix_required')
            ->assertSet('sendActivationEmails', true)
            ->set('sendActivationEmails', true)
            ->call('commitImport');

        // 2 ready students created, 1 invalid email row skipped into downloadable CSV.
        $carlo = StudentProfile::query()->where('student_number', 'SIA-2023-8001')->first();
        $bianca = StudentProfile::query()->where('student_number', 'SIA-2024-8002')->first();
        $broken = StudentProfile::query()->where('student_number', 'SIA-2024-8003')->first();

        $this->assertNotNull($carlo);
        $this->assertNotNull($bianca);
        $this->assertNull($broken);

        // Single active curriculum auto-resolved when blank on Sheet 1.
        $this->assertSame($fixtures['curriculum']->id, $carlo->curriculum_version_id);
        $this->assertSame(StudentProfile::StandingRegular, $carlo->academic_standing);
        $this->assertSame(StudentProfile::StandingIrregular, $bianca->academic_standing);

        // Sheet 2 pre-TALA grades recorded for Carlo; inline prior credit recorded for Bianca.
        $this->assertSame(2, PriorAcademicCredit::query()->active()->where('student_profile_id', $carlo->id)->count());
        $this->assertDatabaseHas('prior_academic_credits', [
            'student_profile_id' => $carlo->id,
            'course_specification_id' => $fixtures['spec1']->id,
            'academic_year_label' => 'AY 2023-2024',
            'term_label' => 'First Semester',
            'year_level' => '1',
            'final_grade' => '1.50',
            'result_status' => PriorAcademicCredit::ResultPassed,
        ]);
        $this->assertSame(1, PriorAcademicCredit::query()->active()->where('student_profile_id', $bianca->id)->count());

        Notification::assertSentTo($carlo->user, ResetPassword::class);
        Notification::assertSentTo($bianca->user, ResetPassword::class);

        $summary = $component->get('lastImportSummary');
        $this->assertSame(2, $summary['created_students']);
        $this->assertSame(3, $summary['prior_credits_recorded']);
        $this->assertSame(2, $summary['activation_emails_sent']);
        $this->assertSame(1, $summary['skipped_rows_count']);

        $skippedCsvPath = $component->get('lastSkippedImportCsvPath');
        $this->assertIsString($skippedCsvPath);
        $this->assertFileExists($skippedCsvPath);
        $this->tempPaths[] = $skippedCsvPath;
        $this->assertStringContainsString('SIA-2024-8003', (string) file_get_contents($skippedCsvPath));
    }

    public function test_idempotent_reupload_skips_existing_students_while_appending_missing_sheet_two_prior_subjects(): void
    {
        Notification::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $fixtures = $this->createProgramCurriculumFixture();

        $parser = app(OngoingStudentSpreadsheetParser::class);
        $importer = app(OngoingStudentSpreadsheetImportService::class);

        // First upload creates SIA-2023-8100 with 1 prior subject.
        $firstWorkbook = $this->createSyntheticOnboardingWorkbook(
            studentRows: [
                OngoingStudentSpreadsheetExportService::studentsHeaders(),
                [
                    'SIA-2023-8100', 'Original', '', 'Student', 'original.8100@example.test',
                    $fixtures['program']->code, $fixtures['curriculum']->version_code, '', 'Regular', 'ACTIVE',
                    '', '', '', '', '', '',
                ],
            ],
            priorSubjectRows: [
                OngoingStudentSpreadsheetExportService::priorSubjectsHeaders(),
                ['SIA-2023-8100', $fixtures['course1']->code, 1, 'AY 2023-2024', 'First Semester', '1.25', 'passed', 'Servitech Institute'],
            ],
        );

        $extracted1 = $parser->extractSheet($firstWorkbook, 0, 'xlsx');
        $preview1 = $importer->buildPreview(
            $parser->parseStudentRows($firstWorkbook, 0, $extracted1['suggested_mapping'], 'xlsx'),
            $parser->parsePriorSubjectsSheet($firstWorkbook, 1, 'xlsx'),
        );
        $commit1 = $importer->commitImport($registrar, $preview1['rows'], $preview1['prior_subjects'], sendActivationEmails: false);

        $this->assertSame(1, $commit1['created_students']);
        $this->assertSame(1, $commit1['prior_credits_recorded']);

        // Second upload tries to change First Name on Sheet 1 AND includes both existing course1 + new course2 on Sheet 2.
        $secondWorkbook = $this->createSyntheticOnboardingWorkbook(
            studentRows: [
                OngoingStudentSpreadsheetExportService::studentsHeaders(),
                [
                    'SIA-2023-8100', 'OVERWRITTEN', '', 'Student', 'original.8100@example.test',
                    $fixtures['program']->code, $fixtures['curriculum']->version_code, '', 'Irregular', 'ACTIVE',
                    '', '', '', '', '', '',
                ],
            ],
            priorSubjectRows: [
                OngoingStudentSpreadsheetExportService::priorSubjectsHeaders(),
                ['SIA-2023-8100', $fixtures['course1']->code, 1, 'AY 2023-2024', 'First Semester', '1.25', 'passed', 'Servitech Institute'],
                ['SIA-2023-8100', $fixtures['course2']->code, 1, 'AY 2023-2024', 'Second Semester', '2.00', 'passed', 'Servitech Institute'],
            ],
        );

        $extracted2 = $parser->extractSheet($secondWorkbook, 0, 'xlsx');
        $preview2 = $importer->buildPreview(
            $parser->parseStudentRows($secondWorkbook, 0, $extracted2['suggested_mapping'], 'xlsx'),
            $parser->parsePriorSubjectsSheet($secondWorkbook, 1, 'xlsx'),
        );

        $this->assertSame(0, $preview2['summary']['ready_new']);
        $this->assertSame(1, $preview2['summary']['already_onboarded']);
        $this->assertSame(2, $preview2['summary']['prior_subjects_ready']);

        $commit2 = $importer->commitImport($registrar, $preview2['rows'], $preview2['prior_subjects'], sendActivationEmails: false);

        $this->assertSame(0, $commit2['created_students']);
        $this->assertSame(1, $commit2['skipped_existing_students']);
        // Only the missing course2 is newly recorded; existing course1 is deduplicated cleanly.
        $this->assertSame(1, $commit2['prior_credits_recorded']);

        $profile = StudentProfile::query()->where('student_number', 'SIA-2023-8100')->firstOrFail();
        $this->assertSame('Original', $profile->first_name);
        $this->assertSame(StudentProfile::StandingRegular, $profile->academic_standing);
        $this->assertSame(2, PriorAcademicCredit::query()->active()->where('student_profile_id', $profile->id)->count());
    }

    /**
     * @param  list<list<float|int|string>>  $studentRows
     * @param  list<list<float|int|string>>  $priorSubjectRows
     */
    private function createSyntheticOnboardingWorkbook(array $studentRows, array $priorSubjectRows = []): string
    {
        $dir = storage_path('app/private/exports');
        File::ensureDirectoryExists($dir);
        $path = $dir.DIRECTORY_SEPARATOR.'test-ongoing-students-'.uniqid('', true).'.xlsx';
        $this->tempPaths[] = $path;

        $writer = new XlsxWriter;
        $writer->openToFile($path);

        try {
            $writer->getCurrentSheet()->setName('Students');
            foreach ($studentRows as $values) {
                $writer->addRow(Row::fromValues($values));
            }

            if ($priorSubjectRows !== []) {
                $sheet2 = $writer->addNewSheetAndMakeItCurrent();
                $sheet2->setName('Prior Subjects');
                foreach ($priorSubjectRows as $values) {
                    $writer->addRow(Row::fromValues($values));
                }
            }
        } finally {
            $writer->close();
        }

        return $path;
    }

    /**
     * @return array{program: Program, curriculum: CurriculumVersion, course1: Course, course2: Course, spec1: CourseSpecification, spec2: CourseSpecification}
     */
    private function createProgramCurriculumFixture(): array
    {
        $program = Program::factory()->create([
            'code' => 'DIT-IMP',
            'name' => 'Diploma in Information Technology (Import)',
            'duration_years' => 3,
            'is_active' => true,
        ]);

        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'DIT-IMP-2023',
            'state' => CurriculumVersion::StateActive,
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
            'sequence' => 2,
        ]);

        return [
            'program' => $program,
            'curriculum' => $curriculum,
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
