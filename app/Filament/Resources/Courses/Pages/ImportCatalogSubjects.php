<?php

namespace App\Filament\Resources\Courses\Pages;

use App\Actions\AcademicSetup\CurriculumSpreadsheetExportService;
use App\Actions\AcademicSetup\CurriculumSpreadsheetImportService;
use App\Actions\AcademicSetup\CurriculumSpreadsheetParser;
use App\Filament\Pages\CatalogCurriculaWorkbench;
use App\Filament\Resources\Courses\CourseResource;
use App\Models\Course;
use App\Models\Term;
use App\Models\User;
use App\Support\CurriculumSpreadsheetWizard;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ImportCatalogSubjects extends Page
{
    use WithFileUploads;

    protected static string $resource = CourseResource::class;

    protected static ?string $navigationLabel = 'Import subjects';

    protected string $view = 'filament.resources.curriculum-versions.pages.import-curriculum-spreadsheet';

    #[Url]
    public ?int $oversightTermId = null;

    public int $activeStep = 1;

    public int $maxUnlockedStep = 1;

    public mixed $uploadedFile = null;

    public mixed $file = null;

    public ?int $sheetIndex = null;

    public string $importMode = 'add_only';

    public bool $confirmReplaceDraft = false;

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
        $user = auth()->user();

        return $user instanceof User && $user->can('create', Course::class);
    }

    public function isCurriculumImport(): bool
    {
        return false;
    }

    public function getTitle(): string
    {
        return 'Import subjects';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Import subjects from spreadsheet';
    }

    public function getSubheading(): string|Htmlable
    {
        return 'Upload an Excel (.xlsx) or CSV (.csv) file, match columns, and review new and existing catalog subjects before importing.';
    }

    public function getBreadcrumbs(): array
    {
        $params = array_filter([
            'oversightTermId' => $this->oversightTermId,
        ], fn (mixed $value): bool => $value !== null);

        return [
            CatalogCurriculaWorkbench::getUrl($params) => 'Programs & Curricula',
            $this->returnUrl() => 'Subjects',
            'Import subjects',
        ];
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToSubjects')
                ->label('Back to subjects')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url($this->returnUrl()),
        ];
    }

    public function returnUrl(): string
    {
        return CourseResource::getUrl('index', array_filter([
            'oversightTermId' => $this->oversightTermId,
        ], fn (mixed $value): bool => $value !== null));
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
        $this->columnMap = [];
        $this->rowOverrides = [];
        $this->trackedFixRowKeys = [];
        $this->activeStep = 1;
        $this->maxUnlockedStep = 1;
        $this->uploadError = null;
        $this->mappingErrors = [];
    }

    public function updatedSheetIndex(): void
    {
        $this->uploadError = null;
        $this->mappingErrors = [];
        $this->columnMap = [];
        $this->rowOverrides = [];
        $this->trackedFixRowKeys = [];

        $extracted = $this->currentExtractedSheet();

        if ($extracted === null) {
            return;
        }

        $this->columnMap = $this->stringifyColumnMap($extracted['column_map']);
        $this->syncRowOverridesFromExtracted($extracted);

        if ($extracted['parsed_row_count'] === 0) {
            $this->uploadError = 'No subject rows were found in this worksheet. Check that you selected the right tab or download our template.';
            $this->maxUnlockedStep = 1;
        }
    }

    public function updatedColumnMap(): void
    {
        $this->mappingErrors = [];
        $extracted = $this->currentExtractedSheet();

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

        $extracted = $this->currentExtractedSheet();

        if ($extracted === null) {
            $this->uploadError = 'Upload a valid Excel (.xlsx) or CSV (.csv) spreadsheet file to continue.';
            $this->activeStep = 1;

            return;
        }

        if ($extracted['parsed_row_count'] === 0) {
            $this->uploadError = 'No subject rows were found in this worksheet. Check that you selected the right tab or download our template.';
            $this->activeStep = 1;

            return;
        }

        $this->uploadError = null;

        if ($this->columnMap === []) {
            $this->columnMap = $this->stringifyColumnMap($extracted['column_map']);
        }

        if ($step === 2) {
            $this->activeStep = 2;
            $this->maxUnlockedStep = max($this->maxUnlockedStep, 2);

            return;
        }

        $errors = CurriculumSpreadsheetWizard::validateColumnMapErrors(
            columnMap: $this->columnMap,
            includePlacement: false,
            detectedBanners: $extracted['detected_banners'],
        );

        if ($errors !== []) {
            $this->mappingErrors = $errors;
            $this->activeStep = 2;
            $this->maxUnlockedStep = max($this->maxUnlockedStep, 2);

            return;
        }

        $this->mappingErrors = [];
        $this->syncRowOverridesFromExtracted($extracted);

        $preview = CurriculumSpreadsheetWizard::buildFullPagePreviewData(
            parsedRows: $extracted['parsed_rows'],
            includePlacement: false,
            curriculum: null,
            rowOverrides: $this->rowOverrides,
            importMode: 'add_only',
        );

        $this->reviewTab = $this->resolveDefaultReviewTab($preview['summary']);
        $this->activeStep = 3;
        $this->maxUnlockedStep = 3;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function resolveDefaultReviewTab(array $summary): string
    {
        if (($summary['blocked_count'] ?? 0) > 0) {
            return 'fix_required';
        }

        if (($summary['ready_count'] ?? 0) > 0) {
            return 'ready';
        }

        if (($summary['warnings_count'] ?? 0) > 0) {
            return 'notes';
        }

        return 'all';
    }

    public function setReviewTab(string $tab): void
    {
        if (in_array($tab, ['fix_required', 'notes', 'ready', 'all'], true)) {
            $this->reviewTab = $tab;
        }
    }

    public function toggleSkipRow(string $rowKey): void
    {
        if (! isset($this->rowOverrides[$rowKey])) {
            $extracted = $this->currentExtractedSheet();

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
        $extracted = $this->currentExtractedSheet();

        if ($extracted === null) {
            return;
        }

        $preview = CurriculumSpreadsheetWizard::buildFullPagePreviewData(
            parsedRows: $extracted['parsed_rows'],
            includePlacement: false,
            curriculum: null,
            rowOverrides: $this->rowOverrides,
            importMode: 'add_only',
        );

        foreach ($preview['rows'] as $row) {
            if (($row['status'] ?? '') === CurriculumSpreadsheetImportService::StatusBlocked) {
                $rowKey = (string) $row['row_key'];
                $this->rowOverrides[$rowKey] ??= [
                    'row_key' => $rowKey,
                    'source_row' => (int) $row['source_row'],
                    'code' => (string) $row['code'],
                    'title' => (string) $row['title'],
                    'credit_units' => $row['credit_units'],
                ];
                $this->rowOverrides[$rowKey]['skip'] = true;

                if (! in_array($rowKey, $this->trackedFixRowKeys, true)) {
                    $this->trackedFixRowKeys[] = $rowKey;
                }
            }
        }
    }

    public function downloadTemplate(string $format = 'xlsx'): BinaryFileResponse
    {
        $exportService = app(CurriculumSpreadsheetExportService::class);
        $path = $exportService->exportTemplate('subjects', $format);

        return $exportService->downloadAndDelete($path, basename($path), $format);
    }

    #[On('download-skipped-import-rows')]
    public function downloadSkippedRowsCsv(): ?BinaryFileResponse
    {
        $path = $this->lastSkippedImportCsvPath ?: session()->pull('tala.skipped_import_csv_path');

        if (! is_string($path) || ! is_file($path)) {
            return null;
        }

        $this->lastSkippedImportCsvPath = null;

        return app(CurriculumSpreadsheetExportService::class)->downloadAndDelete($path, basename($path), 'csv');
    }

    public function commitImport(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        Gate::forUser($actor)->authorize('create', Course::class);

        $extracted = $this->currentExtractedSheet();

        if ($extracted === null || $extracted['parsed_row_count'] === 0) {
            Notification::make()
                ->title('Upload a valid spreadsheet')
                ->body('No subject rows were found to import.')
                ->danger()
                ->send();

            $this->activeStep = 1;

            return;
        }

        $errors = CurriculumSpreadsheetWizard::validateColumnMapErrors(
            columnMap: $this->columnMap,
            includePlacement: false,
            detectedBanners: $extracted['detected_banners'],
        );

        if ($errors !== []) {
            $this->mappingErrors = $errors;
            $this->activeStep = 2;

            return;
        }

        $preview = CurriculumSpreadsheetWizard::buildFullPagePreviewData(
            parsedRows: $extracted['parsed_rows'],
            includePlacement: false,
            curriculum: null,
            rowOverrides: $this->rowOverrides,
            importMode: 'add_only',
        );

        $readyCount = $preview['summary']['ready_new'] + $preview['summary']['ready_existing'];

        if ($readyCount === 0) {
            Notification::make()
                ->title('No valid subjects ready to import')
                ->body('Fix at least one row with issues or adjust your column mappings before importing.')
                ->warning()
                ->send();

            return;
        }

        $importService = app(CurriculumSpreadsheetImportService::class);

        try {
            $result = $importService->commitImport(
                actor: $actor,
                previewRows: $preview['rows'],
                curriculumVersion: null,
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
            $skippedCsvPath = $importService->exportSkippedRowsCsv($skippedRows);
            $this->lastSkippedImportCsvPath = $skippedCsvPath;
            session()->put('tala.skipped_import_csv_path', $skippedCsvPath);
        }

        CurriculumSpreadsheetWizard::sendImportSummaryNotification(
            result: $result,
            includePlacement: false,
            skippedCsvPath: $skippedCsvPath,
        );

        $this->redirect($this->returnUrl());
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $rawState = $this->uploadedFile ?? $this->file;
        $extracted = $this->currentExtractedSheet();

        $preview = $extracted !== null && $extracted['parsed_row_count'] > 0
            ? CurriculumSpreadsheetWizard::buildFullPagePreviewData(
                parsedRows: $extracted['parsed_rows'],
                includePlacement: false,
                curriculum: null,
                rowOverrides: $this->rowOverrides,
                importMode: 'add_only',
            )
            : [
                'summary' => [
                    'total' => 0,
                    'ready_new' => 0,
                    'ready_existing' => 0,
                    'already_in_draft_count' => 0,
                    'existing_draft_entry_count' => 0,
                    'warnings_count' => 0,
                    'blocked_count' => 0,
                    'skipped_count' => 0,
                ],
                'rows' => [],
                'placementGroups' => [],
            ];

        return [
            'isCurriculum' => false,
            'curriculum' => null,
            'program' => null,
            'existingCount' => 0,
            'importMode' => 'add_only',
            'confirmReplaceDraft' => false,
            'activeStep' => $this->activeStep,
            'maxUnlockedStep' => $this->maxUnlockedStep,
            'hasFile' => $rawState !== null && $extracted !== null,
            'filename' => $extracted['filename'] ?? null,
            'sheets' => $extracted['sheets'] ?? [],
            'sheetIndex' => $extracted['sheet_index'] ?? $this->sheetIndex ?? 0,
            'sheetName' => $extracted['sheet_name'] ?? null,
            'mismatchWarning' => null,
            'detectedBanners' => (bool) ($extracted['detected_banners'] ?? false),
            'headerOptions' => $extracted['header_options'] ?? [],
            'parsedRowCount' => (int) ($extracted['parsed_row_count'] ?? 0),
            'fieldLabels' => CurriculumSpreadsheetParser::canonicalFieldLabels(false),
            'columnMap' => $this->columnMap,
            'uploadError' => $this->uploadError,
            'mappingErrors' => $this->mappingErrors,
            'summary' => $preview['summary'],
            'rows' => $preview['rows'],
            'placementGroups' => [],
            'reviewTab' => $this->reviewTab,
            'trackedFixRowKeys' => $this->trackedFixRowKeys,
            'yearOptions' => [],
            'semesterOptions' => [
                Term::TypeFirstSemester => '1st Semester',
                Term::TypeSecondSemester => '2nd Semester',
                Term::TypeSpecialTerm => 'Summer',
            ],
            'returnUrl' => $this->returnUrl(),
            'returnLabel' => 'Back to subjects',
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

        $rawState = $this->uploadedFile ?? $this->file;
        $inspected = CurriculumSpreadsheetWizard::inspectUploadedFile($rawState, null);

        if ($inspected === null) {
            $this->uploadError = 'The uploaded file could not be read as a valid Excel (.xlsx) or CSV (.csv) spreadsheet.';

            return;
        }

        $this->sheetIndex = $inspected['suggested_sheet_index'];
        $extracted = $this->currentExtractedSheet();

        if ($extracted === null) {
            $this->uploadError = 'The selected worksheet could not be read.';

            return;
        }

        $this->columnMap = $this->stringifyColumnMap($extracted['column_map']);
        $this->syncRowOverridesFromExtracted($extracted);

        if ($extracted['parsed_row_count'] === 0) {
            $this->uploadError = 'No subject rows were found in this worksheet. Check that you selected the right tab or download our template.';
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentExtractedSheet(): ?array
    {
        $rawState = $this->uploadedFile ?? $this->file;

        if ($rawState === null) {
            return null;
        }

        return CurriculumSpreadsheetWizard::extractAndParseSheet(
            rawState: $rawState,
            sheetIndex: $this->sheetIndex,
            columnMap: $this->columnMap,
            includePlacement: false,
            program: null,
        );
    }

    /**
     * @param  array<string, mixed>  $extracted
     */
    private function syncRowOverridesFromExtracted(array $extracted): void
    {
        if (($extracted['parsed_rows'] ?? []) === []) {
            $this->rowOverrides = [];
            $this->trackedFixRowKeys = [];

            return;
        }

        $basePreview = CurriculumSpreadsheetWizard::buildFullPagePreviewData(
            parsedRows: $extracted['parsed_rows'],
            includePlacement: false,
            curriculum: null,
            rowOverrides: [],
            importMode: 'add_only',
        );

        $newOverrides = [];
        $trackedKeys = $this->trackedFixRowKeys;

        foreach ($basePreview['rows'] as $row) {
            $rowKey = (string) $row['row_key'];
            $isBlocked = ($row['status'] ?? '') === CurriculumSpreadsheetImportService::StatusBlocked;
            $prior = $this->rowOverrides[$rowKey] ?? null;

            if (! $isBlocked && $prior === null && ! in_array($rowKey, $trackedKeys, true)) {
                continue;
            }

            if ($isBlocked && ! in_array($rowKey, $trackedKeys, true)) {
                $trackedKeys[] = $rowKey;
            }

            $newOverrides[$rowKey] = [
                'row_key' => $rowKey,
                'source_row' => (int) $row['source_row'],
                'code' => is_array($prior) && array_key_exists('code', $prior) && $prior['code'] !== null
                    ? (string) $prior['code']
                    : (string) $row['code'],
                'title' => is_array($prior) && array_key_exists('title', $prior) && $prior['title'] !== null
                    ? (string) $prior['title']
                    : (string) $row['title'],
                'credit_units' => is_array($prior) && array_key_exists('credit_units', $prior) && $prior['credit_units'] !== null && $prior['credit_units'] !== ''
                    ? $prior['credit_units']
                    : $row['credit_units'],
                'skip' => is_array($prior) ? (bool) ($prior['skip'] ?? false) : false,
            ];
        }

        $this->rowOverrides = $newOverrides;
        $this->trackedFixRowKeys = array_values(array_unique($trackedKeys));
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
}
