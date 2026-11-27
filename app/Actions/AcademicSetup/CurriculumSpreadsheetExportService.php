<?php

namespace App\Actions\AcademicSetup;

use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Term;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CurriculumSpreadsheetExportService
{
    /**
     * Neutralize CSV/XLSX formula injection by prefixing an apostrophe on string values starting with `=`, `+`, `-`, `@`, `\t`, or `\r`.
     */
    public static function neutralizeFormulaInjection(mixed $value): float|int|string
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if ($value === null) {
            return '';
        }

        $string = (string) $value;

        if ($string !== '' && in_array($string[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$string;
        }

        return $string;
    }

    /**
     * Canonical column headers for Subject Catalog exports and templates.
     *
     * @return list<string>
     */
    public static function subjectHeaders(): array
    {
        return [
            'Subject Code',
            'Subject Name',
            'Total Units',
            'Lec Hours/Week',
            'Lab Hours/Week',
            'Prerequisites',
            'Subject Type',
            'Schedule Type',
            'Description',
        ];
    }

    /**
     * Canonical round-trip column headers for Curriculum exports and templates.
     *
     * @return list<string>
     */
    public static function curriculumHeaders(): array
    {
        return [
            'Subject Code',
            'Subject Name',
            'Year Level',
            'Semester',
            'Total Units',
            'Lec Hours/Week',
            'Lab Hours/Week',
            'Prerequisites',
            'Subject Type',
            'Schedule Type',
            'Description',
        ];
    }

    /**
     * Export a collection of Course models to a temporary `.xlsx` or `.csv` file and return its absolute path.
     * The file's basename is the suggested download filename (`subjects-catalog-YYYY-MM-DD.xlsx|csv`).
     *
     * @param  Collection<int, Course>  $courses
     */
    public function exportSubjects(Collection $courses, string $format = 'xlsx'): string
    {
        $ext = $this->normalizeFormat($format);
        $filename = $this->suggestedSubjectsFilename($ext);
        $path = $this->prepareExportPath($filename);

        if ($courses instanceof EloquentCollection) {
            $courses->loadMissing([
                'specifications' => fn ($query) => $query
                    ->with(['components', 'requirements.relatedCourse'])
                    ->orderByRaw("CASE WHEN state = 'ACTIVE' THEN 0 WHEN state = 'DRAFT' THEN 1 ELSE 2 END")
                    ->orderByDesc('id'),
            ]);
        }

        $rows = [self::subjectHeaders()];

        foreach ($courses as $course) {
            if (! $course instanceof Course) {
                continue;
            }

            $spec = $this->selectExportSpecification($course);
            $metrics = $this->extractSpecificationExportMetrics($spec);

            $rows[] = [
                self::neutralizeFormulaInjection((string) $course->code),
                self::neutralizeFormulaInjection($metrics['title']),
                $metrics['credit_units'],
                $metrics['lec_hours'],
                $metrics['lab_hours'],
                self::neutralizeFormulaInjection($metrics['prerequisites']),
                self::neutralizeFormulaInjection($metrics['subject_type']),
                self::neutralizeFormulaInjection($metrics['schedule_type']),
                self::neutralizeFormulaInjection($metrics['description']),
            ];
        }

        $this->writeSpreadsheet($path, $ext, $rows, 'Subjects');

        return $path;
    }

    /**
     * Export a CurriculumVersion's entries ordered by Year Level, Semester, and Sequence in canonical round-trip columns.
     * Returns the absolute file path whose basename is the suggested download filename.
     */
    public function exportCurriculum(CurriculumVersion $curriculumVersion, string $format = 'xlsx'): string
    {
        $ext = $this->normalizeFormat($format);
        $filename = $this->suggestedCurriculumFilename($curriculumVersion, $ext);
        $path = $this->prepareExportPath($filename);

        $curriculumVersion->loadMissing([
            'program',
            'academicYears',
            'entries.courseSpecification.course',
            'entries.courseSpecification.components',
            'entries.courseSpecification.requirements.relatedCourse',
        ]);

        $sortedEntries = $curriculumVersion->entries
            ->sortBy(fn (CurriculumEntry $entry): array => [
                (int) $entry->year_level,
                $this->termSortOrder((string) $entry->term_type),
                (int) $entry->sequence,
                (int) $entry->id,
            ])
            ->values();

        $rows = [self::curriculumHeaders()];

        foreach ($sortedEntries as $entry) {
            $spec = $entry->courseSpecification;
            $courseCode = (string) ($spec?->course?->code ?? '');
            $metrics = $this->extractSpecificationExportMetrics($spec);
            $termLabel = $this->exportTermLabel((string) $entry->term_type);

            $rows[] = [
                self::neutralizeFormulaInjection($courseCode),
                self::neutralizeFormulaInjection($metrics['title']),
                (int) $entry->year_level,
                self::neutralizeFormulaInjection($termLabel),
                $metrics['credit_units'],
                $metrics['lec_hours'],
                $metrics['lab_hours'],
                self::neutralizeFormulaInjection($metrics['prerequisites']),
                self::neutralizeFormulaInjection($metrics['subject_type']),
                self::neutralizeFormulaInjection($metrics['schedule_type']),
                self::neutralizeFormulaInjection($metrics['description']),
            ];
        }

        $sheetTitle = $curriculumVersion->program?->code
            ? substr(preg_replace('/[^A-Za-z0-9 _-]/', '', (string) $curriculumVersion->program->code) ?: 'Curriculum', 0, 31)
            : 'Curriculum';

        $summarySheetRows = $ext === 'xlsx'
            ? $this->buildCurriculumSummaryRows($curriculumVersion)
            : null;

        $this->writeSpreadsheet($path, $ext, $rows, $sheetTitle, $summarySheetRows, 'Summary');

        return $path;
    }

    /**
     * Generate a clean `.xlsx` or `.csv` import template with canonical headers and 3 clear sample rows.
     *
     * @param  string  $scope  `'curriculum'` or `'subjects'` (or `'catalog'`)
     */
    public function exportTemplate(string $scope = 'curriculum', string $format = 'xlsx'): string
    {
        $normalizedScope = strtolower(trim($scope)) === 'curriculum' ? 'curriculum' : 'subjects';
        $ext = $this->normalizeFormat($format);
        $filename = $this->suggestedTemplateFilename($normalizedScope, $ext);
        $path = $this->prepareExportPath($filename);

        if ($normalizedScope === 'curriculum') {
            $rows = [
                self::curriculumHeaders(),
                [
                    'GE 1',
                    'Understanding the Self',
                    1,
                    'First Semester',
                    3,
                    3,
                    0,
                    'None',
                    'Regular',
                    'Recurring',
                    'General education core lecture subject.',
                ],
                [
                    'HPC 2',
                    'Fundamentals in Food Service Operations',
                    1,
                    'Second Semester',
                    3,
                    2,
                    3,
                    'GE 1',
                    'TESDA Competency',
                    'Recurring',
                    'Aligned with Food and Beverage Services (FBS) NC II.',
                ],
                [
                    'PRAC 1',
                    'Supervised Work-Related Training 1 (300 hours)',
                    2,
                    'Summer',
                    6,
                    0,
                    0,
                    'HPC 2',
                    'Regular',
                    'ExternallyArranged',
                    'Externally arranged industry practicum / OJT.',
                ],
            ];
        } else {
            $rows = [
                self::subjectHeaders(),
                [
                    'GE 1',
                    'Understanding the Self',
                    3,
                    3,
                    0,
                    'None',
                    'Regular',
                    'Recurring',
                    'General education core lecture subject.',
                ],
                [
                    'HPC 2',
                    'Fundamentals in Food Service Operations',
                    3,
                    2,
                    3,
                    'GE 1',
                    'TESDA Competency',
                    'Recurring',
                    'Aligned with Food and Beverage Services (FBS) NC II.',
                ],
                [
                    'PRAC 1',
                    'Supervised Work-Related Training 1 (300 hours)',
                    6,
                    0,
                    0,
                    'HPC 2',
                    'Regular',
                    'ExternallyArranged',
                    'Externally arranged industry practicum / OJT.',
                ],
            ];
        }

        $this->writeSpreadsheet($path, $ext, $rows, 'Template');

        return $path;
    }

    /**
     * Return a BinaryFileResponse configured to delete the temporary file after download.
     */
    public function downloadAndDelete(string $path, ?string $downloadName = null, ?string $format = null): BinaryFileResponse
    {
        $resolvedFormat = $this->normalizeFormat($format ?: pathinfo($path, PATHINFO_EXTENSION));
        $resolvedName = filled($downloadName) ? (string) $downloadName : basename($path);

        $contentType = $resolvedFormat === 'csv'
            ? 'text/csv; charset=UTF-8'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response()
            ->download($path, $resolvedName, [
                'Content-Type' => $contentType,
            ])
            ->deleteFileAfterSend(true);
    }

    public function suggestedSubjectsFilename(string $format = 'xlsx'): string
    {
        $ext = $this->normalizeFormat($format);

        return 'subjects-catalog-'.now()->format('Y-m-d').'.'.$ext;
    }

    public function suggestedCurriculumFilename(CurriculumVersion $curriculumVersion, string $format = 'xlsx'): string
    {
        $ext = $this->normalizeFormat($format);
        $curriculumVersion->loadMissing('program');

        $programPart = Str::slug((string) ($curriculumVersion->program?->code ?? 'program'));
        $versionPart = Str::slug((string) ($curriculumVersion->version_code ?: $curriculumVersion->name ?: 'curriculum'));

        return "curriculum-{$programPart}-{$versionPart}-".now()->format('Y-m-d').'.'.$ext;
    }

    public function suggestedTemplateFilename(string $scope = 'curriculum', string $format = 'xlsx'): string
    {
        $normalizedScope = strtolower(trim($scope)) === 'curriculum' ? 'curriculum' : 'subjects';
        $ext = $this->normalizeFormat($format);

        return "{$normalizedScope}-import-template.{$ext}";
    }

    /**
     * @return array{
     *     title: string,
     *     credit_units: float,
     *     lec_hours: float,
     *     lab_hours: float,
     *     prerequisites: string,
     *     subject_type: string,
     *     schedule_type: string,
     *     description: string
     * }
     */
    private function extractSpecificationExportMetrics(?CourseSpecification $spec): array
    {
        if (! $spec instanceof CourseSpecification) {
            return [
                'title' => '',
                'credit_units' => 0.0,
                'lec_hours' => 0.0,
                'lab_hours' => 0.0,
                'prerequisites' => '',
                'subject_type' => CourseSpecification::shortClassificationLabel(CourseSpecification::AcademicClassificationOrdinary),
                'schedule_type' => CourseSpecification::SchedulingRecurring,
                'description' => '',
            ];
        }

        $components = $spec->relationLoaded('components')
            ? $spec->components
            : $spec->components()->get();

        $requirements = $spec->relationLoaded('requirements')
            ? $spec->requirements
            : $spec->requirements()->with('relatedCourse')->get();

        $lecHours = 0.0;
        $labHours = 0.0;

        if ($spec->scheduling_treatment !== CourseSpecification::SchedulingExternallyArranged) {
            foreach ($components as $component) {
                if ($component->component_type === CourseComponent::TypeLaboratory) {
                    $labHours += (float) $component->weekly_contact_hours;
                } else {
                    $lecHours += (float) $component->weekly_contact_hours;
                }
            }
        }

        $prereqCodes = $requirements
            ->where('rule_type', CourseRequirement::TypePrerequisite)
            ->sortBy(fn (CourseRequirement $req): array => [(int) $req->sequence, (int) $req->id])
            ->map(fn (CourseRequirement $req): string => trim((string) ($req->relatedCourse?->code ?? '')))
            ->filter(fn (string $code): bool => $code !== '')
            ->unique()
            ->values()
            ->all();

        return [
            'title' => (string) $spec->title,
            'credit_units' => round((float) $spec->credit_units, 2),
            'lec_hours' => round($lecHours, 2),
            'lab_hours' => round($labHours, 2),
            'prerequisites' => implode(', ', $prereqCodes),
            'subject_type' => CourseSpecification::shortClassificationLabel($spec->academic_classification),
            'schedule_type' => $spec->scheduling_treatment === CourseSpecification::SchedulingExternallyArranged
                ? CourseSpecification::SchedulingExternallyArranged
                : CourseSpecification::SchedulingRecurring,
            'description' => (string) ($spec->description ?? ''),
        ];
    }

    private function selectExportSpecification(Course $course): ?CourseSpecification
    {
        $specifications = $course->relationLoaded('specifications')
            ? $course->specifications
            : $course->specifications()
                ->with(['components', 'requirements.relatedCourse'])
                ->orderByRaw("CASE WHEN state = 'ACTIVE' THEN 0 WHEN state = 'DRAFT' THEN 1 ELSE 2 END")
                ->orderByDesc('id')
                ->get();

        return $specifications->first();
    }

    private function termSortOrder(string $termType): int
    {
        return match ($termType) {
            Term::TypeFirstSemester => 1,
            Term::TypeSecondSemester => 2,
            Term::TypeSpecialTerm => 3,
            default => 4,
        };
    }

    private function exportTermLabel(string $termType): string
    {
        return match ($termType) {
            Term::TypeFirstSemester => 'First Semester',
            Term::TypeSecondSemester => 'Second Semester',
            Term::TypeSpecialTerm => 'Summer',
            default => $termType,
        };
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

    /**
     * @return list<list<float|int|string>>
     */
    private function buildCurriculumSummaryRows(CurriculumVersion $curriculumVersion): array
    {
        $program = $curriculumVersion->program;
        $programDisplay = $program
            ? trim(collect([$program->name, $program->code ? '('.$program->code.')' : null])->filter()->implode(' '))
            : 'Not assigned';
        $batches = $curriculumVersion->academicYears
            ->sortBy('starts_on')
            ->pluck('label')
            ->filter()
            ->implode(', ');

        return [
            ['Field', 'Value'],
            ['Program', self::neutralizeFormulaInjection($programDisplay)],
            ['Curriculum Name', self::neutralizeFormulaInjection((string) ($curriculumVersion->name ?: $curriculumVersion->version_code))],
            ['Assigned Batches', self::neutralizeFormulaInjection($batches !== '' ? $batches : 'Unassigned')],
            ['Status', self::neutralizeFormulaInjection(str((string) $curriculumVersion->state)->lower()->headline()->toString())],
            ['Export Date', now()->timezone(config('app.display_timezone'))->format('Y-m-d H:i:s T')],
        ];
    }

    /**
     * @param  list<list<float|int|string>>  $rows
     * @param  list<list<float|int|string>>|null  $secondaryRows
     */
    private function writeSpreadsheet(
        string $path,
        string $format,
        array $rows,
        string $sheetName = 'Sheet1',
        ?array $secondaryRows = null,
        string $secondarySheetName = 'Summary',
    ): void {
        /** @var WriterInterface $writer */
        $writer = $format === 'csv' ? new CsvWriter : new XlsxWriter;
        $writer->openToFile($path);

        try {
            if ($writer instanceof XlsxWriter && $sheetName !== '') {
                $writer->getCurrentSheet()->setName(substr($sheetName, 0, 31));
            }

            foreach ($rows as $rowValues) {
                $writer->addRow(Row::fromValues($rowValues));
            }

            if ($writer instanceof XlsxWriter && is_array($secondaryRows) && $secondaryRows !== []) {
                $summarySheet = $writer->addNewSheetAndMakeItCurrent();
                $summarySheet->setName(substr($secondarySheetName, 0, 31));

                foreach ($secondaryRows as $rowValues) {
                    $writer->addRow(Row::fromValues($rowValues));
                }
            }
        } finally {
            $writer->close();
        }
    }
}
