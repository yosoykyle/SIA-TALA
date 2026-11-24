<?php

namespace App\Support;

use App\Actions\AcademicSetup\CurriculumSpreadsheetExportService;
use App\Actions\AcademicSetup\CurriculumSpreadsheetImportService;
use App\Actions\AcademicSetup\CurriculumSpreadsheetParser;
use App\Models\Course;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class CurriculumSpreadsheetWizard
{
    /**
     * @var array<string, array{sheets: list<array{index: int, name: string, row_count: int}>, default_sheet_index: int}>
     */
    private static array $workbookCache = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private static array $sheetCache = [];

    /**
     * @var array<string, list<array<string, mixed>>>
     */
    private static array $parsedRowsCache = [];

    /**
     * Build the 3-step Wizard Action for importing subjects into the Subject Catalog (`ListCourses`).
     */
    public static function makeCatalogImportAction(
        ?Closure $onSkippedCsvGenerated = null,
        ?Closure $onCompleted = null,
    ): Action {
        return self::buildWizardAction(
            actionName: 'importSubjects',
            label: 'Import subjects',
            modalHeading: 'Import subjects from spreadsheet',
            modalDescription: 'Upload an Excel (.xlsx) or CSV (.csv) file, confirm column mappings, and preview new and existing subjects before importing.',
            submitLabel: 'Import subjects',
            includePlacement: false,
            curriculumResolver: null,
            onSkippedCsvGenerated: $onSkippedCsvGenerated,
            onCompleted: $onCompleted,
        )
            ->authorize(fn (): bool => auth()->user()?->can('create', Course::class) ?? false)
            ->visible(fn (): bool => auth()->user()?->can('create', Course::class) ?? false);
    }

    /**
     * Build the 3-step Wizard Action for importing subjects & placements into an editable Draft curriculum (`ReviewCurriculumVersion`).
     *
     * @param  Closure(): CurriculumVersion  $curriculumResolver
     */
    public static function makeCurriculumImportAction(
        Closure $curriculumResolver,
        ?Closure $onSkippedCsvGenerated = null,
        ?Closure $onCompleted = null,
    ): Action {
        return self::buildWizardAction(
            actionName: 'importSpreadsheet',
            label: 'Import from spreadsheet',
            modalHeading: 'Import subjects into draft curriculum',
            modalDescription: 'Upload a curriculum workbook (.xlsx) or spreadsheet (.csv), match columns, and preview year and semester placements before importing into this draft.',
            submitLabel: 'Import into draft curriculum',
            includePlacement: true,
            curriculumResolver: $curriculumResolver,
            onSkippedCsvGenerated: $onSkippedCsvGenerated,
            onCompleted: $onCompleted,
        )
            ->authorize(function () use ($curriculumResolver): bool {
                $user = auth()->user();
                $curriculum = $curriculumResolver();

                return $user instanceof User
                    && $curriculum instanceof CurriculumVersion
                    && $curriculum->isEditableDraft()
                    && $user->can('update', $curriculum)
                    && $user->can('create', Course::class);
            })
            ->visible(function () use ($curriculumResolver): bool {
                $user = auth()->user();
                $curriculum = $curriculumResolver();

                return $user instanceof User
                    && $curriculum instanceof CurriculumVersion
                    && $curriculum->isEditableDraft()
                    && $user->can('update', $curriculum)
                    && $user->can('create', Course::class);
            });
    }

    /**
     * Build a standalone template download Action (`downloadXlsxTemplate` or `downloadCsvTemplate`).
     */
    public static function makeTemplateDownloadAction(
        string $name,
        string $scope,
        string $format,
        string $label,
    ): Action {
        $isCsv = strtolower($format) === 'csv';

        return Action::make($name)
            ->label($label)
            ->icon($isCsv ? Heroicon::OutlinedDocumentText : Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->size('sm')
            ->outlined()
            ->action(function () use ($scope, $format): BinaryFileResponse {
                $exportService = app(CurriculumSpreadsheetExportService::class);
                $path = $exportService->exportTemplate($scope, $format);

                return $exportService->downloadAndDelete($path, basename($path), $format);
            });
    }

    /**
     * Build the core 3-step Wizard Action shared by Subject Catalog and Draft Curriculum imports.
     *
     * @param  (Closure(): CurriculumVersion)|null  $curriculumResolver
     */
    private static function buildWizardAction(
        string $actionName,
        string $label,
        string $modalHeading,
        string $modalDescription,
        string $submitLabel,
        bool $includePlacement,
        ?Closure $curriculumResolver,
        ?Closure $onSkippedCsvGenerated,
        ?Closure $onCompleted,
    ): Action {
        $templateScope = $includePlacement ? 'curriculum' : 'subjects';

        return Action::make($actionName)
            ->label($label)
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('gray')
            ->modalHeading($modalHeading)
            ->modalDescription($modalDescription)
            ->modalSubmitActionLabel($submitLabel)
            ->modalWidth(Width::FiveExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->registerModalActions([
                self::makeTemplateDownloadAction('downloadModalXlsxTemplate', $templateScope, 'xlsx', 'Download XLSX template'),
                self::makeTemplateDownloadAction('downloadModalCsvTemplate', $templateScope, 'csv', 'Download CSV template'),
            ])
            ->steps([
                self::buildUploadStep($includePlacement, $templateScope, $curriculumResolver),
                self::buildMappingStep($includePlacement, $curriculumResolver),
                self::buildPreviewStep($includePlacement, $curriculumResolver),
            ])
            ->action(function (array $data) use (
                $includePlacement,
                $curriculumResolver,
                $onSkippedCsvGenerated,
                $onCompleted,
            ): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                Gate::forUser($actor)->authorize('create', Course::class);

                $curriculum = $curriculumResolver !== null ? $curriculumResolver() : null;

                if ($includePlacement) {
                    abort_unless($curriculum instanceof CurriculumVersion, 404);
                    Gate::forUser($actor)->authorize('update', $curriculum);

                    if (! $curriculum->isEditableDraft()) {
                        abort(403, 'Only an editable draft curriculum can accept spreadsheet imports.');
                    }
                }

                $resolvedFile = self::resolveUploadedFile($data['file'] ?? $data['spreadsheet_file'] ?? null);

                if ($resolvedFile === null) {
                    throw ValidationException::withMessages([
                        'file' => 'Upload a valid .xlsx or .csv spreadsheet file.',
                    ]);
                }

                $workbook = self::inspectWorkbookCached($resolvedFile['path'], $resolvedFile['extension']);
                $sheetIndex = isset($data['sheet_index']) && $data['sheet_index'] !== '' && $data['sheet_index'] !== null
                    ? (int) $data['sheet_index']
                    : (int) $workbook['default_sheet_index'];

                $extracted = self::extractSheetCached($resolvedFile['path'], $sheetIndex, $resolvedFile['extension']);
                $submittedMap = is_array($data['column_map'] ?? null) ? $data['column_map'] : [];
                $columnMap = self::mergeColumnMapWithSuggested($submittedMap, $extracted['suggested_mapping'], $includePlacement);

                self::validateRequiredColumnMappings(
                    columnMap: $columnMap,
                    includePlacement: $includePlacement,
                    detectedBanners: (bool) $extracted['detected_banners'],
                );

                $parsedRows = self::parseRowsCached(
                    path: $resolvedFile['path'],
                    sheetIndex: $sheetIndex,
                    columnMap: $columnMap,
                    extension: $resolvedFile['extension'],
                );

                $importService = app(CurriculumSpreadsheetImportService::class);
                $rowOverrides = is_array($data['row_overrides'] ?? null) ? $data['row_overrides'] : [];
                $preview = $importService->buildPreview($parsedRows, $curriculum, $rowOverrides);
                $skipBlocked = array_key_exists('skip_blocked', $data) ? (bool) $data['skip_blocked'] : true;

                if (! $skipBlocked && $preview['summary']['blocked_count'] > 0) {
                    Notification::make()
                        ->title('Rows still need attention')
                        ->body('Fix the highlighted rows or enable "Skip remaining rows with issues and import valid rows" before importing.')
                        ->danger()
                        ->send();

                    throw ValidationException::withMessages([
                        'skip_blocked' => 'Fix the rows with issues or enable skipping remaining rows with issues.',
                    ]);
                }

                try {
                    $result = $importService->commitImport(
                        actor: $actor,
                        previewRows: $preview['rows'],
                        curriculumVersion: $curriculum,
                        skipBlocked: $skipBlocked,
                    );
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Import could not be completed')
                        ->body(collect($exception->errors())->flatten()->implode(' '))
                        ->danger()
                        ->send();

                    throw $exception;
                }

                $skippedRows = $result['skipped_rows'];
                $skippedCsvPath = null;

                if ($skippedRows !== []) {
                    $skippedCsvPath = $importService->exportSkippedRowsCsv($skippedRows);

                    if ($onSkippedCsvGenerated !== null) {
                        $onSkippedCsvGenerated($skippedCsvPath, $skippedRows);
                    }
                }

                if ($onCompleted !== null) {
                    $onCompleted($result, $skippedCsvPath);
                }

                self::sendImportSummaryNotification($result, $includePlacement, $skippedCsvPath);
            });
    }

    /**
     * Step 1 — Upload file, download templates, and choose worksheet (for multi-sheet `.xlsx` workbooks).
     *
     * @param  (Closure(): CurriculumVersion)|null  $curriculumResolver
     */
    private static function buildUploadStep(
        bool $includePlacement,
        string $templateScope,
        ?Closure $curriculumResolver,
    ): Step {
        return Step::make('Upload file')
            ->description($includePlacement
                ? 'Choose an Excel (.xlsx) or CSV (.csv) curriculum spreadsheet.'
                : 'Choose an Excel (.xlsx) or CSV (.csv) subjects spreadsheet.')
            ->schema([
                Section::make('Spreadsheet file & templates')
                    ->compact()
                    ->description($includePlacement
                        ? 'Supports Servitech multi-sheet curriculum workbooks (with Year and Semester section banners) as well as standard flat .xlsx and .csv templates.'
                        : 'Upload a flat .xlsx or .csv list of subjects, or download a sample template to get started.')
                    ->schema([
                        SchemaActions::make([
                            self::makeTemplateDownloadAction(
                                name: 'downloadXlsxTemplate',
                                scope: $templateScope,
                                format: 'xlsx',
                                label: 'Download XLSX template',
                            ),
                            self::makeTemplateDownloadAction(
                                name: 'downloadCsvTemplate',
                                scope: $templateScope,
                                format: 'csv',
                                label: 'Download CSV template',
                            ),
                        ])->extraAttributes(['class' => 'tala-import-template-actions']),
                        FileUpload::make('file')
                            ->label('Spreadsheet file (.xlsx or .csv)')
                            ->storeFiles(false)
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                                'application/zip',
                                'application/octet-stream',
                                'text/csv',
                                'text/plain',
                                'application/csv',
                                'text/comma-separated-values',
                                'text/x-comma-separated-values',
                            ])
                            ->maxSize(10240)
                            ->required()
                            ->live()
                            ->helperText('Excel (.xlsx) or CSV (.csv) up to 10 MB.')
                            ->rules([
                                fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                    $resolved = self::resolveUploadedFile($value);

                                    if ($resolved === null) {
                                        $fail('Upload a valid .xlsx or .csv spreadsheet file.');

                                        return;
                                    }

                                    try {
                                        $workbook = self::inspectWorkbookCached($resolved['path'], $resolved['extension']);

                                        if (($workbook['sheets'] ?? []) === []) {
                                            $fail('The uploaded spreadsheet does not contain any readable worksheets.');
                                        }
                                    } catch (\Throwable) {
                                        $fail('The uploaded file could not be read as a valid .xlsx or .csv spreadsheet.');
                                    }
                                },
                            ])
                            ->afterStateUpdated(function (mixed $state, Get $get, Set $set) use ($includePlacement, $curriculumResolver): void {
                                self::initializeStateFromFile($state, null, $get, $set, $includePlacement, $curriculumResolver);
                            }),
                        Select::make('sheet_index')
                            ->label('Worksheet')
                            ->helperText('Multiple worksheets were found in this workbook. Choose which worksheet to import.')
                            ->options(fn (Get $get): array => self::sheetOptionsFromState($get))
                            ->visible(fn (Get $get): bool => count(self::sheetOptionsFromState($get)) > 1)
                            ->required(fn (Get $get): bool => count(self::sheetOptionsFromState($get)) > 1)
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Get $get, Set $set) use ($includePlacement, $curriculumResolver): void {
                                $sheetIndex = $state !== null && $state !== '' ? (int) $state : 0;
                                self::initializeStateFromFile($get('file'), $sheetIndex, $get, $set, $includePlacement, $curriculumResolver);
                            }),
                        Hidden::make('detected_banners')->default(false),
                    ]),
            ])
            ->afterValidation(function (Get $get, Set $set) use ($includePlacement, $curriculumResolver): void {
                $currentMap = $get('column_map');
                $sheetIndex = $get('sheet_index');
                $resolvedSheetIndex = $sheetIndex !== null && $sheetIndex !== '' ? (int) $sheetIndex : null;

                if (! is_array($currentMap) || $currentMap === []) {
                    self::initializeStateFromFile($get('file'), $resolvedSheetIndex, $get, $set, $includePlacement, $curriculumResolver);
                } else {
                    self::syncBlockedRowOverrides($get, $set, $includePlacement, $curriculumResolver);
                }
            });
    }

    /**
     * Step 2 — Match columns from the uploaded worksheet to canonical TALA subject fields.
     *
     * @param  (Closure(): CurriculumVersion)|null  $curriculumResolver
     */
    private static function buildMappingStep(
        bool $includePlacement,
        ?Closure $curriculumResolver,
    ): Step {
        $fieldLabels = CurriculumSpreadsheetParser::canonicalFieldLabels($includePlacement);
        $selectComponents = [];

        foreach ($fieldLabels as $fieldKey => $fieldLabel) {
            $isAlwaysRequired = in_array($fieldKey, [
                CurriculumSpreadsheetParser::FieldCode,
                CurriculumSpreadsheetParser::FieldTitle,
                CurriculumSpreadsheetParser::FieldCreditUnits,
            ], true);

            $isPlacementField = in_array($fieldKey, [
                CurriculumSpreadsheetParser::FieldYearLevel,
                CurriculumSpreadsheetParser::FieldTermType,
            ], true);

            $selectComponents[] = Select::make("column_map.{$fieldKey}")
                ->label(function (Get $get) use ($fieldLabel, $isAlwaysRequired, $isPlacementField): string {
                    if ($isAlwaysRequired) {
                        return "{$fieldLabel} *";
                    }

                    if ($isPlacementField && ! self::detectedBannersFromState($get)) {
                        return "{$fieldLabel} *";
                    }

                    return $fieldLabel;
                })
                ->options(fn (Get $get): array => self::columnMappingOptionsFromState($get))
                ->default('ignore')
                ->selectablePlaceholder(false)
                ->helperText(function (Get $get) use ($fieldKey, $isPlacementField): ?string {
                    if ($isPlacementField && self::detectedBannersFromState($get)) {
                        return 'Optional — Year and Semester section banners were auto-detected in this worksheet.';
                    }

                    return match ($fieldKey) {
                        CurriculumSpreadsheetParser::FieldCode => 'Required. Matches existing catalog codes or creates a new subject.',
                        CurriculumSpreadsheetParser::FieldTitle => 'Required. Descriptive title of the subject.',
                        CurriculumSpreadsheetParser::FieldCreditUnits => 'Required. Credit units (e.g. 3).',
                        CurriculumSpreadsheetParser::FieldLecHours => 'Weekly lecture contact hours (e.g. 2 or 3).',
                        CurriculumSpreadsheetParser::FieldLabHours => 'Weekly laboratory hours (or practicum total like 300 hours).',
                        CurriculumSpreadsheetParser::FieldPrerequisites => 'Comma-separated prerequisite subject codes.',
                        CurriculumSpreadsheetParser::FieldAcademicClassification => 'Regular, TESDA Competency / Resultant NC, NSTP, PE, or Non-credit.',
                        CurriculumSpreadsheetParser::FieldSchedulingTreatment => 'Weekly scheduled class or Externally arranged / Practicum.',
                        default => null,
                    };
                })
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set) use ($includePlacement, $curriculumResolver): void {
                    self::syncBlockedRowOverrides($get, $set, $includePlacement, $curriculumResolver);
                })
                ->rules([
                    fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use (
                        $get,
                        $fieldLabel,
                        $isAlwaysRequired,
                        $isPlacementField,
                    ): void {
                        $requiresMapping = $isAlwaysRequired
                            || ($isPlacementField && ! self::detectedBannersFromState($get));

                        if ($requiresMapping && ($value === null || $value === '' || $value === 'ignore')) {
                            $fail("Select the spreadsheet column for {$fieldLabel}.");
                        }
                    },
                ]);
        }

        return Step::make('Match columns')
            ->description('Verify how your spreadsheet columns map to TALA subject fields. Sample values from your file are shown beside each column header.')
            ->schema([
                Section::make('Column mapping')
                    ->compact()
                    ->description(function (Get $get) use ($includePlacement): string {
                        if ($includePlacement && self::detectedBannersFromState($get)) {
                            return 'Section banners (such as "First Year - First Semester" or "2nd Year - Summer Term") were automatically detected in this worksheet, so Year Level and Semester column mappings are optional.';
                        }

                        return 'Columns were auto-matched from your file headers. Unmapped optional fields default to Ignore.';
                    })
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2])
                            ->schema($selectComponents),
                    ]),
            ])
            ->beforeValidation(function (Get $get, Set $set) use ($includePlacement, $curriculumResolver): void {
                $currentMap = $get('column_map');

                if (! is_array($currentMap) || $currentMap === []) {
                    $sheetIndex = $get('sheet_index');
                    $resolvedSheetIndex = $sheetIndex !== null && $sheetIndex !== '' ? (int) $sheetIndex : null;
                    self::initializeStateFromFile($get('file'), $resolvedSheetIndex, $get, $set, $includePlacement, $curriculumResolver);
                }
            })
            ->afterValidation(function (Get $get, Set $set) use ($includePlacement, $curriculumResolver): void {
                self::syncBlockedRowOverrides($get, $set, $includePlacement, $curriculumResolver);
            });
    }

    /**
     * Step 3 — Preview summary counts, placement breakdown, row table, and inline fixes for blocked rows.
     *
     * @param  (Closure(): CurriculumVersion)|null  $curriculumResolver
     */
    private static function buildPreviewStep(
        bool $includePlacement,
        ?Closure $curriculumResolver,
    ): Step {
        $yearOptions = [];
        for ($y = 1; $y <= 8; $y++) {
            $yearOptions[$y] = "Year {$y}";
        }

        $semesterOptions = [
            Term::TypeFirstSemester => '1st Semester',
            Term::TypeSecondSemester => '2nd Semester',
            Term::TypeSpecialTerm => 'Summer',
        ];

        $repeaterFields = [
            Hidden::make('row_key'),
            Hidden::make('source_row'),
            TextInput::make('code')
                ->label('Subject code')
                ->placeholder('e.g. GE 1')
                ->maxLength(64)
                ->live(onBlur: true),
            TextInput::make('title')
                ->label('Subject name')
                ->placeholder('Descriptive title')
                ->maxLength(255)
                ->live(onBlur: true),
            TextInput::make('credit_units')
                ->label('Units')
                ->numeric()
                ->step(0.25)
                ->minValue(0)
                ->maxValue(30)
                ->placeholder('e.g. 3')
                ->live(onBlur: true),
        ];

        if ($includePlacement) {
            $repeaterFields[] = Select::make('year_level')
                ->label('Year level')
                ->options($yearOptions)
                ->placeholder('Select year')
                ->live();
            $repeaterFields[] = Select::make('term_type')
                ->label('Semester')
                ->options($semesterOptions)
                ->placeholder('Select semester')
                ->live();
        }

        $repeaterFields[] = Toggle::make('skip')
            ->label('Skip this row')
            ->inline(false)
            ->live();

        return Step::make('Preview & import')
            ->description($includePlacement
                ? 'Review parsed subjects, year and semester placements, warnings, and any rows needing attention before importing into this draft curriculum.'
                : 'Review parsed subjects, catalog matches, warnings, and any rows needing attention before importing.')
            ->schema([
                SchemaView::make('filament.resources.curriculum-versions.partials.spreadsheet-import-preview')
                    ->viewData(fn (Get $get): array => self::buildPreviewViewData($get, $includePlacement, $curriculumResolver)),
                Section::make('Resolve rows needing attention')
                    ->compact()
                    ->description('Fix missing subject codes, units, or placements directly below, or leave the skip toggle enabled to import all valid rows now.')
                    ->visible(fn (Get $get): bool => count((array) ($get('row_overrides') ?? [])) > 0)
                    ->schema([
                        Toggle::make('skip_blocked')
                            ->label('Skip remaining rows with issues and import valid rows')
                            ->helperText('When enabled, any row that still has an unresolved issue is safely skipped and included in a downloadable CSV summary.')
                            ->default(true)
                            ->live(),
                        Repeater::make('row_overrides')
                            ->label('Rows with issues')
                            ->extraAttributes(['class' => 'tala-record-repeater tala-import-fixes-repeater'])
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columns(['default' => 1, 'sm' => 2, 'md' => $includePlacement ? 6 : 4])
                            ->itemLabel(function (array $state): string {
                                $rowNum = (int) ($state['source_row'] ?? 0);
                                $code = trim((string) ($state['code'] ?? ''));
                                $title = trim((string) ($state['title'] ?? ''));
                                $label = $rowNum > 0 ? "Row #{$rowNum}" : 'Row';

                                if ($code !== '' || $title !== '') {
                                    $label .= ' · '.collect([$code, $title])->filter()->implode(' — ');
                                }

                                if (! empty($state['skip'])) {
                                    $label .= ' (Skipped)';
                                }

                                return $label;
                            })
                            ->schema($repeaterFields),
                    ]),
            ]);
    }

    /**
     * Resolve an uploaded file from Livewire `TemporaryUploadedFile`, `UploadedFile`, array state, or local path.
     *
     * @return array{path: string, extension: string}|null
     */
    public static function resolveUploadedFile(mixed $rawState): ?array
    {
        $candidate = $rawState;

        while (is_array($candidate)) {
            if ($candidate === []) {
                return null;
            }

            $candidate = Arr::first($candidate);
        }

        if ($candidate instanceof TemporaryUploadedFile || $candidate instanceof UploadedFile) {
            $realPath = $candidate->getRealPath() ?: $candidate->path();

            if (! is_string($realPath) || $realPath === '' || ! is_file($realPath)) {
                return null;
            }

            $ext = strtolower(
                $candidate->getClientOriginalExtension()
                ?: pathinfo($candidate->getClientOriginalName(), PATHINFO_EXTENSION)
                ?: pathinfo($realPath, PATHINFO_EXTENSION)
            );

            return [
                'path' => $realPath,
                'extension' => self::normalizeSpreadsheetExtension($realPath, $ext),
            ];
        }

        if (is_string($candidate) && trim($candidate) !== '') {
            $trimmed = trim($candidate);

            if (is_file($trimmed)) {
                $ext = strtolower(pathinfo($trimmed, PATHINFO_EXTENSION));

                return [
                    'path' => $trimmed,
                    'extension' => self::normalizeSpreadsheetExtension($trimmed, $ext),
                ];
            }

            foreach (array_unique([
                (string) config('livewire.temporary_file_upload.disk', 'local'),
                (string) config('filament.default_filesystem_disk', 'local'),
                'local',
            ]) as $disk) {
                if ($disk === '') {
                    continue;
                }

                try {
                    if (Storage::disk($disk)->exists($trimmed)) {
                        $diskPath = Storage::disk($disk)->path($trimmed);

                        if (is_file($diskPath)) {
                            $ext = strtolower(pathinfo($diskPath, PATHINFO_EXTENSION));

                            return [
                                'path' => $diskPath,
                                'extension' => self::normalizeSpreadsheetExtension($diskPath, $ext),
                            ];
                        }
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * Determine whether the file is `xlsx` or `csv`, falling back to ZIP magic bytes if the temporary file has a `.tmp` extension.
     */
    private static function normalizeSpreadsheetExtension(string $path, string $ext): string
    {
        $clean = strtolower(ltrim(trim($ext), '.'));

        if (in_array($clean, ['xlsx', 'csv'], true)) {
            return $clean;
        }

        $magic = @file_get_contents($path, false, null, 0, 4);

        if (is_string($magic) && str_starts_with($magic, "PK\x03\x04")) {
            return 'xlsx';
        }

        return 'csv';
    }

    /**
     * Populate `sheet_index`, `detected_banners`, `column_map`, and initial `row_overrides` when a file or worksheet is selected.
     *
     * @param  (Closure(): CurriculumVersion)|null  $curriculumResolver
     */
    private static function initializeStateFromFile(
        mixed $fileState,
        ?int $explicitSheetIndex,
        Get $get,
        Set $set,
        bool $includePlacement,
        ?Closure $curriculumResolver,
    ): void {
        $resolved = self::resolveUploadedFile($fileState);

        if ($resolved === null) {
            return;
        }

        try {
            $workbook = self::inspectWorkbookCached($resolved['path'], $resolved['extension']);
            $sheetIndex = $explicitSheetIndex ?? (int) $workbook['default_sheet_index'];
            $extracted = self::extractSheetCached($resolved['path'], $sheetIndex, $resolved['extension']);
        } catch (\Throwable) {
            return;
        }

        $set('sheet_index', $sheetIndex);
        $set('detected_banners', (bool) $extracted['detected_banners']);

        $fieldLabels = CurriculumSpreadsheetParser::canonicalFieldLabels($includePlacement);
        $suggested = $extracted['suggested_mapping'];

        foreach (array_keys($fieldLabels) as $fieldKey) {
            $mappedIndex = $suggested[$fieldKey] ?? null;
            $set("column_map.{$fieldKey}", $mappedIndex !== null ? (string) $mappedIndex : 'ignore');
        }

        self::syncBlockedRowOverrides($get, $set, $includePlacement, $curriculumResolver);
    }

    /**
     * Synchronize the Step 3 `row_overrides` Repeater state for any rows that are initially blocked,
     * preserving any user edits already entered for matching `row_key`s.
     *
     * @param  (Closure(): CurriculumVersion)|null  $curriculumResolver
     */
    private static function syncBlockedRowOverrides(
        Get $get,
        Set $set,
        bool $includePlacement,
        ?Closure $curriculumResolver,
    ): void {
        $resolved = self::resolveUploadedFile($get('file'));

        if ($resolved === null) {
            return;
        }

        try {
            $workbook = self::inspectWorkbookCached($resolved['path'], $resolved['extension']);
            $rawSheetIndex = $get('sheet_index');
            $sheetIndex = $rawSheetIndex !== null && $rawSheetIndex !== ''
                ? (int) $rawSheetIndex
                : (int) $workbook['default_sheet_index'];

            $extracted = self::extractSheetCached($resolved['path'], $sheetIndex, $resolved['extension']);
            $currentMap = is_array($get('column_map')) ? $get('column_map') : [];
            $columnMap = self::mergeColumnMapWithSuggested($currentMap, $extracted['suggested_mapping'], $includePlacement);

            $parsedRows = self::parseRowsCached(
                path: $resolved['path'],
                sheetIndex: $sheetIndex,
                columnMap: $columnMap,
                extension: $resolved['extension'],
            );

            $curriculum = $curriculumResolver !== null ? $curriculumResolver() : null;
            $basePreview = app(CurriculumSpreadsheetImportService::class)->buildPreview($parsedRows, $curriculum, []);
        } catch (\Throwable) {
            return;
        }

        $existingOverrides = is_array($get('row_overrides')) ? $get('row_overrides') : [];
        $existingByRowKey = [];

        foreach ($existingOverrides as $key => $item) {
            if (! is_array($item)) {
                continue;
            }

            $rowKey = isset($item['row_key']) && is_string($item['row_key']) && $item['row_key'] !== ''
                ? $item['row_key']
                : (is_string($key) && str_starts_with($key, 'row_') ? $key : null);

            if ($rowKey !== null) {
                $existingByRowKey[$rowKey] = $item;
            }
        }

        $newOverrides = [];

        foreach ($basePreview['rows'] as $row) {
            if ($row['status'] !== CurriculumSpreadsheetImportService::StatusBlocked) {
                continue;
            }

            $rowKey = (string) $row['row_key'];
            $prior = $existingByRowKey[$rowKey] ?? [];

            $newOverrides[$rowKey] = [
                'row_key' => $rowKey,
                'source_row' => (int) $row['source_row'],
                'code' => array_key_exists('code', $prior) && $prior['code'] !== null
                    ? (string) $prior['code']
                    : (string) $row['code'],
                'title' => array_key_exists('title', $prior) && $prior['title'] !== null
                    ? (string) $prior['title']
                    : (string) $row['title'],
                'credit_units' => array_key_exists('credit_units', $prior) && $prior['credit_units'] !== null && $prior['credit_units'] !== ''
                    ? $prior['credit_units']
                    : $row['credit_units'],
                'year_level' => array_key_exists('year_level', $prior) && $prior['year_level'] !== null && $prior['year_level'] !== ''
                    ? (int) $prior['year_level']
                    : $row['year_level'],
                'term_type' => array_key_exists('term_type', $prior) && $prior['term_type'] !== null && $prior['term_type'] !== ''
                    ? (string) $prior['term_type']
                    : $row['term_type'],
                'skip' => (bool) ($prior['skip'] ?? false),
            ];
        }

        $set('row_overrides', $newOverrides);

        if ($get('skip_blocked') === null) {
            $set('skip_blocked', true);
        }
    }

    /**
     * Build view data for `spreadsheet-import-preview.blade.php`.
     *
     * @param  (Closure(): CurriculumVersion)|null  $curriculumResolver
     * @return array<string, mixed>
     */
    private static function buildPreviewViewData(
        Get $get,
        bool $includePlacement,
        ?Closure $curriculumResolver,
    ): array {
        $emptySummary = [
            'total' => 0,
            'ready_new' => 0,
            'ready_existing' => 0,
            'warnings_count' => 0,
            'blocked_count' => 0,
            'skipped_count' => 0,
        ];

        $resolved = self::resolveUploadedFile($get('file'));

        if ($resolved === null) {
            return [
                'isCurriculum' => $includePlacement,
                'sheetName' => null,
                'detectedBanners' => false,
                'summary' => $emptySummary,
                'rows' => [],
                'placementGroups' => [],
            ];
        }

        try {
            $workbook = self::inspectWorkbookCached($resolved['path'], $resolved['extension']);
            $rawSheetIndex = $get('sheet_index');
            $sheetIndex = $rawSheetIndex !== null && $rawSheetIndex !== ''
                ? (int) $rawSheetIndex
                : (int) $workbook['default_sheet_index'];

            $extracted = self::extractSheetCached($resolved['path'], $sheetIndex, $resolved['extension']);
            $currentMap = is_array($get('column_map')) ? $get('column_map') : [];
            $columnMap = self::mergeColumnMapWithSuggested($currentMap, $extracted['suggested_mapping'], $includePlacement);

            $parsedRows = self::parseRowsCached(
                path: $resolved['path'],
                sheetIndex: $sheetIndex,
                columnMap: $columnMap,
                extension: $resolved['extension'],
            );

            $curriculum = $curriculumResolver !== null ? $curriculumResolver() : null;
            $overrides = is_array($get('row_overrides')) ? $get('row_overrides') : [];
            $preview = app(CurriculumSpreadsheetImportService::class)->buildPreview($parsedRows, $curriculum, $overrides);
        } catch (\Throwable) {
            return [
                'isCurriculum' => $includePlacement,
                'sheetName' => null,
                'detectedBanners' => false,
                'summary' => $emptySummary,
                'rows' => [],
                'placementGroups' => [],
            ];
        }

        $placementGroups = [];

        if ($includePlacement) {
            $grouped = [];

            foreach ($preview['rows'] as $row) {
                if (! in_array($row['status'], [
                    CurriculumSpreadsheetImportService::StatusReadyNew,
                    CurriculumSpreadsheetImportService::StatusReadyExisting,
                ], true)) {
                    continue;
                }

                $year = (int) ($row['year_level'] ?? 0);
                $termType = (string) ($row['term_type'] ?? '');
                $termOrder = match ($termType) {
                    Term::TypeFirstSemester => 1,
                    Term::TypeSecondSemester => 2,
                    Term::TypeSpecialTerm => 3,
                    default => 9,
                };
                $sortKey = sprintf('%02d-%d', $year, $termOrder);

                if (! isset($grouped[$sortKey])) {
                    $grouped[$sortKey] = [
                        'label' => (string) $row['placement_label'],
                        'subjects_count' => 0,
                        'total_units' => 0.0,
                    ];
                }

                $grouped[$sortKey]['subjects_count']++;
                $grouped[$sortKey]['total_units'] += (float) ($row['credit_units'] ?? 0.0);
            }

            ksort($grouped);
            $placementGroups = array_values($grouped);
        }

        return [
            'isCurriculum' => $includePlacement,
            'sheetName' => $extracted['sheet_name'] ?? null,
            'detectedBanners' => (bool) ($extracted['detected_banners'] ?? false),
            'summary' => $preview['summary'],
            'rows' => $preview['rows'],
            'placementGroups' => $placementGroups,
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function sheetOptionsFromState(Get $get): array
    {
        $resolved = self::resolveUploadedFile($get('file'));

        if ($resolved === null) {
            return [];
        }

        try {
            $workbook = self::inspectWorkbookCached($resolved['path'], $resolved['extension']);
        } catch (\Throwable) {
            return [];
        }

        $options = [];

        foreach ($workbook['sheets'] as $sheet) {
            $count = (int) $sheet['row_count'];
            $options[(int) $sheet['index']] = "{$sheet['name']} ({$count} ".Str::plural('row', $count).')';
        }

        return $options;
    }

    /**
     * @return array<string|int, string>
     */
    private static function columnMappingOptionsFromState(Get $get): array
    {
        $options = [
            'ignore' => '— Ignore / Not in file —',
        ];

        $resolved = self::resolveUploadedFile($get('file'));

        if ($resolved === null) {
            return $options;
        }

        try {
            $workbook = self::inspectWorkbookCached($resolved['path'], $resolved['extension']);
            $rawSheetIndex = $get('sheet_index');
            $sheetIndex = $rawSheetIndex !== null && $rawSheetIndex !== ''
                ? (int) $rawSheetIndex
                : (int) $workbook['default_sheet_index'];

            $extracted = self::extractSheetCached($resolved['path'], $sheetIndex, $resolved['extension']);

            foreach ($extracted['header_options'] as $colIndex => $label) {
                $options[(string) $colIndex] = $label;
            }
        } catch (\Throwable) {
            return $options;
        }

        return $options;
    }

    private static function detectedBannersFromState(Get $get): bool
    {
        $stateFlag = $get('detected_banners');

        if (is_bool($stateFlag) && $stateFlag) {
            return true;
        }

        $resolved = self::resolveUploadedFile($get('file'));

        if ($resolved === null) {
            return false;
        }

        try {
            $workbook = self::inspectWorkbookCached($resolved['path'], $resolved['extension']);
            $rawSheetIndex = $get('sheet_index');
            $sheetIndex = $rawSheetIndex !== null && $rawSheetIndex !== ''
                ? (int) $rawSheetIndex
                : (int) $workbook['default_sheet_index'];

            $extracted = self::extractSheetCached($resolved['path'], $sheetIndex, $resolved['extension']);

            return (bool) ($extracted['detected_banners'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Merge user-selected column mappings with auto-suggested mappings for any uninitialized keys.
     *
     * @param  array<string, mixed>  $submittedMap
     * @param  array<string, int|null>  $suggestedMap
     * @return array<string, int|string|null>
     */
    private static function mergeColumnMapWithSuggested(
        array $submittedMap,
        array $suggestedMap,
        bool $includePlacement,
    ): array {
        $merged = [];
        $fields = array_keys(CurriculumSpreadsheetParser::canonicalFieldLabels($includePlacement));

        foreach ($fields as $field) {
            if (array_key_exists($field, $submittedMap) && $submittedMap[$field] !== null && $submittedMap[$field] !== '') {
                $merged[$field] = $submittedMap[$field];
            } else {
                $merged[$field] = $suggestedMap[$field] !== null ? (string) $suggestedMap[$field] : 'ignore';
            }
        }

        return $merged;
    }

    /**
     * Validate that required canonical fields have a non-ignored column mapping.
     *
     * @param  array<string, int|string|null>  $columnMap
     */
    private static function validateRequiredColumnMappings(
        array $columnMap,
        bool $includePlacement,
        bool $detectedBanners,
    ): void {
        $required = [
            CurriculumSpreadsheetParser::FieldCode => 'Subject Code',
            CurriculumSpreadsheetParser::FieldTitle => 'Subject Name',
            CurriculumSpreadsheetParser::FieldCreditUnits => 'Units',
        ];

        if ($includePlacement && ! $detectedBanners) {
            $required[CurriculumSpreadsheetParser::FieldYearLevel] = 'Year Level';
            $required[CurriculumSpreadsheetParser::FieldTermType] = 'Semester';
        }

        $errors = [];

        foreach ($required as $field => $label) {
            $val = $columnMap[$field] ?? null;

            if ($val === null || $val === '' || $val === 'ignore') {
                $errors["column_map.{$field}"] = "Select the spreadsheet column for {$label}.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Validate required column mappings for the full-page importer without throwing an exception.
     *
     * @param  array<string, int|string|null>  $columnMap
     * @return array<string, string>
     */
    public static function validateColumnMapErrors(
        array $columnMap,
        bool $includePlacement,
        bool $detectedBanners,
    ): array {
        $required = [
            CurriculumSpreadsheetParser::FieldCode => 'Subject Code',
            CurriculumSpreadsheetParser::FieldTitle => 'Subject Name',
            CurriculumSpreadsheetParser::FieldCreditUnits => 'Units',
        ];

        if ($includePlacement && ! $detectedBanners) {
            $required[CurriculumSpreadsheetParser::FieldYearLevel] = 'Year Level';
            $required[CurriculumSpreadsheetParser::FieldTermType] = 'Semester';
        }

        $errors = [];

        foreach ($required as $field => $label) {
            $val = $columnMap[$field] ?? null;

            if ($val === null || $val === '' || $val === 'ignore') {
                $errors[$field] = "Select the spreadsheet column for {$label}.";
            }
        }

        return $errors;
    }

    /**
     * Inspect an uploaded spreadsheet file and suggest the best worksheet for the target program.
     *
     * @return array{
     *     path: string,
     *     extension: string,
     *     filename: string,
     *     sheets: list<array{index: int, name: string, row_count: int}>,
     *     default_sheet_index: int,
     *     suggested_sheet_index: int,
     *     matched_program: bool,
     *     mismatch_warning: string|null
     * }|null
     */
    public static function inspectUploadedFile(mixed $rawState, ?Program $program = null): ?array
    {
        $resolved = self::resolveUploadedFile($rawState);

        if ($resolved === null) {
            return null;
        }

        try {
            $workbook = self::inspectWorkbookCached($resolved['path'], $resolved['extension']);
        } catch (\Throwable) {
            return null;
        }

        $sheets = $workbook['sheets'] ?? [];

        if ($sheets === []) {
            return null;
        }

        $defaultSheetIndex = (int) ($workbook['default_sheet_index'] ?? 0);
        $suggestedSheetIndex = $defaultSheetIndex;
        $matchedProgram = false;
        $mismatchWarning = null;

        $parser = app(CurriculumSpreadsheetParser::class);

        if ($program instanceof Program && method_exists($parser, 'suggestSheetForProgram')) {
            /** @var array{sheet_index: int, matched: bool, mismatch_warning: string|null} $suggestion */
            $suggestion = $parser->suggestSheetForProgram($sheets, $program);
            $suggestedSheetIndex = (int) ($suggestion['sheet_index'] ?? $defaultSheetIndex);
            $matchedProgram = (bool) ($suggestion['matched'] ?? false);
            $mismatchWarning = $suggestion['mismatch_warning'] ?? null;
        }

        return [
            'path' => $resolved['path'],
            'extension' => $resolved['extension'],
            'filename' => self::extractUploadedFilename($rawState, $resolved['path']),
            'sheets' => $sheets,
            'default_sheet_index' => $defaultSheetIndex,
            'suggested_sheet_index' => $suggestedSheetIndex,
            'matched_program' => $matchedProgram,
            'mismatch_warning' => $mismatchWarning,
        ];
    }

    /**
     * Extract header metadata and parse subject rows for a selected worksheet on the full-page importer.
     *
     * @param  array<string, int|string|null>  $columnMap
     * @return array{
     *     path: string,
     *     extension: string,
     *     filename: string,
     *     sheets: list<array{index: int, name: string, row_count: int}>,
     *     sheet_index: int,
     *     sheet_name: string,
     *     detected_banners: bool,
     *     headers: list<array<string, mixed>>,
     *     header_options: array<int, string>,
     *     suggested_mapping: array<string, int|null>,
     *     column_map: array<string, int|string|null>,
     *     parsed_rows: list<array<string, mixed>>,
     *     parsed_row_count: int,
     *     mismatch_warning: string|null
     * }|null
     */
    public static function extractAndParseSheet(
        mixed $rawState,
        ?int $sheetIndex,
        array $columnMap,
        bool $includePlacement,
        ?Program $program = null,
    ): ?array {
        $inspected = self::inspectUploadedFile($rawState, $program);

        if ($inspected === null) {
            return null;
        }

        $resolvedSheetIndex = $sheetIndex ?? $inspected['suggested_sheet_index'];

        try {
            $extracted = self::extractSheetCached($inspected['path'], $resolvedSheetIndex, $inspected['extension']);
            $mergedMap = self::mergeColumnMapWithSuggested(
                submittedMap: $columnMap,
                suggestedMap: $extracted['suggested_mapping'] ?? [],
                includePlacement: $includePlacement,
            );
            $parsedRows = self::parseRowsCached(
                path: $inspected['path'],
                sheetIndex: $resolvedSheetIndex,
                columnMap: $mergedMap,
                extension: $inspected['extension'],
            );
        } catch (\Throwable) {
            return null;
        }

        $sheetName = (string) ($extracted['sheet_name'] ?? ('Sheet '.($resolvedSheetIndex + 1)));
        $mismatchWarning = null;
        $parser = app(CurriculumSpreadsheetParser::class);

        if ($program instanceof Program && method_exists($parser, 'sheetMismatchWarning')) {
            $mismatchWarning = $parser->sheetMismatchWarning($sheetName, $program);
        }

        return [
            'path' => $inspected['path'],
            'extension' => $inspected['extension'],
            'filename' => $inspected['filename'],
            'sheets' => $inspected['sheets'],
            'sheet_index' => $resolvedSheetIndex,
            'sheet_name' => $sheetName,
            'detected_banners' => (bool) ($extracted['detected_banners'] ?? false),
            'headers' => $extracted['headers'] ?? [],
            'header_options' => $extracted['header_options'] ?? [],
            'suggested_mapping' => $extracted['suggested_mapping'] ?? [],
            'column_map' => $mergedMap,
            'parsed_rows' => $parsedRows,
            'parsed_row_count' => count($parsedRows),
            'mismatch_warning' => $mismatchWarning,
        ];
    }

    /**
     * Build preview summary, rows, and term placement groups for the full-page importer.
     *
     * @param  list<array<string, mixed>>  $parsedRows
     * @param  array<string|int, array<string, mixed>>  $rowOverrides
     * @return array{
     *     summary: array{
     *         total: int,
     *         ready_new: int,
     *         ready_existing: int,
     *         already_in_draft_count: int,
     *         existing_draft_entry_count: int,
     *         warnings_count: int,
     *         blocked_count: int,
     *         skipped_count: int
     *     },
     *     rows: list<array<string, mixed>>,
     *     placementGroups: list<array{label: string, subjects_count: int, total_units: float}>
     * }
     */
    public static function buildFullPagePreviewData(
        array $parsedRows,
        bool $includePlacement,
        ?CurriculumVersion $curriculum,
        array $rowOverrides,
        string $importMode = 'add_only',
    ): array {
        $importService = app(CurriculumSpreadsheetImportService::class);
        $preview = $importService->buildPreview($parsedRows, $curriculum, $rowOverrides, $importMode);

        $rawSummary = $preview['summary'] ?? [];
        $summary = [
            'total' => (int) ($rawSummary['total'] ?? count($preview['rows'] ?? [])),
            'ready_new' => (int) ($rawSummary['ready_new'] ?? 0),
            'ready_existing' => (int) ($rawSummary['ready_existing'] ?? 0),
            'already_in_draft_count' => (int) ($rawSummary['already_in_draft_count'] ?? 0),
            'existing_draft_entry_count' => (int) ($rawSummary['existing_draft_entry_count'] ?? ($curriculum instanceof CurriculumVersion ? $curriculum->entries()->count() : 0)),
            'warnings_count' => (int) ($rawSummary['warnings_count'] ?? 0),
            'blocked_count' => (int) ($rawSummary['blocked_count'] ?? 0),
            'skipped_count' => (int) ($rawSummary['skipped_count'] ?? 0),
        ];

        $placementGroups = [];

        if ($includePlacement) {
            $grouped = [];

            foreach ($preview['rows'] as $row) {
                if (! in_array($row['status'], [
                    CurriculumSpreadsheetImportService::StatusReadyNew,
                    CurriculumSpreadsheetImportService::StatusReadyExisting,
                ], true)) {
                    continue;
                }

                $year = (int) ($row['year_level'] ?? 0);
                $termType = (string) ($row['term_type'] ?? '');
                $termOrder = match ($termType) {
                    Term::TypeFirstSemester => 1,
                    Term::TypeSecondSemester => 2,
                    Term::TypeSpecialTerm => 3,
                    default => 9,
                };
                $sortKey = sprintf('%02d-%d', $year, $termOrder);

                if (! isset($grouped[$sortKey])) {
                    $grouped[$sortKey] = [
                        'label' => (string) ($row['placement_label'] ?? '—'),
                        'subjects_count' => 0,
                        'total_units' => 0.0,
                    ];
                }

                $grouped[$sortKey]['subjects_count']++;
                $grouped[$sortKey]['total_units'] += (float) ($row['credit_units'] ?? 0.0);
            }

            ksort($grouped);
            $placementGroups = array_values($grouped);
        }

        return [
            'summary' => $summary,
            'rows' => $preview['rows'],
            'placementGroups' => $placementGroups,
        ];
    }

    private static function extractUploadedFilename(mixed $rawState, string $fallbackPath): string
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
     * Send a post-import notification summarizing created, matched, placed, and skipped counts.
     *
     * @param  array{
     *     created_subjects: int,
     *     linked_existing: int,
     *     placed_entries: int,
     *     linked_prerequisites: int,
     *     replaced_existing_entries?: int,
     *     skipped_rows: list<array<string, mixed>>
     * }  $result
     */
    public static function sendImportSummaryNotification(
        array $result,
        bool $includePlacement,
        ?string $skippedCsvPath,
    ): void {
        $created = (int) $result['created_subjects'];
        $linked = (int) $result['linked_existing'];
        $placed = (int) $result['placed_entries'];
        $replaced = (int) ($result['replaced_existing_entries'] ?? 0);
        $skippedCount = count($result['skipped_rows']);

        if ($includePlacement) {
            $title = $placed > 0
                ? "Imported {$placed} ".Str::plural('subject', $placed).' into draft curriculum'
                : 'No subjects imported into draft curriculum';
        } else {
            $totalCatalog = $created + $linked;
            $title = $created > 0
                ? "Imported {$created} new ".Str::plural('subject', $created)
                : ($totalCatalog > 0
                    ? "Matched {$linked} existing ".Str::plural('subject', $linked)
                    : 'No subjects imported');
        }

        $parts = [
            "{$created} new ".Str::plural('subject', $created).' created',
            "{$linked} existing catalog ".Str::plural('subject', $linked).' matched',
        ];

        if ($includePlacement) {
            $parts[] = "{$placed} curriculum ".Str::plural('placement', $placed).' added';

            if ($replaced > 0) {
                $parts[] = "{$replaced} previous draft ".Str::plural('subject', $replaced).' replaced';
            }
        }

        if ($skippedCount > 0) {
            $parts[] = "{$skippedCount} ".Str::plural('row', $skippedCount).' skipped';
        }

        $notification = Notification::make()
            ->title($title)
            ->body(implode(' · ', $parts).'.');

        if (($includePlacement ? $placed : ($created + $linked)) > 0) {
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

    /**
     * @return array{sheets: list<array{index: int, name: string, row_count: int}>, default_sheet_index: int}
     */
    private static function inspectWorkbookCached(string $path, ?string $extension = null): array
    {
        $key = $path.'|'.(@filemtime($path) ?: 0).'|'.($extension ?? '');

        return self::$workbookCache[$key] ??= app(CurriculumSpreadsheetParser::class)->inspectWorkbook($path, $extension);
    }

    /**
     * @return array<string, mixed>
     */
    private static function extractSheetCached(string $path, int $sheetIndex, ?string $extension = null): array
    {
        $key = $path.'|'.(@filemtime($path) ?: 0).'|'.$sheetIndex.'|'.($extension ?? '');

        return self::$sheetCache[$key] ??= app(CurriculumSpreadsheetParser::class)->extractSheet($path, $sheetIndex, $extension);
    }

    /**
     * @param  array<string, int|string|null>  $columnMap
     * @return list<array<string, mixed>>
     */
    private static function parseRowsCached(string $path, int $sheetIndex, array $columnMap, ?string $extension = null): array
    {
        $key = $path.'|'.(@filemtime($path) ?: 0).'|'.$sheetIndex.'|'.md5((string) json_encode($columnMap)).'|'.($extension ?? '');

        return self::$parsedRowsCache[$key] ??= app(CurriculumSpreadsheetParser::class)->parseRows($path, $sheetIndex, $columnMap, $extension);
    }
}
