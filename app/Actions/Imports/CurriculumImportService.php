<?php

namespace App\Actions\Imports;

use App\Actions\AcademicFoundation\CurriculumScopeReadinessService;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\ImportBatch;
use App\Models\Program;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class CurriculumImportService
{
    private const UploadDirectory = 'imports/curriculum/uploads';

    public function __construct(private readonly CurriculumScopeReadinessService $readinessService) {}

    /**
     * @return array{directory:string, accepted_file_types:list<string>, max_size_kb:int}
     */
    public static function uploadContract(): array
    {
        return [
            'directory' => self::UploadDirectory,
            'accepted_file_types' => [
                'text/csv',
                'text/plain',
                'application/csv',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
            'max_size_kb' => 5120,
        ];
    }

    public function createPreview(string $filePath, string $fileName, User $actor): ImportBatch
    {
        $this->authorizeCurriculumImport($actor);
        $this->assertSupportedPath($filePath);

        $preview = $this->preview($filePath);

        return DB::transaction(function () use ($filePath, $fileName, $actor, $preview): ImportBatch {
            $timestamp = CarbonImmutable::now(config('app.timezone'));

            $batch = ImportBatch::query()->create([
                'import_type' => ImportBatch::TypeCurriculum,
                'file_name' => basename($fileName),
                'file_path' => $filePath,
                'total_rows' => $preview['summary']['total_rows'],
                'valid_rows' => $preview['summary']['valid_rows'],
                'error_rows' => $preview['summary']['error_rows'],
                'skipped_rows' => $preview['summary']['skipped_rows'],
                'status' => ImportBatch::StatusPendingReview,
                'imported_by' => $actor->id,
                'error_log' => $preview,
            ]);

            $this->recordActivity(
                event: 'import_batch_preview_created',
                actor: $actor,
                importBatch: $batch,
                properties: [
                    'import_batch_id' => $batch->id,
                    'import_type' => $batch->import_type,
                    'total_rows' => $batch->total_rows,
                    'valid_rows' => $batch->valid_rows,
                    'error_rows' => $batch->error_rows,
                    'status_after' => $batch->status,
                ],
                timestamp: $timestamp,
            );

            return $batch->fresh();
        });
    }

    public function commit(ImportBatch $importBatch, User $actor): ImportBatch
    {
        $this->authorizeCurriculumImport($actor);

        return DB::transaction(function () use ($importBatch, $actor): ImportBatch {
            $locked = ImportBatch::query()
                ->lockForUpdate()
                ->findOrFail($importBatch->getKey());

            if (! $locked->isPendingReview()) {
                throw ValidationException::withMessages([
                    'status' => 'Only pending import batches can be committed.',
                ]);
            }

            if ($locked->import_type !== ImportBatch::TypeCurriculum) {
                throw ValidationException::withMessages([
                    'import_type' => 'Only curriculum imports have a controlled TAL-12 commit pipeline.',
                ]);
            }

            if ((int) $locked->error_rows > 0) {
                throw ValidationException::withMessages([
                    'error_rows' => 'Import batches with validation errors cannot be committed.',
                ]);
            }

            if ((int) $locked->valid_rows < 1) {
                throw ValidationException::withMessages([
                    'valid_rows' => 'Import batches must contain at least one valid curriculum subject row before commit.',
                ]);
            }

            $this->assertSupportedPath($locked->file_path);
            $preview = $this->preview($locked->file_path);

            if ($preview['summary']['error_rows'] > 0) {
                $locked->forceFill([
                    'total_rows' => $preview['summary']['total_rows'],
                    'valid_rows' => $preview['summary']['valid_rows'],
                    'error_rows' => $preview['summary']['error_rows'],
                    'skipped_rows' => $preview['summary']['skipped_rows'],
                    'error_log' => $preview,
                ])->save();

                throw ValidationException::withMessages([
                    'error_rows' => 'The stored file no longer matches a valid preview. Review the import errors before commit.',
                ]);
            }

            if ($preview['summary']['valid_rows'] < 1) {
                $locked->forceFill([
                    'total_rows' => $preview['summary']['total_rows'],
                    'valid_rows' => $preview['summary']['valid_rows'],
                    'error_rows' => $preview['summary']['error_rows'],
                    'skipped_rows' => $preview['summary']['skipped_rows'],
                    'error_log' => $preview,
                ])->save();

                throw ValidationException::withMessages([
                    'valid_rows' => 'Import batches must contain at least one valid curriculum subject row before commit.',
                ]);
            }

            $programs = [];
            $subjects = [];
            $curriculums = [];
            $curriculumSubjects = [];
            $readinessScopes = [];

            foreach ($preview['valid_rows'] as $row) {
                $program = Program::query()->updateOrCreate(
                    ['code' => $row['program_code']],
                    [
                        'name' => $row['program_name'],
                        'department' => $row['education_level'],
                        'is_active' => true,
                    ],
                );
                $programs[$program->id] = true;

                $subject = Subject::query()->updateOrCreate(
                    ['code' => $row['subject_code']],
                    [
                        'description' => $row['subject_title'],
                        'units' => $row['units'],
                        'department' => $row['education_level'],
                        'category' => $row['category'],
                    ],
                );
                $subjects[$subject->id] = true;

                $curriculum = Curriculum::query()->updateOrCreate(
                    [
                        'program_id' => $program->id,
                        'effective_year' => $row['effective_year'],
                        'version_name' => $row['curriculum_version'],
                    ],
                    [
                        'is_active' => $row['is_active'],
                        'activated_at' => $row['is_active'] ? CarbonImmutable::now(config('app.timezone')) : null,
                    ],
                );
                $curriculums[$curriculum->id] = true;

                $curriculumSubject = CurriculumSubject::withoutEvents(fn (): CurriculumSubject => CurriculumSubject::query()->updateOrCreate(
                    [
                        'curriculum_id' => $curriculum->id,
                        'subject_id' => $subject->id,
                        'year_level' => $row['year_level'],
                        'semester' => $row['curriculum_period'],
                    ],
                    [
                        'weekly_contact_hours' => $row['weekly_contact_hours'],
                        'academic_subject_type' => $row['academic_subject_type'],
                        'scheduling_group' => $row['scheduling_group'],
                        'delivery_rule_override' => $row['delivery_rule_override'],
                        'sort_order' => $row['sort_order'],
                    ],
                ));
                $curriculumSubjects[$curriculumSubject->id] = true;

                $scope = $this->readinessService->markNeedsReview(
                    scope: $this->readinessService->scopeFor(
                        $curriculum,
                        $row['year_level'],
                        $row['curriculum_period'],
                    ),
                    actor: $actor,
                    reason: 'Curriculum import committed.',
                );
                $readinessScopes[$scope->id] = true;
            }

            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $summary = [
                'committed_rows' => count($preview['valid_rows']),
                'programs_touched' => count($programs),
                'subjects_touched' => count($subjects),
                'curriculums_touched' => count($curriculums),
                'curriculum_subjects_touched' => count($curriculumSubjects),
                'readiness_scopes_touched' => count($readinessScopes),
            ];

            $locked->forceFill([
                'total_rows' => $preview['summary']['total_rows'],
                'valid_rows' => $preview['summary']['valid_rows'],
                'error_rows' => $preview['summary']['error_rows'],
                'skipped_rows' => $preview['summary']['skipped_rows'],
                'status' => ImportBatch::StatusCommitted,
                'committed_by' => $actor->id,
                'committed_at' => $timestamp,
                'error_log' => [
                    ...$preview,
                    'commit_summary' => $summary,
                ],
            ])->save();

            $this->recordActivity(
                event: 'import_batch_committed',
                actor: $actor,
                importBatch: $locked,
                properties: [
                    'import_batch_id' => $locked->id,
                    'import_type' => $locked->import_type,
                    'status_after' => ImportBatch::StatusCommitted,
                    ...$summary,
                ],
                timestamp: $timestamp,
            );

            return $locked->fresh();
        });
    }

    /**
     * @return array{schema:string, headers:list<string>, summary:array{total_rows:int, valid_rows:int, error_rows:int, skipped_rows:int}, valid_rows:list<array<string, mixed>>, errors:list<array{row:int, messages:list<string>, values:array<string, mixed>}>}
     */
    private function preview(string $filePath): array
    {
        $rows = $this->readRows($filePath);
        $header = array_shift($rows) ?? [];
        $expectedHeaders = CurriculumImportTemplate::headers();

        if ($header !== $expectedHeaders) {
            throw ValidationException::withMessages([
                'file' => 'Curriculum import template headers do not match the required template.',
            ]);
        }

        $validRows = [];
        $errors = [];
        $skippedRows = 0;

        foreach ($rows as $index => $row) {
            $lineNumber = $index + 2;

            if ($this->blankRow($row)) {
                $skippedRows++;

                continue;
            }

            $normalized = $this->normalizeRow($expectedHeaders, $row);
            $messages = $this->validateRow($normalized);

            if ($messages !== []) {
                $errors[] = [
                    'row' => $lineNumber,
                    'messages' => $messages,
                    'values' => $normalized,
                ];

                continue;
            }

            $validRows[] = $this->typedRow($normalized);
        }

        return [
            'schema' => 'curriculum_preview_v2',
            'headers' => $expectedHeaders,
            'summary' => [
                'total_rows' => count($validRows) + count($errors),
                'valid_rows' => count($validRows),
                'error_rows' => count($errors),
                'skipped_rows' => $skippedRows,
            ],
            'valid_rows' => $validRows,
            'errors' => $errors,
        ];
    }

    /**
     * @return list<list<string|null>>
     */
    private function readRows(string $filePath): array
    {
        if (! Storage::disk('local')->exists($filePath)) {
            throw ValidationException::withMessages([
                'file' => 'Uploaded import file was not found in private storage.',
            ]);
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv', 'txt' => $this->readCsvRows($filePath),
            'xlsx' => $this->readSpreadsheetRows($filePath),
            default => throw ValidationException::withMessages([
                'file' => 'Only CSV and XLSX curriculum import files are supported.',
            ]),
        };
    }

    /**
     * @return list<list<string|null>>
     */
    private function readCsvRows(string $filePath): array
    {
        $stream = Storage::disk('local')->readStream($filePath);

        if ($stream === null || $stream === false) {
            throw ValidationException::withMessages([
                'file' => 'Uploaded import file could not be read.',
            ]);
        }

        $rows = [];

        while (($row = fgetcsv($stream)) !== false) {
            $rows[] = array_map(
                fn ($value): ?string => is_string($value) ? trim($value) : null,
                $row,
            );
        }

        fclose($stream);

        return $rows;
    }

    /**
     * @return list<list<string|null>>
     */
    private function readSpreadsheetRows(string $filePath): array
    {
        try {
            $spreadsheet = IOFactory::load(Storage::disk('local')->path($filePath));
            $sheet = $spreadsheet->getActiveSheet();
            $rows = [];

            foreach ($sheet->toArray(null, false, false, false) as $row) {
                $rows[] = array_map(
                    fn ($value): ?string => is_scalar($value) ? trim((string) $value) : null,
                    $row,
                );
            }

            return $rows;
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'file' => 'Uploaded spreadsheet could not be parsed: '.$exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  list<string|null>  $row
     */
    private function blankRow(array $row): bool
    {
        return collect($row)->every(fn (?string $value): bool => blank($value));
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string|null>  $row
     * @return array<string, string|null>
     */
    private function normalizeRow(array $headers, array $row): array
    {
        $normalized = [];

        foreach ($headers as $index => $header) {
            $normalized[$this->key($header)] = isset($row[$index]) && filled($row[$index])
                ? trim((string) $row[$index])
                : null;
        }

        return $normalized;
    }

    /**
     * @param  array<string, string|null>  $row
     * @return list<string>
     */
    private function validateRow(array $row): array
    {
        $messages = [];

        foreach ([
            'education_level',
            'program_code',
            'program_name',
            'curriculum_version',
            'effective_year',
            'is_active',
            'subject_code',
            'subject_title',
            'weekly_contact_hours',
            'academic_subject_type',
            'scheduling_group',
            'year_level',
            'curriculum_period',
        ] as $requiredField) {
            if (blank($row[$requiredField] ?? null)) {
                $messages[] = str($requiredField)->replace('_', ' ')->headline().' is required.';
            }
        }

        $educationLevel = $this->choiceValue($row['education_level'] ?? null);
        $academicSubjectType = $this->choiceValue($row['academic_subject_type'] ?? null);
        $schedulingGroup = $this->choiceValue($row['scheduling_group'] ?? null);
        $deliveryRuleOverride = $this->choiceValue($row['delivery_rule_override'] ?? null);

        if (filled($row['education_level'] ?? null) && ! in_array($educationLevel, ['college', 'shs'], true)) {
            $messages[] = 'Education Level must be college or shs.';
        }

        if (filled($row['effective_year'] ?? null) && ! preg_match('/^\d{4}$/', (string) $row['effective_year'])) {
            $messages[] = 'Effective Year must be a four-digit year.';
        }

        foreach (['units', 'weekly_contact_hours'] as $decimalField) {
            if (filled($row[$decimalField] ?? null) && ! is_numeric($row[$decimalField])) {
                $messages[] = str($decimalField)->replace('_', ' ')->headline().' must be numeric.';
            }
        }

        if ($educationLevel === 'college' && blank($row['units'] ?? null)) {
            $messages[] = 'Units is required for college curriculum rows.';
        }

        if (filled($row['sort_order'] ?? null) && ! ctype_digit((string) $row['sort_order'])) {
            $messages[] = 'Sort Order must be a whole number.';
        }

        if (filled($row['year_level'] ?? null) && ! array_key_exists((string) $row['year_level'], Section::yearLevelOptions())) {
            $messages[] = 'Year Level is not one of the approved section year/grade values.';
        }

        if (filled($row['curriculum_period'] ?? null) && ! array_key_exists((string) $row['curriculum_period'], Section::curriculumPeriodOptions())) {
            $messages[] = 'Curriculum Period is not one of the approved section periods.';
        }

        if (filled($row['academic_subject_type'] ?? null) && ! in_array($academicSubjectType, CurriculumSubject::academicSubjectTypeValues(), true)) {
            $messages[] = 'Academic Subject Type is not one of the approved scheduling classification values.';
        }

        if (filled($row['scheduling_group'] ?? null) && ! in_array($schedulingGroup, CurriculumSubject::schedulingGroupValues(), true)) {
            $messages[] = 'Scheduling Group is not one of the approved scheduling group values.';
        }

        if (filled($row['delivery_rule_override'] ?? null) && ! in_array($deliveryRuleOverride, CurriculumSubject::deliveryRuleOverrideValues(), true)) {
            $messages[] = 'Delivery Rule Override is not one of the approved override values.';
        }

        if (is_numeric($row['weekly_contact_hours'] ?? null)) {
            $weeklyContactHours = (float) $row['weekly_contact_hours'];

            if ($weeklyContactHours < 0) {
                $messages[] = 'Weekly Contact Hours cannot be negative.';
            }

            if ($schedulingGroup !== CurriculumSubject::SchedulingGroupModular && $weeklyContactHours <= 0) {
                $messages[] = 'Weekly Contact Hours must be greater than zero unless the row is modular.';
            }
        }

        if ($this->booleanValue($row['is_active'] ?? null) === null) {
            $messages[] = 'Is Active must be yes/no, true/false, or 1/0.';
        }

        foreach (['program_code', 'program_name', 'curriculum_version', 'subject_code', 'subject_title'] as $field) {
            if ($this->looksLikeFormula($row[$field] ?? null)) {
                $messages[] = str($field)->replace('_', ' ')->headline().' cannot start with a spreadsheet formula character.';
            }
        }

        return $messages;
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array<string, mixed>
     */
    private function typedRow(array $row): array
    {
        return [
            'education_level' => $this->choiceValue($row['education_level'] ?? null),
            'program_code' => strtoupper((string) $row['program_code']),
            'program_name' => (string) $row['program_name'],
            'curriculum_version' => (string) $row['curriculum_version'],
            'effective_year' => (string) $row['effective_year'],
            'is_active' => (bool) $this->booleanValue($row['is_active'] ?? null),
            'subject_code' => strtoupper((string) $row['subject_code']),
            'subject_title' => (string) $row['subject_title'],
            'units' => filled($row['units'] ?? null) ? number_format((float) $row['units'], 2, '.', '') : '0.00',
            'weekly_contact_hours' => number_format((float) $row['weekly_contact_hours'], 2, '.', ''),
            'academic_subject_type' => $this->choiceValue($row['academic_subject_type'] ?? null),
            'scheduling_group' => $this->choiceValue($row['scheduling_group'] ?? null),
            'delivery_rule_override' => filled($row['delivery_rule_override'] ?? null) ? $this->choiceValue($row['delivery_rule_override']) : null,
            'category' => filled($row['category'] ?? null) ? (string) $row['category'] : null,
            'year_level' => (string) $row['year_level'],
            'curriculum_period' => (string) $row['curriculum_period'],
            'sort_order' => filled($row['sort_order'] ?? null) ? (int) $row['sort_order'] : 0,
        ];
    }

    private function booleanValue(?string $value): ?bool
    {
        return match (strtolower(trim((string) $value))) {
            '1', 'yes', 'y', 'true', 'active' => true,
            '0', 'no', 'n', 'false', 'inactive' => false,
            default => null,
        };
    }

    private function choiceValue(?string $value): string
    {
        return str($value ?? '')
            ->trim()
            ->lower()
            ->replace(' ', '_')
            ->toString();
    }

    private function looksLikeFormula(?string $value): bool
    {
        if (blank($value)) {
            return false;
        }

        return in_array(substr(trim((string) $value), 0, 1), ['=', '+', '-', '@'], true);
    }

    private function key(string $header): string
    {
        if ($header === 'Year/Grade') {
            return 'year_level';
        }

        return str($header)->lower()->replace(['/', '-'], ' ')->snake()->toString();
    }

    private function assertSupportedPath(string $filePath): void
    {
        if (! str_starts_with($filePath, self::UploadDirectory.'/')) {
            throw ValidationException::withMessages([
                'file' => 'Curriculum imports must be uploaded through the approved private import directory.',
            ]);
        }
    }

    private function authorizeCurriculumImport(User $actor): void
    {
        if ($actor->can('manage-curricula')) {
            return;
        }

        throw new AuthorizationException('Only authorized curriculum managers can upload or commit curriculum imports.');
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function recordActivity(string $event, User $actor, ImportBatch $importBatch, array $properties, CarbonImmutable $timestamp): void
    {
        DB::table('activity_log')->insert([
            'log_name' => 'imports',
            'description' => 'Import batch state changed.',
            'subject_type' => ImportBatch::class,
            'subject_id' => null,
            'event' => $event,
            'causer_type' => User::class,
            'causer_id' => $actor->id,
            'properties' => json_encode($properties, JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }
}
