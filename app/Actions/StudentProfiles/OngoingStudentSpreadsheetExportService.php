<?php

namespace App\Actions\StudentProfiles;

use App\Actions\AcademicSetup\CurriculumSpreadsheetExportService;
use App\Models\Course;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OngoingStudentSpreadsheetExportService
{
    /**
     * Canonical column headers for Sheet 1 (`Students`) and CSV templates.
     *
     * @return list<string>
     */
    public static function studentsHeaders(): array
    {
        return [
            'Student Number*',
            'First Name*',
            'Middle Name',
            'Last Name*',
            'Email*',
            'Program Code*',
            'Curriculum Version Code',
            'Entry Term Code',
            'Academic Standing',
            'Lifecycle Status',
            'Birth Date',
            'Phone',
            'Address',
            'LRN / Prior ID',
            'Financial Hold Note',
            'Prior Completed Subjects',
        ];
    }

    /**
     * Canonical column headers for Sheet 2 (`Prior Subjects`).
     *
     * @return list<string>
     */
    public static function priorSubjectsHeaders(): array
    {
        return [
            'Student Number*',
            'Subject Code*',
            'Year Level',
            'Academic Year',
            'Term',
            'Final Grade',
            'Result Status',
            'Source School',
        ];
    }

    /**
     * Generate a 3-sheet `.xlsx` (Students, Prior Subjects, Reference Codes) or 1-sheet `.csv` onboarding template.
     */
    public function exportTemplate(string $format = 'xlsx'): string
    {
        $ext = $this->normalizeFormat($format);
        $path = $this->prepareExportPath('ongoing-students-import-template.'.$ext);

        $sampleProgram = Program::query()
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->first();
        $programCode = $sampleProgram instanceof Program && filled($sampleProgram->code)
            ? (string) $sampleProgram->code
            : 'DIT';

        $sampleCurriculum = $sampleProgram instanceof Program
            ? CurriculumVersion::query()
                ->where('program_id', $sampleProgram->id)
                ->whereIn('state', [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded])
                ->orderByRaw("CASE WHEN state = 'ACTIVE' THEN 0 ELSE 1 END")
                ->orderByDesc('id')
                ->first()
            : null;
        $curriculumCode = $sampleCurriculum instanceof CurriculumVersion && filled($sampleCurriculum->version_code)
            ? (string) $sampleCurriculum->version_code
            : 'DIT-2023';

        $sampleTerm = Term::query()
            ->with('academicYear')
            ->orderByRaw("CASE WHEN state = 'ACTIVE' THEN 0 ELSE 1 END")
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first();
        $entryTermCode = $sampleTerm instanceof Term
            ? collect([$sampleTerm->academicYear?->label, $sampleTerm->label])->filter()->implode(' - ')
            : '';

        $studentRows = [
            self::studentsHeaders(),
            [
                self::neutralize('SIA-2023-0045'),
                self::neutralize('Juan'),
                self::neutralize('Santos'),
                self::neutralize('Dela Cruz'),
                self::neutralize('juan.delacruz@servitech.edu.ph'),
                self::neutralize($programCode),
                self::neutralize($curriculumCode),
                self::neutralize($entryTermCode),
                self::neutralize('Regular'),
                self::neutralize('ACTIVE'),
                self::neutralize('2005-04-12'),
                self::neutralize('09171234567'),
                self::neutralize('San Mateo, Rizal'),
                self::neutralize('LRN-109283746501'),
                self::neutralize(''),
                self::neutralize(''),
            ],
            [
                self::neutralize('SIA-2024-0102'),
                self::neutralize('Maria'),
                self::neutralize('Reyes'),
                self::neutralize('Bautista'),
                self::neutralize('maria.bautista@servitech.edu.ph'),
                self::neutralize($programCode),
                self::neutralize($curriculumCode),
                self::neutralize($entryTermCode),
                self::neutralize('Irregular'),
                self::neutralize('ACTIVE'),
                self::neutralize('2006-08-21'),
                self::neutralize('09189876543'),
                self::neutralize('Marikina City'),
                self::neutralize('LRN-109283746502'),
                self::neutralize('Prior balance from AY 2024-2025'),
                self::neutralize('GE 1:1.75; IT 101:1.50'),
            ],
        ];

        if ($ext === 'csv') {
            $writer = new CsvWriter;
            $writer->openToFile($path);

            try {
                foreach ($studentRows as $rowValues) {
                    $writer->addRow(Row::fromValues($rowValues));
                }
            } finally {
                $writer->close();
            }

            return $path;
        }

        $priorSubjectRows = [
            self::priorSubjectsHeaders(),
            [
                self::neutralize('SIA-2023-0045'),
                self::neutralize('GE 1'),
                1,
                self::neutralize('AY 2023-2024'),
                self::neutralize('First Semester'),
                self::neutralize('1.50'),
                self::neutralize('passed'),
                self::neutralize('Servitech Institute'),
            ],
            [
                self::neutralize('SIA-2023-0045'),
                self::neutralize('IT 101'),
                1,
                self::neutralize('AY 2023-2024'),
                self::neutralize('First Semester'),
                self::neutralize('1.75'),
                self::neutralize('passed'),
                self::neutralize('Servitech Institute'),
            ],
            [
                self::neutralize('SIA-2024-0102'),
                self::neutralize('GE 2'),
                1,
                self::neutralize('AY 2023-2024'),
                self::neutralize('Second Semester'),
                self::neutralize('1.50'),
                self::neutralize('passed'),
                self::neutralize('Servitech Institute'),
            ],
        ];

        $referenceRows = $this->buildReferenceCodesRows();

        $writer = new XlsxWriter;
        $writer->openToFile($path);

        try {
            $writer->getCurrentSheet()->setName('Students');
            foreach ($studentRows as $rowValues) {
                $writer->addRow(Row::fromValues($rowValues));
            }

            $priorSheet = $writer->addNewSheetAndMakeItCurrent();
            $priorSheet->setName('Prior Subjects');
            foreach ($priorSubjectRows as $rowValues) {
                $writer->addRow(Row::fromValues($rowValues));
            }

            $refSheet = $writer->addNewSheetAndMakeItCurrent();
            $refSheet->setName('Reference Codes');
            foreach ($referenceRows as $rowValues) {
                $writer->addRow(Row::fromValues($rowValues));
            }
        } finally {
            $writer->close();
        }

        return $path;
    }

    /**
     * Stream a formula-safe CSV summarizing student onboarding & account activation status.
     *
     * @param  Collection<int, StudentProfile>  $profiles
     */
    public function exportActivationStatus(Collection $profiles): string
    {
        $path = $this->prepareExportPath('student-activation-status-'.now()->format('Y-m-d').'.csv');

        if ($profiles instanceof EloquentCollection) {
            $profiles->loadMissing(['user', 'program', 'curriculumVersion']);
            $profiles->loadCount([
                'priorAcademicCredits as active_prior_credits_count' => fn ($query) => $query->active(),
            ]);
        }

        $writer = new CsvWriter;
        $writer->openToFile($path);

        try {
            $writer->addRow(Row::fromValues([
                'Student Number',
                'Student Name',
                'Email',
                'Program',
                'Curriculum',
                'Standing',
                'Prior Subjects Count',
                'Activation Status',
                'Last Sign-In',
            ]));

            foreach ($profiles as $profile) {
                if (! $profile instanceof StudentProfile) {
                    continue;
                }

                $fullName = collect([
                    $profile->first_name,
                    $profile->middle_name,
                    $profile->last_name,
                ])->filter()->implode(' ');

                if ($fullName === '' && $profile->user !== null) {
                    $fullName = (string) $profile->user->name;
                }

                $email = (string) ($profile->user?->email ?? $profile->email ?? '');
                $curriculumLabel = (string) ($profile->curriculumVersion?->version_code ?: ($profile->curriculumVersion?->name ?? ''));
                $priorCount = $profile->hasAttribute('active_prior_credits_count')
                    ? (int) $profile->getAttribute('active_prior_credits_count')
                    : $profile->priorAcademicCredits()->active()->count();
                $lastSignIn = $profile->user?->last_sign_in_at?->format('Y-m-d H:i') ?? 'Never';

                $writer->addRow(Row::fromValues([
                    self::neutralize((string) $profile->student_number),
                    self::neutralize($fullName),
                    self::neutralize($email),
                    self::neutralize((string) ($profile->program?->code ?? '')),
                    self::neutralize($curriculumLabel),
                    self::neutralize((string) ($profile->academic_standing ?? '')),
                    $priorCount,
                    self::neutralize(StudentAccountActivation::statusLabel($profile)),
                    self::neutralize($lastSignIn),
                ]));
            }
        } finally {
            $writer->close();
        }

        return $path;
    }

    /**
     * Stream a formula-safe CSV of skipped or blocked onboarding rows.
     *
     * @param  array<int, array<string, mixed>>  $skippedRows
     */
    public function exportSkippedRowsCsv(array $skippedRows): string
    {
        $path = $this->prepareExportPath('skipped-student-import-rows-'.now()->format('Y-m-d').'.csv');

        $writer = new CsvWriter;
        $writer->openToFile($path);

        try {
            $writer->addRow(Row::fromValues([
                'Sheet',
                'Row',
                'Student Number',
                'Student Name',
                'Email',
                'Reason',
            ]));

            foreach ($skippedRows as $row) {
                $sheetName = (string) ($row['sheet'] ?? (isset($row['subject_code']) ? 'Prior Subjects' : 'Students'));
                $studentName = trim((string) ($row['student_name'] ?? collect([
                    $row['first_name'] ?? null,
                    $row['middle_name'] ?? null,
                    $row['last_name'] ?? null,
                ])->filter()->implode(' ')));

                if ($studentName === '' && filled($row['subject_code'] ?? null)) {
                    $studentName = 'Subject: '.(string) $row['subject_code'];
                }

                $reason = trim((string) ($row['skip_reason'] ?? $row['already_onboarded_reason'] ?? implode(' ', (array) ($row['errors'] ?? []))));
                if ($reason === '') {
                    $reason = 'Skipped';
                }

                $writer->addRow(Row::fromValues([
                    self::neutralize($sheetName),
                    (int) ($row['source_row'] ?? 0),
                    self::neutralize((string) ($row['student_number'] ?? '')),
                    self::neutralize($studentName),
                    self::neutralize((string) ($row['email'] ?? '')),
                    self::neutralize($reason),
                ]));
            }
        } finally {
            $writer->close();
        }

        return $path;
    }

    /**
     * Return a BinaryFileResponse configured to delete the temporary file after download.
     */
    public function downloadAndDelete(string $path, ?string $downloadName = null, ?string $format = null): BinaryFileResponse
    {
        return app(CurriculumSpreadsheetExportService::class)->downloadAndDelete($path, $downloadName, $format);
    }

    /**
     * Build Sheet 3 (`Reference Codes`) rows live from the database.
     *
     * @return list<list<float|int|string>>
     */
    private function buildReferenceCodesRows(): array
    {
        $rows = [[
            'Category',
            'Code / Identifier',
            'Name / Title',
            'Program / Academic Year',
            'Status',
        ]];

        $programs = Program::query()
            ->orderBy('code')
            ->get();

        foreach ($programs as $program) {
            $rows[] = [
                'Program',
                self::neutralize((string) $program->code),
                self::neutralize((string) $program->name),
                '',
                $program->is_active ? 'Active' : 'Inactive',
            ];
        }

        $curricula = CurriculumVersion::query()
            ->with('program')
            ->whereIn('state', [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded])
            ->orderBy('program_id')
            ->orderBy('version_code')
            ->get();

        foreach ($curricula as $curriculum) {
            $rows[] = [
                'Curriculum Version',
                self::neutralize((string) $curriculum->version_code),
                self::neutralize((string) $curriculum->name),
                self::neutralize((string) ($curriculum->program?->code ?? '')),
                self::neutralize(CurriculumVersion::stateOptions()[$curriculum->state] ?? (string) $curriculum->state),
            ];
        }

        $terms = Term::query()
            ->with('academicYear')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get();

        foreach ($terms as $term) {
            $ayLabel = (string) ($term->academicYear?->label ?? '');
            $termIdentifier = collect([$ayLabel !== '' ? $ayLabel : null, $term->label])->filter()->implode(' - ');

            $rows[] = [
                'Term',
                self::neutralize($termIdentifier),
                self::neutralize((string) $term->label),
                self::neutralize($ayLabel),
                self::neutralize(Term::stateOptions()[$term->state] ?? (string) $term->state),
            ];
        }

        $courses = Course::query()
            ->with(['specifications' => fn ($query) => $query
                ->where('state', 'ACTIVE')
                ->orderByDesc('id')])
            ->orderBy('code')
            ->get();

        foreach ($courses as $course) {
            $activeSpec = $course->specifications->first();
            $rows[] = [
                'Course',
                self::neutralize((string) $course->code),
                self::neutralize((string) ($activeSpec?->title ?? '')),
                $activeSpec !== null ? (string) round((float) $activeSpec->credit_units, 2).' units' : '',
                self::neutralize(Course::stateOptions()[$course->state] ?? (string) $course->state),
            ];
        }

        return $rows;
    }

    private static function neutralize(mixed $value): float|int|string
    {
        return CurriculumSpreadsheetExportService::neutralizeFormulaInjection($value);
    }

    private function normalizeFormat(string $format): string
    {
        $clean = strtolower(ltrim(trim($format), '.'));

        return $clean === 'csv' ? 'csv' : 'xlsx';
    }

    private function prepareExportPath(string $filename): string
    {
        $exportDir = storage_path('app/private/exports/'.Str::ulid());
        File::ensureDirectoryExists($exportDir);

        return $exportDir.DIRECTORY_SEPARATOR.$filename;
    }
}
