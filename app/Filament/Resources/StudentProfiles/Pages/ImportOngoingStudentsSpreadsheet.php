<?php

namespace App\Filament\Resources\StudentProfiles\Pages;

use App\Actions\StudentProfiles\OngoingStudentSpreadsheetExportService;
use App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService;
use App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser;
use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\CurriculumSpreadsheetWizard;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ImportOngoingStudentsSpreadsheet extends Page
{
    use WithFileUploads;

    protected static string $resource = StudentProfileResource::class;

    protected static ?string $navigationLabel = 'Import ongoing students';

    protected string $view = 'filament.admin.student-profiles.import-ongoing-students';

    public int $activeStep = 1;

    public int $maxUnlockedStep = 1;

    public mixed $uploadedFile = null;

    public mixed $file = null;

    public ?int $sheetIndex = null;

    public ?int $priorSubjectsSheetIndex = null;

    public bool $sendActivationEmails = true;

    /** @var array<string, int|string|null> */
    public array $columnMap = [];

    /** @var array<string, array<string, mixed>> */
    public array $rowOverrides = [];

    /** @var list<string> */
    public array $trackedFixRowKeys = [];

    public string $reviewTab = 'ready';

    public ?string $uploadError = null;

    /** @var array<string, string> */
    public array $mappingErrors = [];

    #[Locked]
    public ?string $lastSkippedImportCsvPath = null;

    /** @var array<string, int>|null */
    #[Locked]
    public ?array $lastImportSummary = null;

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    public function hydrate(): void
    {
        $this->authorizeAccess();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false;
    }

    public function getTitle(): string
    {
        return 'Import ongoing students';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Import ongoing students from spreadsheet';
    }

    public function getSubheading(): string|Htmlable
    {
        return 'Upload a Servitech student roster (.xlsx or .csv) with optional prior completed subjects to onboard existing students into TALA.';
    }

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [
            GradesAndCompletion::getUrl() => 'Students Record',
            $this->returnUrl() => 'Student records',
            'Import students',
        ];
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToProfiles')
                ->label('Back to student records')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url($this->returnUrl()),
        ];
    }

    public function returnUrl(): string
    {
        return GradesAndCompletion::canAccess()
            ? GradesAndCompletion::getUrl(['viewTab' => 'academic-progress'])
            : StudentProfileResource::getUrl('index');
    }

    public function updatedUploadedFile(): void
    {
        $this->file = $this->uploadedFile;
        $this->handleFileUploaded();
    }

    public function updatedFile(): void
    {
        $this->uploadedFile = $this->file;
        $this->handleFileUploaded();
    }

    public function clearUploadedFile(): void
    {
        $this->uploadedFile = null;
        $this->file = null;
        $this->sheetIndex = null;
        $this->priorSubjectsSheetIndex = null;
        $this->columnMap = [];
        $this->rowOverrides = [];
        $this->trackedFixRowKeys = [];
        $this->activeStep = 1;
        $this->maxUnlockedStep = 1;
        $this->uploadError = null;
        $this->mappingErrors = [];
        $this->lastImportSummary = null;
        $this->lastSkippedImportCsvPath = null;
    }

    public function updatedSheetIndex(): void
    {
        $this->uploadError = null;
        $this->mappingErrors = [];
        $this->columnMap = [];
        $this->rowOverrides = [];
        $this->trackedFixRowKeys = [];

        $extracted = $this->currentExtractedWorkbook();

        if ($extracted === null) {
            return;
        }

        $this->columnMap = $this->stringifyColumnMap($extracted['suggested_mapping']);
        $this->syncRowOverridesFromExtracted($extracted);

        if ($extracted['parsed_row_count'] === 0 && $extracted['prior_subjects_count'] === 0) {
            $this->uploadError = 'No student rows were found in this worksheet. Check that you selected the right tab or download our template.';
            $this->maxUnlockedStep = 1;
        }
    }

    public function updatedColumnMap(): void
    {
        $this->mappingErrors = [];
        $extracted = $this->currentExtractedWorkbook();

        if ($extracted !== null) {
            $this->syncRowOverridesFromExtracted($extracted);
        }
    }

    public function goToStep(int $step): void
    {
        if ($step <= 1) {
            $this->activeStep = 1;

            return;
        }

        $extracted = $this->currentExtractedWorkbook();

        if ($extracted === null) {
            $this->uploadError = 'Upload a valid Excel (.xlsx) or CSV (.csv) spreadsheet file to continue.';
            $this->activeStep = 1;

            return;
        }

        if ($extracted['parsed_row_count'] === 0 && $extracted['prior_subjects_count'] === 0) {
            $this->uploadError = 'No student rows were found in this worksheet. Check that you selected the right tab or download our template.';
            $this->activeStep = 1;

            return;
        }

        $this->uploadError = null;

        if ($this->columnMap === []) {
            $this->columnMap = $this->stringifyColumnMap($extracted['suggested_mapping']);
        }

        if ($step === 2) {
            $this->activeStep = 2;
            $this->maxUnlockedStep = max($this->maxUnlockedStep, 2);

            return;
        }

        $errors = $this->validateColumnMapErrors($this->columnMap);

        if ($errors !== []) {
            $this->mappingErrors = $errors;
            $this->activeStep = 2;
            $this->maxUnlockedStep = max($this->maxUnlockedStep, 2);

            return;
        }

        $this->mappingErrors = [];
        $this->syncRowOverridesFromExtracted($extracted);

        $preview = app(OngoingStudentSpreadsheetImportService::class)->buildPreview(
            parsedStudents: $extracted['parsed_students'],
            parsedPriorSubjects: $extracted['parsed_prior_subjects'],
            rowOverrides: $this->rowOverrides,
        );

        $this->reviewTab = $this->resolveDefaultReviewTab($preview['summary']);
        $this->activeStep = 3;
        $this->maxUnlockedStep = 3;
    }

    public function setReviewTab(string $tab): void
    {
        if (in_array($tab, ['fix_required', 'ready', 'already_onboarded', 'prior_subjects', 'all'], true)) {
            $this->reviewTab = $tab;
        }
    }

    public function toggleSkipRow(string $rowKey): void
    {
        if (! isset($this->rowOverrides[$rowKey])) {
            $extracted = $this->currentExtractedWorkbook();

            if ($extracted !== null) {
                $this->syncRowOverridesFromExtracted($extracted);
            }
        }

        if (! isset($this->rowOverrides[$rowKey])) {
            $this->rowOverrides[$rowKey] = [
                'row_key' => $rowKey,
                'skip' => true,
            ];
        } else {
            $this->rowOverrides[$rowKey]['skip'] = ! (bool) ($this->rowOverrides[$rowKey]['skip'] ?? false);
        }

        if (! in_array($rowKey, $this->trackedFixRowKeys, true)) {
            $this->trackedFixRowKeys[] = $rowKey;
        }
    }

    public function skipAllBlockedRows(): void
    {
        $extracted = $this->currentExtractedWorkbook();

        if ($extracted === null) {
            return;
        }

        $preview = app(OngoingStudentSpreadsheetImportService::class)->buildPreview(
            parsedStudents: $extracted['parsed_students'],
            parsedPriorSubjects: $extracted['parsed_prior_subjects'],
            rowOverrides: $this->rowOverrides,
        );

        foreach ($preview['rows'] as $row) {
            if (($row['status'] ?? '') === OngoingStudentSpreadsheetImportService::StatusBlocked) {
                $rowKey = (string) $row['row_key'];
                $this->rowOverrides[$rowKey] ??= $this->overrideBaselineFromRow($row);
                $this->rowOverrides[$rowKey]['skip'] = true;

                if (! in_array($rowKey, $this->trackedFixRowKeys, true)) {
                    $this->trackedFixRowKeys[] = $rowKey;
                }
            }
        }
    }

    public function downloadTemplate(string $format = 'xlsx'): BinaryFileResponse
    {
        $exportService = app(OngoingStudentSpreadsheetExportService::class);
        $path = $exportService->exportTemplate($format);

        return $exportService->downloadAndDelete($path, basename($path), $format);
    }

    #[On('download-skipped-import-rows')]
    public function downloadSkippedRowsCsv(): ?BinaryFileResponse
    {
        $path = $this->lastSkippedImportCsvPath ?: session()->pull('tala.skipped_ongoing_students_csv_path');

        if (! is_string($path) || ! is_file($path)) {
            return null;
        }

        $this->lastSkippedImportCsvPath = null;
        session()->forget('tala.skipped_ongoing_students_csv_path');

        return app(OngoingStudentSpreadsheetExportService::class)->downloadAndDelete($path, basename($path), 'csv');
    }

    public function commitImport(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);

        $extracted = $this->currentExtractedWorkbook();

        if ($extracted === null || ($extracted['parsed_row_count'] === 0 && $extracted['prior_subjects_count'] === 0)) {
            Notification::make()
                ->title('Upload a valid spreadsheet')
                ->body('No student rows were found to import.')
                ->danger()
                ->send();

            $this->activeStep = 1;

            return;
        }

        $errors = $this->validateColumnMapErrors($this->columnMap);

        if ($errors !== []) {
            $this->mappingErrors = $errors;
            $this->activeStep = 2;

            return;
        }

        $importService = app(OngoingStudentSpreadsheetImportService::class);
        $preview = $importService->buildPreview(
            parsedStudents: $extracted['parsed_students'],
            parsedPriorSubjects: $extracted['parsed_prior_subjects'],
            rowOverrides: $this->rowOverrides,
        );

        $readyStudentsCount = (int) ($preview['summary']['ready_new'] ?? 0);
        $readyPriorCount = (int) ($preview['summary']['prior_subjects_ready'] ?? 0);

        if ($readyStudentsCount === 0 && $readyPriorCount === 0) {
            Notification::make()
                ->title('No valid rows ready to import')
                ->body('Fix at least one student row with issues or adjust your column mappings before importing.')
                ->warning()
                ->send();

            return;
        }

        try {
            $result = $importService->commitImport(
                actor: $actor,
                previewRows: $preview['rows'],
                previewPriorSubjects: $preview['prior_subjects'],
                sendActivationEmails: $this->sendActivationEmails,
                skipBlocked: true,
            );
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Import could not be completed')
                ->body(collect($exception->errors())->flatten()->implode(' '))
                ->danger()
                ->send();

            return;
        }

        $skippedRows = $result['skipped_rows'] ?? [];
        $skippedCsvPath = null;

        if ($skippedRows !== []) {
            $skippedCsvPath = app(OngoingStudentSpreadsheetExportService::class)->exportSkippedRowsCsv($skippedRows);
            $this->lastSkippedImportCsvPath = $skippedCsvPath;
            session()->put('tala.skipped_ongoing_students_csv_path', $skippedCsvPath);
        } else {
            $this->lastSkippedImportCsvPath = null;
        }

        $this->lastImportSummary = [
            'created_students' => (int) ($result['created_students'] ?? 0),
            'skipped_existing_students' => (int) ($result['skipped_existing_students'] ?? 0),
            'prior_credits_recorded' => (int) ($result['prior_credits_recorded'] ?? 0),
            'activation_emails_sent' => (int) ($result['activation_emails_sent'] ?? 0),
            'activation_emails_failed' => (int) ($result['activation_emails_failed'] ?? 0),
            'skipped_rows_count' => count($skippedRows),
        ];

        $this->sendImportSummaryNotification($result, $skippedCsvPath);

        if ($skippedCsvPath === null) {
            $this->redirect($this->returnUrl());
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $rawState = $this->uploadedFile ?? $this->file;
        $extracted = $this->currentExtractedWorkbook();

        $preview = $extracted !== null && ($extracted['parsed_row_count'] > 0 || $extracted['prior_subjects_count'] > 0)
            ? app(OngoingStudentSpreadsheetImportService::class)->buildPreview(
                parsedStudents: $extracted['parsed_students'],
                parsedPriorSubjects: $extracted['parsed_prior_subjects'],
                rowOverrides: $this->rowOverrides,
            )
            : [
                'summary' => [
                    'total' => 0,
                    'ready_new' => 0,
                    'already_onboarded' => 0,
                    'blocked_count' => 0,
                    'skipped_count' => 0,
                    'warnings_count' => 0,
                    'prior_subjects_total' => 0,
                    'prior_subjects_ready' => 0,
                    'prior_subjects_blocked' => 0,
                ],
                'rows' => [],
                'prior_subjects' => [],
            ];

        return [
            'activeStep' => $this->activeStep,
            'maxUnlockedStep' => $this->maxUnlockedStep,
            'hasFile' => $rawState !== null && $extracted !== null,
            'filename' => $extracted['filename'] ?? null,
            'sheets' => $extracted['sheets'] ?? [],
            'sheetIndex' => $extracted['sheet_index'] ?? $this->sheetIndex ?? 0,
            'sheetName' => $extracted['sheet_name'] ?? null,
            'priorSubjectsSheetName' => $extracted['prior_subjects_sheet_name'] ?? null,
            'priorSubjectsCount' => (int) ($extracted['prior_subjects_count'] ?? 0),
            'headerOptions' => $extracted['header_options'] ?? [],
            'parsedRowCount' => (int) ($extracted['parsed_row_count'] ?? 0),
            'fieldLabels' => OngoingStudentSpreadsheetParser::canonicalFieldLabels(),
            'requiredFields' => OngoingStudentSpreadsheetParser::requiredFields(),
            'columnMap' => $this->columnMap,
            'uploadError' => $this->uploadError,
            'mappingErrors' => $this->mappingErrors,
            'summary' => $preview['summary'],
            'rows' => $preview['rows'],
            'priorSubjects' => $preview['prior_subjects'],
            'reviewTab' => $this->reviewTab,
            'trackedFixRowKeys' => $this->trackedFixRowKeys,
            'sendActivationEmails' => $this->sendActivationEmails,
            'standingOptions' => [
                StudentProfile::StandingRegular => 'Regular',
                StudentProfile::StandingIrregular => 'Irregular',
            ],
            'lastImportSummary' => $this->lastImportSummary,
            'hasSkippedRowsCsv' => $this->lastSkippedImportCsvPath !== null && is_file($this->lastSkippedImportCsvPath),
            'returnUrl' => $this->returnUrl(),
        ];
    }

    private function handleFileUploaded(): void
    {
        $this->uploadError = null;
        $this->mappingErrors = [];
        $this->columnMap = [];
        $this->rowOverrides = [];
        $this->trackedFixRowKeys = [];
        $this->activeStep = 1;
        $this->maxUnlockedStep = 1;
        $this->lastImportSummary = null;
        $this->lastSkippedImportCsvPath = null;

        $rawState = $this->uploadedFile ?? $this->file;
        $resolved = CurriculumSpreadsheetWizard::resolveUploadedFile($rawState);

        if ($resolved === null) {
            $this->uploadError = 'The uploaded file could not be read as a valid Excel (.xlsx) or CSV (.csv) spreadsheet.';

            return;
        }

        try {
            $inspected = app(OngoingStudentSpreadsheetParser::class)->inspectWorkbook(
                $resolved['path'],
                $resolved['extension'],
            );
        } catch (Throwable) {
            $this->uploadError = 'The uploaded file could not be read as a valid Excel (.xlsx) or CSV (.csv) spreadsheet.';

            return;
        }

        $this->sheetIndex = (int) $inspected['students_sheet_index'];
        $this->priorSubjectsSheetIndex = $inspected['prior_subjects_sheet_index'];

        $extracted = $this->currentExtractedWorkbook();

        if ($extracted === null) {
            $this->uploadError = 'The selected worksheet could not be read.';

            return;
        }

        $this->columnMap = $this->stringifyColumnMap($extracted['suggested_mapping']);
        $this->syncRowOverridesFromExtracted($extracted);

        if ($extracted['parsed_row_count'] === 0 && $extracted['prior_subjects_count'] === 0) {
            $this->uploadError = 'No student rows were found in this worksheet. Check that you selected the right tab or download our template.';
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentExtractedWorkbook(): ?array
    {
        $rawState = $this->uploadedFile ?? $this->file;

        if ($rawState === null) {
            return null;
        }

        $resolved = CurriculumSpreadsheetWizard::resolveUploadedFile($rawState);

        if ($resolved === null) {
            return null;
        }

        $parser = app(OngoingStudentSpreadsheetParser::class);

        try {
            $workbook = $parser->inspectWorkbook($resolved['path'], $resolved['extension']);
            $sheetIndex = $this->sheetIndex ?? (int) $workbook['students_sheet_index'];
            $extracted = $parser->extractSheet($resolved['path'], $sheetIndex, $resolved['extension']);

            $effectiveMap = $this->columnMap !== []
                ? $this->columnMap
                : $extracted['suggested_mapping'];

            $parsedStudents = $parser->parseStudentRows(
                path: $resolved['path'],
                sheetIndex: $sheetIndex,
                columnMap: $effectiveMap,
                extension: $resolved['extension'],
            );

            $priorSheetIndex = $this->priorSubjectsSheetIndex ?? $workbook['prior_subjects_sheet_index'];
            $parsedPriorSubjects = [];
            $priorSheetName = null;

            if ($priorSheetIndex !== null && $priorSheetIndex !== $sheetIndex) {
                $parsedPriorSubjects = $parser->parsePriorSubjectsSheet(
                    path: $resolved['path'],
                    sheetIndex: $priorSheetIndex,
                    extension: $resolved['extension'],
                );

                foreach ($workbook['sheets'] as $sheet) {
                    if ((int) $sheet['index'] === (int) $priorSheetIndex) {
                        $priorSheetName = (string) $sheet['name'];

                        break;
                    }
                }
            }
        } catch (Throwable) {
            return null;
        }

        return [
            'filename' => $this->extractUploadedFilename($rawState, $resolved['path']),
            'sheets' => $workbook['sheets'],
            'sheet_index' => (int) $extracted['sheet_index'],
            'sheet_name' => (string) $extracted['sheet_name'],
            'prior_subjects_sheet_name' => $priorSheetName,
            'header_options' => $extracted['header_options'],
            'suggested_mapping' => $extracted['suggested_mapping'],
            'parsed_students' => $parsedStudents,
            'parsed_row_count' => count($parsedStudents),
            'parsed_prior_subjects' => $parsedPriorSubjects,
            'prior_subjects_count' => count($parsedPriorSubjects),
        ];
    }

    /**
     * @param  array<string, mixed>  $extracted
     */
    private function syncRowOverridesFromExtracted(array $extracted): void
    {
        if (($extracted['parsed_students'] ?? []) === []) {
            $this->rowOverrides = [];
            $this->trackedFixRowKeys = [];

            return;
        }

        $basePreview = app(OngoingStudentSpreadsheetImportService::class)->buildPreview(
            parsedStudents: $extracted['parsed_students'],
            parsedPriorSubjects: $extracted['parsed_prior_subjects'] ?? [],
            rowOverrides: [],
        );

        $newOverrides = [];
        $trackedKeys = $this->trackedFixRowKeys;

        foreach ($basePreview['rows'] as $row) {
            $rowKey = (string) $row['row_key'];
            $isBlocked = ($row['status'] ?? '') === OngoingStudentSpreadsheetImportService::StatusBlocked;
            $prior = $this->rowOverrides[$rowKey] ?? null;

            if (! $isBlocked && $prior === null && ! in_array($rowKey, $trackedKeys, true)) {
                continue;
            }

            if ($isBlocked && ! in_array($rowKey, $trackedKeys, true)) {
                $trackedKeys[] = $rowKey;
            }

            $base = $this->overrideBaselineFromRow($row);
            $newOverrides[$rowKey] = [
                'row_key' => $rowKey,
                'source_row' => $base['source_row'],
                'student_number' => is_array($prior) && array_key_exists('student_number', $prior) ? (string) $prior['student_number'] : $base['student_number'],
                'first_name' => is_array($prior) && array_key_exists('first_name', $prior) ? (string) $prior['first_name'] : $base['first_name'],
                'last_name' => is_array($prior) && array_key_exists('last_name', $prior) ? (string) $prior['last_name'] : $base['last_name'],
                'email' => is_array($prior) && array_key_exists('email', $prior) ? (string) $prior['email'] : $base['email'],
                'program_code' => is_array($prior) && array_key_exists('program_code', $prior) ? (string) $prior['program_code'] : $base['program_code'],
                'curriculum_version_code' => is_array($prior) && array_key_exists('curriculum_version_code', $prior) ? (string) ($prior['curriculum_version_code'] ?? '') : $base['curriculum_version_code'],
                'academic_standing' => is_array($prior) && array_key_exists('academic_standing', $prior) ? (string) ($prior['academic_standing'] ?? '') : $base['academic_standing'],
                'skip' => is_array($prior) ? (bool) ($prior['skip'] ?? false) : false,
            ];
        }

        $this->rowOverrides = $newOverrides;
        $this->trackedFixRowKeys = array_values(array_unique($trackedKeys));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function overrideBaselineFromRow(array $row): array
    {
        return [
            'row_key' => (string) $row['row_key'],
            'source_row' => (int) $row['source_row'],
            'student_number' => (string) ($row['student_number'] ?? ''),
            'first_name' => (string) ($row['first_name'] ?? ''),
            'last_name' => (string) ($row['last_name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'program_code' => (string) ($row['program_code'] ?? ''),
            'curriculum_version_code' => (string) ($row['curriculum_version_code'] ?? ''),
            'academic_standing' => (string) ($row['academic_standing'] ?? StudentProfile::StandingRegular),
            'skip' => false,
        ];
    }

    /**
     * @param  array<string, int|string|null>  $columnMap
     * @return array<string, string>
     */
    private function validateColumnMapErrors(array $columnMap): array
    {
        $labels = OngoingStudentSpreadsheetParser::canonicalFieldLabels();
        $errors = [];

        foreach (OngoingStudentSpreadsheetParser::requiredFields() as $field) {
            $val = $columnMap[$field] ?? null;

            if ($val === null || $val === '' || $val === 'ignore' || $val === 'none') {
                $cleanLabel = rtrim((string) ($labels[$field] ?? $field), '* ');
                $errors[$field] = "Select the spreadsheet column for {$cleanLabel}.";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function resolveDefaultReviewTab(array $summary): string
    {
        if (($summary['blocked_count'] ?? 0) > 0) {
            return 'fix_required';
        }

        if (($summary['ready_new'] ?? 0) > 0) {
            return 'ready';
        }

        if (($summary['already_onboarded'] ?? 0) > 0) {
            return 'already_onboarded';
        }

        return 'all';
    }

    /**
     * @param  array<string, int|string|null>  $map
     * @return array<string, string>
     */
    private function stringifyColumnMap(array $map): array
    {
        $result = [];

        foreach ($map as $key => $val) {
            $result[$key] = $val !== null && $val !== '' ? (string) $val : 'ignore';
        }

        return $result;
    }

    private function extractUploadedFilename(mixed $rawState, string $fallbackPath): string
    {
        $candidate = $rawState;

        while (is_array($candidate)) {
            if ($candidate === []) {
                break;
            }

            $candidate = Arr::first($candidate);
        }

        if ($candidate instanceof TemporaryUploadedFile || $candidate instanceof UploadedFile) {
            $original = trim((string) $candidate->getClientOriginalName());

            if ($original !== '') {
                return $original;
            }
        }

        return basename($fallbackPath);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function sendImportSummaryNotification(array $result, ?string $skippedCsvPath): void
    {
        $created = (int) ($result['created_students'] ?? 0);
        $priorRecorded = (int) ($result['prior_credits_recorded'] ?? 0);
        $skippedExisting = (int) ($result['skipped_existing_students'] ?? 0);
        $emailsSent = (int) ($result['activation_emails_sent'] ?? 0);
        $skippedCount = count((array) ($result['skipped_rows'] ?? []));

        $title = $created > 0
            ? "Onboarded {$created} ".Str::plural('student', $created)
            : ($priorRecorded > 0
                ? "Recorded {$priorRecorded} prior ".Str::plural('subject', $priorRecorded)
                : 'No new students onboarded');

        $parts = [
            "{$created} ".Str::plural('student', $created).' created',
            "{$priorRecorded} prior ".Str::plural('credit', $priorRecorded).' recorded',
        ];

        if ($emailsSent > 0) {
            $parts[] = "{$emailsSent} activation ".Str::plural('email', $emailsSent).' sent';
        }

        if ($skippedExisting > 0) {
            $parts[] = "{$skippedExisting} already onboarded";
        }

        if ($skippedCount > 0) {
            $parts[] = "{$skippedCount} ".Str::plural('row', $skippedCount).' skipped';
        }

        $notification = Notification::make()
            ->title($title)
            ->body(implode(' · ', $parts).'.');

        if ($created > 0 || $priorRecorded > 0) {
            $notification->success();
        } else {
            $notification->warning();
        }

        if ($skippedCount > 0 && $skippedCsvPath !== null) {
            $notification->actions([
                Action::make('downloadSkippedRowsCsv')
                    ->label('Download skipped rows (.csv)')
                    ->button()
                    ->color('gray')
                    ->size('sm')
                    ->dispatch('download-skipped-import-rows'),
            ]);
        }

        $notification->send();
    }
}
