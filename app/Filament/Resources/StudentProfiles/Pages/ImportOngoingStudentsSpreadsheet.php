<?php

namespace App\Filament\Resources\StudentProfiles\Pages;

use App\Actions\StudentProfiles\CohortGradeMatrixService;
use App\Actions\StudentProfiles\OngoingStudentSpreadsheetExportService;
use App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService;
use App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser;
use App\Actions\StudentProfiles\UndoStudentImportBatch;
use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Models\AcademicYear;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\StudentImportBatch;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\CurriculumSpreadsheetWizard;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
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

    protected static ?string $navigationLabel = 'Import continuing students';

    protected string $view = 'filament.admin.student-profiles.import-ongoing-students';

    public string $workspaceMode = 'roster';

    public int $activeStep = 1;

    public int $maxUnlockedStep = 1;

    public bool $autoSkippedMapping = false;

    // Stage 1 — Cohort scope selections.
    public ?int $programId = null;

    public ?int $curriculumVersionId = null;

    public ?int $entryAcademicYearId = null;

    public string $completedThroughKey = 'none';

    public mixed $uploadedFile = null;

    public mixed $file = null;

    public ?int $sheetIndex = null;

    /** Default OFF per activation safety policy. */
    public bool $sendActivationEmails = false;

    /** @var array<string, int|string|null> */
    public array $columnMap = [];

    /** @var array<string, array<string, mixed>> */
    public array $rowOverrides = [];

    /** @var list<string> */
    public array $trackedFixRowKeys = [];

    public string $reviewTab = 'ready';

    public ?string $uploadError = null;

    public ?string $footerValidationMessage = null;

    /** @var array<string, string> */
    public array $mappingErrors = [];

    #[Locked]
    public ?string $lastSkippedImportCsvPath = null;

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $lastImportSummary = null;

    // Stage 2B — Wide Cohort Grade Matrix state.
    public ?int $matrixProgramId = null;

    public ?int $matrixCurriculumVersionId = null;

    public ?int $matrixEntryAcademicYearId = null;

    public ?string $matrixTermKey = null;

    public string $matrixAcademicYearLabel = '';

    public string $matrixSourceSchool = '';

    public mixed $matrixUploadedFile = null;

    public ?string $matrixUploadError = null;

    public ?string $matrixFooterMessage = null;

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $lastMatrixSummary = null;

    public function mount(): void
    {
        $this->authorizeAccess();
        $this->initializeDefaultCohortScope();
        $this->initializeDefaultMatrixScope();

        $requestedMode = (string) request()->query('mode', '');
        if (in_array($requestedMode, ['roster', 'matrix'], true)) {
            $this->workspaceMode = $requestedMode;
        }

        $requestedProgram = request()->query('program');
        if (is_numeric($requestedProgram) && Program::query()->whereKey((int) $requestedProgram)->exists()) {
            $this->programId = (int) $requestedProgram;
            $this->selectDefaultCurriculumAndBatchForRoster();
            $this->matrixProgramId = (int) $requestedProgram;
            $this->selectDefaultCurriculumAndTermForMatrix();
        }
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
        return 'Import existing students';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Import existing students & prior grades';
    }

    public function getSubheading(): string|Htmlable
    {
        return 'Upload a student list by program and batch year, or update exact prior grades using a pre-filled Excel grade sheet.';
    }

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [
            GradesAndCompletion::getUrl() => 'Students Record',
            $this->returnUrl() => 'Student records',
            'Import existing students',
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

    public function setWorkspaceMode(string $mode): void
    {
        if (! in_array($mode, ['roster', 'matrix'], true)) {
            return;
        }

        $this->workspaceMode = $mode;
        $this->footerValidationMessage = null;
        $this->matrixFooterMessage = null;

        if ($mode === 'matrix' && $this->matrixProgramId === null) {
            $this->initializeDefaultMatrixScope();
        }
    }

    public function updatedProgramId(): void
    {
        $this->curriculumVersionId = null;
        $this->entryAcademicYearId = null;
        $this->completedThroughKey = 'none';
        $this->footerValidationMessage = null;

        $this->selectDefaultCurriculumAndBatchForRoster();
        $this->resyncOverridesIfFileLoaded();
    }

    public function updatedCurriculumVersionId(): void
    {
        $this->completedThroughKey = 'none';
        $this->footerValidationMessage = null;

        $this->autoSelectEntryAcademicYearForRoster();
        $this->resyncOverridesIfFileLoaded();
    }

    public function updatedEntryAcademicYearId(): void
    {
        $this->footerValidationMessage = null;
        $this->resyncOverridesIfFileLoaded();
    }

    public function updatedCompletedThroughKey(): void
    {
        $this->footerValidationMessage = null;
        $this->resyncOverridesIfFileLoaded();
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

    public function startAnotherImport(): void
    {
        $this->clearUploadedFile();
        $this->lastImportSummary = null;
        $this->lastSkippedImportCsvPath = null;
        $this->dispatch('import-step-changed', targetId: 'ongoing-import-step-1-heading');
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
        $this->autoSkippedMapping = false;
        $this->uploadError = null;
        $this->footerValidationMessage = null;
        $this->mappingErrors = [];
    }

    public function updatedSheetIndex(): void
    {
        $this->uploadError = null;
        $this->footerValidationMessage = null;
        $this->mappingErrors = [];
        $this->columnMap = [];
        $this->rowOverrides = [];
        $this->trackedFixRowKeys = [];
        $this->autoSkippedMapping = false;

        $extracted = $this->currentExtractedWorkbook();

        if ($extracted === null) {
            return;
        }

        $this->columnMap = $this->stringifyColumnMap($extracted['suggested_mapping']);
        $this->syncRowOverridesFromExtracted($extracted);

        if ($extracted['parsed_row_count'] === 0) {
            $this->uploadError = 'No student rows were found in this worksheet. Check that you selected the right tab or download the template.';
            $this->maxUnlockedStep = 1;
        }
    }

    public function updatedColumnMap(): void
    {
        $this->mappingErrors = [];
        $this->footerValidationMessage = null;
        $extracted = $this->currentExtractedWorkbook();

        if ($extracted !== null) {
            $this->syncRowOverridesFromExtracted($extracted);
        }
    }

    public function continueFromStep1(): void
    {
        $extracted = $this->currentExtractedWorkbook();

        if ($extracted === null) {
            $this->uploadError = 'Upload a valid Excel (.xlsx) or CSV (.csv) roster file to continue.';
            $this->activeStep = 1;

            return;
        }

        if ($extracted['parsed_row_count'] === 0) {
            $this->uploadError = 'No student rows were found in this worksheet. Check that you selected the right tab or download the template.';
            $this->activeStep = 1;

            return;
        }

        if (! $this->hasCohortScopeOrProgramColumn($extracted['suggested_mapping'])) {
            $this->uploadError = 'Select a Program and Curriculum Version in the cohort scope bar above before continuing.';
            $this->activeStep = 1;

            return;
        }

        if ($this->columnMap === []) {
            $this->columnMap = $this->stringifyColumnMap($extracted['suggested_mapping']);
        }

        if (OngoingStudentSpreadsheetParser::hasAllRequiredHeadersMapped($this->columnMap)) {
            $this->autoSkippedMapping = true;
            $this->goToStep(3);

            return;
        }

        $this->autoSkippedMapping = false;
        $this->goToStep(2);
    }

    public function goToStep(int $step): void
    {
        $this->footerValidationMessage = null;

        if ($step <= 1) {
            $this->activeStep = 1;
            $this->dispatch('import-step-changed', targetId: 'ongoing-import-step-1-heading');

            return;
        }

        $extracted = $this->currentExtractedWorkbook();

        if ($extracted === null) {
            $this->uploadError = 'Upload a valid Excel (.xlsx) or CSV (.csv) spreadsheet file to continue.';
            $this->activeStep = 1;

            return;
        }

        if ($extracted['parsed_row_count'] === 0) {
            $this->uploadError = 'No student rows were found in this worksheet. Check that you selected the right tab or download the template.';
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
            $this->dispatch('import-step-changed', targetId: 'ongoing-import-step-2-heading');

            return;
        }

        $errors = $this->validateColumnMapErrors($this->columnMap);

        if ($errors !== []) {
            $this->mappingErrors = $errors;
            $this->activeStep = 2;
            $this->maxUnlockedStep = max($this->maxUnlockedStep, 2);
            $this->dispatch('import-step-changed', targetId: 'ongoing-import-step-2-heading');

            return;
        }

        $this->mappingErrors = [];
        $this->syncRowOverridesFromExtracted($extracted);

        $preview = app(OngoingStudentSpreadsheetImportService::class)->buildPreview(
            parsedStudents: $extracted['parsed_students'],
            rowOverrides: $this->rowOverrides,
            cohortScope: $this->currentCohortScope($extracted['filename'] ?? null),
        );

        $this->reviewTab = $this->resolveDefaultReviewTab($preview['summary']);
        $this->activeStep = 3;
        $this->maxUnlockedStep = 3;
        $this->dispatch('import-step-changed', targetId: 'ongoing-import-step-3-heading');
    }

    public function setReviewTab(string $tab): void
    {
        if (in_array($tab, ['fix_required', 'ready', 'update_unactivated', 'already_onboarded', 'all'], true)) {
            $this->reviewTab = $tab;
        }
    }

    public function toggleSkipRow(string $rowKey): void
    {
        $this->footerValidationMessage = null;

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
        $this->footerValidationMessage = null;
        $extracted = $this->currentExtractedWorkbook();

        if ($extracted === null) {
            return;
        }

        $preview = app(OngoingStudentSpreadsheetImportService::class)->buildPreview(
            parsedStudents: $extracted['parsed_students'],
            rowOverrides: $this->rowOverrides,
            cohortScope: $this->currentCohortScope($extracted['filename'] ?? null),
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

    public function openConfirmImportModal(): void
    {
        $this->footerValidationMessage = null;
        $extracted = $this->currentExtractedWorkbook();

        if ($extracted === null || $extracted['parsed_row_count'] === 0) {
            $this->footerValidationMessage = 'Upload a spreadsheet containing at least one student row before importing.';

            return;
        }

        $preview = app(OngoingStudentSpreadsheetImportService::class)->buildPreview(
            parsedStudents: $extracted['parsed_students'],
            rowOverrides: $this->rowOverrides,
            cohortScope: $this->currentCohortScope($extracted['filename'] ?? null),
        );

        $importable = (int) ($preview['summary']['ready_new'] ?? 0) + (int) ($preview['summary']['update_unactivated'] ?? 0);

        if ($importable === 0) {
            $this->footerValidationMessage = 'No rows are currently ready to onboard or update. Fix at least one highlighted row or include a skipped row to continue.';

            return;
        }

        $this->dispatch('open-modal', id: 'confirm-cohort-import-modal');
    }

    public function commitImport(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);

        $this->footerValidationMessage = null;
        $extracted = $this->currentExtractedWorkbook();

        if ($extracted === null || $extracted['parsed_row_count'] === 0) {
            $this->footerValidationMessage = 'Upload a valid spreadsheet containing at least one student row.';
            $this->activeStep = 1;

            return;
        }

        $errors = $this->validateColumnMapErrors($this->columnMap);

        if ($errors !== []) {
            $this->mappingErrors = $errors;
            $this->activeStep = 2;

            return;
        }

        $cohortScope = $this->currentCohortScope($extracted['filename'] ?? null);
        $importService = app(OngoingStudentSpreadsheetImportService::class);
        $preview = $importService->buildPreview(
            parsedStudents: $extracted['parsed_students'],
            rowOverrides: $this->rowOverrides,
            cohortScope: $cohortScope,
        );

        $importableCount = (int) ($preview['summary']['ready_new'] ?? 0) + (int) ($preview['summary']['update_unactivated'] ?? 0);

        if ($importableCount === 0) {
            $this->footerValidationMessage = 'No valid rows are ready to onboard or update. Fix at least one row with issues before importing.';
            $this->dispatch('close-modal', id: 'confirm-cohort-import-modal');

            return;
        }

        try {
            $result = $importService->commitImport(
                actor: $actor,
                previewRows: $preview['rows'],
                sendActivationEmails: $this->sendActivationEmails,
                skipBlocked: true,
                cohortScope: $cohortScope,
            );
        } catch (ValidationException $exception) {
            $this->dispatch('close-modal', id: 'confirm-cohort-import-modal');
            Notification::make()
                ->title('Import could not be completed')
                ->body(collect($exception->errors())->flatten()->implode(' '))
                ->danger()
                ->send();

            return;
        }

        $this->dispatch('close-modal', id: 'confirm-cohort-import-modal');

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
            'import_batch_id' => $result['import_batch_id'],
            'created_students' => (int) ($result['created_students'] ?? 0),
            'updated_students' => (int) ($result['updated_students'] ?? 0),
            'linked_existing_applicants' => (int) ($result['linked_existing_applicants'] ?? 0),
            'skipped_existing_students' => (int) ($result['skipped_existing_students'] ?? 0),
            'prior_credits_recorded' => (int) ($result['prior_credits_recorded'] ?? 0),
            'activation_emails_sent' => (int) ($result['activation_emails_sent'] ?? 0),
            'activation_emails_failed' => (int) ($result['activation_emails_failed'] ?? 0),
            'skipped_rows_count' => count($skippedRows),
            'filename' => $extracted['filename'] ?? null,
            'reverted' => false,
        ];

        // Clear uploaded file state while keeping persistent completion summary + Undo Batch visible!
        $this->clearUploadedFile();

        $this->sendImportSummaryNotification($result, $skippedCsvPath);
    }

    public function undoImportBatch(int $batchId): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);

        $batch = StudentImportBatch::query()->find($batchId);

        if (! $batch instanceof StudentImportBatch) {
            Notification::make()
                ->title('Import batch not found')
                ->danger()
                ->send();

            return;
        }

        try {
            $result = app(UndoStudentImportBatch::class)->execute($batch, $actor);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Import batch cannot be undone')
                ->body(collect($exception->errors())->flatten()->implode(' '))
                ->danger()
                ->send();

            return;
        }

        if (is_array($this->lastImportSummary) && (int) ($this->lastImportSummary['import_batch_id'] ?? 0) === $batchId) {
            $this->lastImportSummary['reverted'] = true;
            $this->lastImportSummary['reverted_profiles'] = (int) $result['reverted_profiles'];
        }

        Notification::make()
            ->title("Undid import batch #{$batchId}")
            ->body("{$result['reverted_profiles']} student ".Str::plural('profile', $result['reverted_profiles']).' removed cleanly.')
            ->success()
            ->send();
    }

    // -------------------------------------------------------------------------
    // Stage 2B — Wide Cohort Grade Matrix Actions
    // -------------------------------------------------------------------------

    public function updatedMatrixProgramId(): void
    {
        $this->matrixCurriculumVersionId = null;
        $this->matrixEntryAcademicYearId = null;
        $this->matrixTermKey = null;
        $this->matrixUploadError = null;
        $this->matrixFooterMessage = null;
        $this->lastMatrixSummary = null;

        $this->selectDefaultCurriculumAndTermForMatrix();
    }

    public function updatedMatrixCurriculumVersionId(): void
    {
        $this->matrixTermKey = null;
        $this->matrixUploadError = null;
        $this->matrixFooterMessage = null;
        $this->lastMatrixSummary = null;

        $this->selectDefaultTermForMatrix();
    }

    public function updatedMatrixUploadedFile(): void
    {
        $this->matrixUploadError = null;
        $this->matrixFooterMessage = null;
        $this->lastMatrixSummary = null;

        $resolved = CurriculumSpreadsheetWizard::resolveUploadedFile($this->matrixUploadedFile);
        if ($resolved === null) {
            $this->matrixUploadError = 'The uploaded Grade Matrix could not be read as a valid Excel (.xlsx) or CSV (.csv) file.';
        }
    }

    public function clearMatrixUploadedFile(): void
    {
        $this->matrixUploadedFile = null;
        $this->matrixUploadError = null;
        $this->matrixFooterMessage = null;
    }

    public function downloadGradeMatrix(): ?BinaryFileResponse
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);

        $selection = $this->resolveMatrixSelection();
        if ($selection === null) {
            $this->matrixFooterMessage = 'Select a Program, Curriculum Version, and Prior Year & Term before downloading a Grade Matrix.';

            return null;
        }

        $service = app(CohortGradeMatrixService::class);
        $path = $service->exportMatrix(
            program: $selection['program'],
            curriculum: $selection['curriculum'],
            yearLevel: $selection['year_level'],
            termType: $selection['term_type'],
            entryAcademicYearId: $this->matrixEntryAcademicYearId,
        );

        return $service->downloadAndDelete($path, basename($path));
    }

    public function openConfirmMatrixModal(): void
    {
        $this->matrixFooterMessage = null;
        $preview = $this->currentMatrixPreview();

        if ($preview === null) {
            $this->matrixFooterMessage = 'Select a cohort period and upload a completed Grade Matrix (.xlsx or .csv) first.';

            return;
        }

        if ((int) ($preview['summary']['changes_total'] ?? 0) === 0) {
            $this->matrixFooterMessage = 'No grade changes were detected in the uploaded matrix.';

            return;
        }

        $this->dispatch('open-modal', id: 'confirm-matrix-commit-modal');
    }

    public function commitGradeMatrix(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);

        $this->matrixFooterMessage = null;
        $preview = $this->currentMatrixPreview();

        if ($preview === null || ($preview['changes'] ?? []) === []) {
            $this->matrixFooterMessage = 'No valid grade updates were found to apply.';
            $this->dispatch('close-modal', id: 'confirm-matrix-commit-modal');

            return;
        }

        try {
            $result = app(CohortGradeMatrixService::class)->commitMatrix($actor, $preview['changes']);
        } catch (ValidationException $exception) {
            $this->dispatch('close-modal', id: 'confirm-matrix-commit-modal');
            Notification::make()
                ->title('Grade matrix update failed')
                ->body(collect($exception->errors())->flatten()->implode(' '))
                ->danger()
                ->send();

            return;
        }

        $this->dispatch('close-modal', id: 'confirm-matrix-commit-modal');
        $this->matrixUploadedFile = null;
        $this->lastMatrixSummary = [
            ...$result,
            'error_count' => (int) ($preview['summary']['error_count'] ?? 0),
        ];

        Notification::make()
            ->title("Applied {$result['total_applied']} prior ".Str::plural('grade', $result['total_applied']))
            ->body("{$result['updated_cells']} updated, {$result['created_cells']} recorded across {$result['students_affected']} ".Str::plural('student', $result['students_affected']).'. Completion readiness and official transcripts have been refreshed.')
            ->success()
            ->send();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $programs = $this->availablePrograms();
        $rosterCurricula = $this->curriculaForProgram($this->programId);
        $selectedRosterCurriculum = $rosterCurricula->firstWhere('id', $this->curriculumVersionId);
        $rosterAcademicYears = $this->academicYearOptionsForCurriculum($selectedRosterCurriculum);
        $rosterTermOptions = $this->curriculumVersionId !== null
            ? app(CohortGradeMatrixService::class)->availableTermsForCurriculum($this->curriculumVersionId)
            : [];

        $rawState = $this->uploadedFile ?? $this->file;
        $extracted = $this->currentExtractedWorkbook();
        $cohortScope = $this->currentCohortScope($extracted['filename'] ?? null);

        $preview = $extracted !== null && $extracted['parsed_row_count'] > 0
            ? app(OngoingStudentSpreadsheetImportService::class)->buildPreview(
                parsedStudents: $extracted['parsed_students'],
                rowOverrides: $this->rowOverrides,
                cohortScope: $cohortScope,
            )
            : [
                'summary' => [
                    'total' => 0,
                    'ready_new' => 0,
                    'update_unactivated' => 0,
                    'already_onboarded' => 0,
                    'blocked_count' => 0,
                    'skipped_count' => 0,
                    'warnings_count' => 0,
                    'applicant_collisions_blocked' => 0,
                    'applicant_links_opted_in' => 0,
                    'estimated_prior_credits' => 0,
                ],
                'rows' => [],
            ];

        // Recent import batches (with live canUndo status).
        $undoService = app(UndoStudentImportBatch::class);
        $recentBatches = StudentImportBatch::query()
            ->with(['program', 'curriculumVersion', 'entryAcademicYear'])
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(function (StudentImportBatch $batch) use ($undoService): array {
                $check = $undoService->canUndo($batch);

                return [
                    'id' => (int) $batch->id,
                    'program_code' => (string) ($batch->program?->code ?? 'Program'),
                    'curriculum_code' => (string) ($batch->curriculumVersion?->version_code ?? ''),
                    'entry_ay_label' => $batch->entryAcademicYear?->label,
                    'student_count' => (int) $batch->student_count,
                    'filename' => $batch->filename,
                    'created_at_label' => $batch->created_at?->timezone('Asia/Manila')->format('M j, Y g:i A') ?? '',
                    'is_reverted' => $batch->isReverted(),
                    'can_undo' => (bool) $check['allowed'],
                    'undo_reason' => $check['reason'],
                ];
            })
            ->all();

        $lastBatchUndoStatus = null;
        if (is_array($this->lastImportSummary) && filled($this->lastImportSummary['import_batch_id'] ?? null)) {
            $lastBatch = StudentImportBatch::query()->find((int) $this->lastImportSummary['import_batch_id']);
            if ($lastBatch instanceof StudentImportBatch) {
                $lastBatchUndoStatus = $undoService->canUndo($lastBatch);
            }
        }

        // Stage 2B — Matrix view data.
        $matrixCurricula = $this->curriculaForProgram($this->matrixProgramId);
        $selectedMatrixCurriculum = $matrixCurricula->firstWhere('id', $this->matrixCurriculumVersionId);
        $matrixAcademicYears = $this->academicYearOptionsForCurriculum($selectedMatrixCurriculum);
        $matrixTermOptions = $this->matrixCurriculumVersionId !== null
            ? app(CohortGradeMatrixService::class)->availableTermsForCurriculum($this->matrixCurriculumVersionId)
            : [];
        $matrixSelection = $this->resolveMatrixSelection();
        $matrixCohortCount = $matrixSelection !== null
            ? app(CohortGradeMatrixService::class)->cohortStudentsQuery(
                $matrixSelection['program']->id,
                $matrixSelection['curriculum']->id,
                $this->matrixEntryAcademicYearId,
            )->count()
            : 0;
        $matrixSubjectCount = $matrixSelection !== null
            ? app(CohortGradeMatrixService::class)->curriculumEntriesForTerm(
                $matrixSelection['curriculum']->id,
                $matrixSelection['year_level'],
                $matrixSelection['term_type'],
            )->count()
            : 0;
        $matrixPreview = $this->currentMatrixPreview();

        $selectedScopeProgram = $programs->firstWhere('id', $this->programId);
        $selectedCompletedTerm = collect($rosterTermOptions)->firstWhere('key', $this->completedThroughKey);
        $selectedEntryAy = $this->entryAcademicYearId !== null
            ? AcademicYear::query()->find($this->entryAcademicYearId)
            : null;

        return [
            'workspaceMode' => $this->workspaceMode,
            'activeStep' => $this->activeStep,
            'maxUnlockedStep' => $this->maxUnlockedStep,
            'autoSkippedMapping' => $this->autoSkippedMapping,
            'programs' => $programs,
            'rosterCurricula' => $rosterCurricula,
            'rosterAcademicYears' => $rosterAcademicYears,
            'rosterTermOptions' => $rosterTermOptions,
            'selectedScopeSummary' => [
                'program_label' => $selectedScopeProgram instanceof Program ? "{$selectedScopeProgram->code} — {$selectedScopeProgram->name}" : null,
                'curriculum_label' => $selectedRosterCurriculum instanceof CurriculumVersion
                    ? "{$selectedRosterCurriculum->version_code} ({$selectedRosterCurriculum->batchLabel()})"
                    : null,
                'entry_ay_label' => $selectedEntryAy instanceof AcademicYear ? (string) $selectedEntryAy->label : null,
                'completed_through_label' => is_array($selectedCompletedTerm)
                    ? "{$selectedCompletedTerm['label']} ({$selectedCompletedTerm['cumulative_subject_count']} subjects · {$selectedCompletedTerm['cumulative_units']} units)"
                    : 'None — route to individual advising',
            ],
            'hasFile' => $rawState !== null && $extracted !== null,
            'filename' => $extracted['filename'] ?? null,
            'sheets' => $extracted['sheets'] ?? [],
            'sheetIndex' => $extracted['sheet_index'] ?? $this->sheetIndex ?? 0,
            'sheetName' => $extracted['sheet_name'] ?? null,
            'headerOptions' => $extracted['header_options'] ?? [],
            'parsedRowCount' => (int) ($extracted['parsed_row_count'] ?? 0),
            'fieldLabels' => OngoingStudentSpreadsheetParser::canonicalFieldLabels(),
            'requiredFields' => OngoingStudentSpreadsheetParser::requiredFields(),
            'columnMap' => $this->columnMap,
            'uploadError' => $this->uploadError,
            'footerValidationMessage' => $this->footerValidationMessage,
            'mappingErrors' => $this->mappingErrors,
            'summary' => $preview['summary'],
            'rows' => $preview['rows'],
            'reviewTab' => $this->reviewTab,
            'trackedFixRowKeys' => $this->trackedFixRowKeys,
            'sendActivationEmails' => $this->sendActivationEmails,
            'standingOptions' => $this->standingOptions(),
            'lifecycleOptions' => $this->lifecycleOptions(),
            'lastImportSummary' => $this->lastImportSummary,
            'lastBatchUndoStatus' => $lastBatchUndoStatus,
            'recentBatches' => $recentBatches,
            'hasSkippedRowsCsv' => $this->lastSkippedImportCsvPath !== null && is_file($this->lastSkippedImportCsvPath),
            'returnUrl' => $this->returnUrl(),

            // Stage 2B — Matrix view data.
            'matrixCurricula' => $matrixCurricula,
            'matrixAcademicYears' => $matrixAcademicYears,
            'matrixTermOptions' => $matrixTermOptions,
            'matrixCohortCount' => $matrixCohortCount,
            'matrixSubjectCount' => $matrixSubjectCount,
            'matrixHasFile' => $this->matrixUploadedFile !== null && CurriculumSpreadsheetWizard::resolveUploadedFile($this->matrixUploadedFile) !== null,
            'matrixFilename' => $this->matrixUploadedFile !== null
                ? $this->extractUploadedFilename($this->matrixUploadedFile, 'grade-matrix.xlsx')
                : null,
            'matrixUploadError' => $this->matrixUploadError,
            'matrixFooterMessage' => $this->matrixFooterMessage,
            'matrixPreview' => $matrixPreview,
            'lastMatrixSummary' => $this->lastMatrixSummary,
        ];
    }

    /**
     * @return Collection<int, Program>
     */
    private function availablePrograms(): Collection
    {
        return Program::query()
            ->whereHas('curriculumVersions', fn (Builder $query): Builder => $query
                ->whereIn('state', [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded]))
            ->orderByDesc('is_active')
            ->orderBy('code')
            ->get();
    }

    /**
     * @return Collection<int, CurriculumVersion>
     */
    private function curriculaForProgram(?int $programId): Collection
    {
        if ($programId === null) {
            return collect();
        }

        return CurriculumVersion::query()
            ->with(['academicYears'])
            ->where('program_id', $programId)
            ->whereIn('state', [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded])
            ->orderByRaw("CASE WHEN state = 'ACTIVE' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return list<array{id: int, label: string, is_linked: bool}>
     */
    private function academicYearOptionsForCurriculum(?CurriculumVersion $curriculum): array
    {
        $linkedIds = [];
        $options = [];

        if ($curriculum instanceof CurriculumVersion) {
            $linkedYears = $curriculum->academicYears
                ->sortBy(fn (AcademicYear $ay): int => CurriculumVersion::extractAcademicYearStart($ay) ?? PHP_INT_MAX)
                ->values();

            foreach ($linkedYears as $ay) {
                $linkedIds[(int) $ay->id] = true;
                $options[] = [
                    'id' => (int) $ay->id,
                    'label' => (string) $ay->label.' (Batch '.CurriculumVersion::formatBatchShort($ay).' — linked)',
                    'is_linked' => true,
                ];
            }
        }

        $otherYears = AcademicYear::query()
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get();

        foreach ($otherYears as $ay) {
            if (isset($linkedIds[(int) $ay->id])) {
                continue;
            }
            $options[] = [
                'id' => (int) $ay->id,
                'label' => (string) $ay->label,
                'is_linked' => false,
            ];
        }

        return $options;
    }

    private function initializeDefaultCohortScope(): void
    {
        $programs = $this->availablePrograms();
        $firstProgram = $programs->first();
        if (! $firstProgram instanceof Program) {
            return;
        }

        $this->programId = (int) $firstProgram->id;
        $this->selectDefaultCurriculumAndBatchForRoster();
    }

    private function selectDefaultCurriculumAndBatchForRoster(): void
    {
        $curricula = $this->curriculaForProgram($this->programId);
        $firstCurriculum = $curricula->first();
        if (! $firstCurriculum instanceof CurriculumVersion) {
            $this->curriculumVersionId = null;
            $this->entryAcademicYearId = null;

            return;
        }

        $this->curriculumVersionId = (int) $firstCurriculum->id;
        $this->autoSelectEntryAcademicYearForRoster();
    }

    private function autoSelectEntryAcademicYearForRoster(): void
    {
        if ($this->curriculumVersionId === null) {
            $this->entryAcademicYearId = null;

            return;
        }

        $curriculum = CurriculumVersion::query()->with('academicYears')->find($this->curriculumVersionId);
        if (! $curriculum instanceof CurriculumVersion) {
            $this->entryAcademicYearId = null;

            return;
        }

        $linked = $curriculum->academicYears
            ->sortBy(fn (AcademicYear $ay): int => CurriculumVersion::extractAcademicYearStart($ay) ?? PHP_INT_MAX)
            ->values();

        if ($linked->isNotEmpty()) {
            $this->entryAcademicYearId = (int) $linked->first()->id;
        } else {
            $this->entryAcademicYearId = null;
        }
    }

    private function initializeDefaultMatrixScope(): void
    {
        $programs = $this->availablePrograms();
        $firstProgram = $programs->first();
        if (! $firstProgram instanceof Program) {
            return;
        }

        $this->matrixProgramId = (int) $firstProgram->id;
        $this->selectDefaultCurriculumAndTermForMatrix();
    }

    private function selectDefaultCurriculumAndTermForMatrix(): void
    {
        $curricula = $this->curriculaForProgram($this->matrixProgramId);
        $firstCurriculum = $curricula->first();
        if (! $firstCurriculum instanceof CurriculumVersion) {
            $this->matrixCurriculumVersionId = null;
            $this->matrixTermKey = null;

            return;
        }

        $this->matrixCurriculumVersionId = (int) $firstCurriculum->id;
        $this->selectDefaultTermForMatrix();
    }

    private function selectDefaultTermForMatrix(): void
    {
        if ($this->matrixCurriculumVersionId === null) {
            $this->matrixTermKey = null;

            return;
        }

        $terms = app(CohortGradeMatrixService::class)->availableTermsForCurriculum($this->matrixCurriculumVersionId);
        $this->matrixTermKey = $terms[0]['key'] ?? null;
    }

    /**
     * @return array{program: Program, curriculum: CurriculumVersion, year_level: int, term_type: string}|null
     */
    private function resolveMatrixSelection(): ?array
    {
        if ($this->matrixProgramId === null || $this->matrixCurriculumVersionId === null || blank($this->matrixTermKey)) {
            return null;
        }

        $parsedTerm = $this->parseTermKey((string) $this->matrixTermKey);
        if ($parsedTerm === null) {
            return null;
        }

        $program = Program::query()->find($this->matrixProgramId);
        $curriculum = CurriculumVersion::query()->find($this->matrixCurriculumVersionId);

        if (! $program instanceof Program || ! $curriculum instanceof CurriculumVersion) {
            return null;
        }

        return [
            'program' => $program,
            'curriculum' => $curriculum,
            'year_level' => $parsedTerm['year_level'],
            'term_type' => $parsedTerm['term_type'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentMatrixPreview(): ?array
    {
        if ($this->matrixUploadedFile === null) {
            return null;
        }

        $selection = $this->resolveMatrixSelection();
        if ($selection === null) {
            return null;
        }

        $resolved = CurriculumSpreadsheetWizard::resolveUploadedFile($this->matrixUploadedFile);
        if ($resolved === null) {
            return null;
        }

        try {
            return app(CohortGradeMatrixService::class)->previewMatrix(
                path: $resolved['path'],
                program: $selection['program'],
                curriculum: $selection['curriculum'],
                yearLevel: $selection['year_level'],
                termType: $selection['term_type'],
                extension: $resolved['extension'],
                academicYearLabel: filled($this->matrixAcademicYearLabel) ? $this->matrixAcademicYearLabel : null,
                sourceSchool: filled($this->matrixSourceSchool) ? $this->matrixSourceSchool : null,
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{year_level: int, term_type: string}|null
     */
    private function parseTermKey(?string $key): ?array
    {
        if ($key === null || $key === '' || $key === 'none' || ! str_contains($key, '|')) {
            return null;
        }

        [$yearRaw, $termType] = explode('|', $key, 2);
        if (! ctype_digit($yearRaw) || trim($termType) === '') {
            return null;
        }

        return [
            'year_level' => (int) $yearRaw,
            'term_type' => trim($termType),
        ];
    }

    /**
     * @return array{
     *     program_id: int|null,
     *     curriculum_version_id: int|null,
     *     entry_academic_year_id: int|null,
     *     completed_through_year_level: int|null,
     *     completed_through_term_type: string|null,
     *     filename: string|null
     * }
     */
    private function currentCohortScope(?string $filename = null): array
    {
        $completed = $this->parseTermKey($this->completedThroughKey);

        return [
            'program_id' => $this->programId,
            'curriculum_version_id' => $this->curriculumVersionId,
            'entry_academic_year_id' => $this->entryAcademicYearId,
            'completed_through_year_level' => $completed['year_level'] ?? null,
            'completed_through_term_type' => $completed['term_type'] ?? null,
            'filename' => $filename,
        ];
    }

    /**
     * @param  array<string, int|string|null>  $mapping
     */
    private function hasCohortScopeOrProgramColumn(array $mapping): bool
    {
        if ($this->programId !== null && $this->curriculumVersionId !== null) {
            return true;
        }

        $progCol = $mapping[OngoingStudentSpreadsheetParser::FieldProgramCode] ?? null;

        return $progCol !== null && $progCol !== '' && $progCol !== 'ignore' && $progCol !== 'none';
    }

    private function resyncOverridesIfFileLoaded(): void
    {
        $extracted = $this->currentExtractedWorkbook();
        if ($extracted !== null) {
            $this->syncRowOverridesFromExtracted($extracted);
        }
    }

    private function handleFileUploaded(): void
    {
        $this->uploadError = null;
        $this->footerValidationMessage = null;
        $this->mappingErrors = [];
        $this->columnMap = [];
        $this->rowOverrides = [];
        $this->trackedFixRowKeys = [];
        $this->activeStep = 1;
        $this->maxUnlockedStep = 1;
        $this->autoSkippedMapping = false;
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

        $extracted = $this->currentExtractedWorkbook();

        if ($extracted === null) {
            $this->uploadError = 'The selected worksheet could not be read.';

            return;
        }

        $this->columnMap = $this->stringifyColumnMap($extracted['suggested_mapping']);
        $this->syncRowOverridesFromExtracted($extracted);

        if ($extracted['parsed_row_count'] === 0) {
            $this->uploadError = 'No student rows were found in this worksheet. Check that you selected the right tab or download the template.';

            return;
        }

        // Auto-skip Step 2 when all required headers match cleanly AND cohort scope is selected (or Program column present).
        if (! empty($extracted['all_required_mapped']) && $this->hasCohortScopeOrProgramColumn($extracted['suggested_mapping'])) {
            $preview = app(OngoingStudentSpreadsheetImportService::class)->buildPreview(
                parsedStudents: $extracted['parsed_students'],
                rowOverrides: $this->rowOverrides,
                cohortScope: $this->currentCohortScope($extracted['filename'] ?? null),
            );

            $this->autoSkippedMapping = true;
            $this->reviewTab = $this->resolveDefaultReviewTab($preview['summary']);
            $this->activeStep = 3;
            $this->maxUnlockedStep = 3;
            $this->dispatch('import-step-changed', targetId: 'ongoing-import-step-3-heading');
        } else {
            $this->maxUnlockedStep = 2;
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
        } catch (Throwable) {
            return null;
        }

        return [
            'filename' => $this->extractUploadedFilename($rawState, $resolved['path']),
            'sheets' => $workbook['sheets'],
            'sheet_index' => (int) $extracted['sheet_index'],
            'sheet_name' => (string) $extracted['sheet_name'],
            'header_options' => $extracted['header_options'],
            'suggested_mapping' => $extracted['suggested_mapping'],
            'all_required_mapped' => (bool) $extracted['all_required_mapped'],
            'parsed_students' => $parsedStudents,
            'parsed_row_count' => count($parsedStudents),
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
            rowOverrides: [],
            cohortScope: $this->currentCohortScope($extracted['filename'] ?? null),
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
                'academic_standing' => is_array($prior) && array_key_exists('academic_standing', $prior) ? (string) ($prior['academic_standing'] ?? '') : $base['academic_standing'],
                'lifecycle_status' => is_array($prior) && array_key_exists('lifecycle_status', $prior) ? (string) ($prior['lifecycle_status'] ?? '') : $base['lifecycle_status'],
                'birth_date' => is_array($prior) && array_key_exists('birth_date', $prior) ? (string) ($prior['birth_date'] ?? '') : $base['birth_date'],
                'prior_identifier' => is_array($prior) && array_key_exists('prior_identifier', $prior) ? (string) ($prior['prior_identifier'] ?? '') : $base['prior_identifier'],
                'phone' => is_array($prior) && array_key_exists('phone', $prior) ? (string) ($prior['phone'] ?? '') : $base['phone'],
                'financial_hold_note' => is_array($prior) && array_key_exists('financial_hold_note', $prior) ? (string) ($prior['financial_hold_note'] ?? '') : $base['financial_hold_note'],
                'program_code' => is_array($prior) && array_key_exists('program_code', $prior) ? (string) ($prior['program_code'] ?? '') : $base['program_code'],
                'curriculum_version_code' => is_array($prior) && array_key_exists('curriculum_version_code', $prior) ? (string) ($prior['curriculum_version_code'] ?? '') : $base['curriculum_version_code'],
                'link_existing_applicant' => is_array($prior) ? (bool) ($prior['link_existing_applicant'] ?? false) : false,
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
            'academic_standing' => (string) ($row['raw_academic_standing'] ?? $row['academic_standing'] ?? StudentProfile::StandingRegular),
            'lifecycle_status' => (string) ($row['raw_lifecycle_status'] ?? $row['lifecycle_status'] ?? StudentProfile::LifecycleActive),
            'birth_date' => (string) ($row['birth_date'] ?? ''),
            'prior_identifier' => (string) ($row['prior_identifier'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'financial_hold_note' => (string) ($row['financial_hold_note'] ?? ''),
            'program_code' => (string) ($row['program_code'] ?? ''),
            'curriculum_version_code' => (string) ($row['curriculum_version_code'] ?? ''),
            'link_existing_applicant' => (bool) ($row['link_existing_applicant'] ?? false),
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

        if (($summary['update_unactivated'] ?? 0) > 0) {
            return 'update_unactivated';
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
     * @return array<string, string>
     */
    private function standingOptions(): array
    {
        return [
            StudentProfile::StandingRegular => 'Regular',
            StudentProfile::StandingIrregular => 'Irregular',
            StudentProfile::StandingProbationary => 'Probationary',
            StudentProfile::StandingDeficient => 'Deficient',
            StudentProfile::StandingCompletionCandidate => 'Completion Candidate',
            StudentProfile::StandingGraduationCandidate => 'Graduation Candidate',
            StudentProfile::StandingBlockedByPrerequisite => 'Blocked by Prerequisite',
            StudentProfile::StandingMustRepeatYear => 'Must Repeat Year Level',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function lifecycleOptions(): array
    {
        return [
            StudentProfile::LifecycleActive => 'Active (ACTIVE)',
            StudentProfile::LifecycleLeaveOfAbsence => 'Leave of Absence (LEAVE_OF_ABSENCE)',
            StudentProfile::LifecycleInactive => 'Inactive (INACTIVE)',
            StudentProfile::LifecycleWithdrawn => 'Withdrawn (WITHDRAWN)',
            StudentProfile::LifecycleTransferredOut => 'Transferred Out (TRANSFERRED_OUT)',
            StudentProfile::LifecycleCompleted => 'Completed (COMPLETED)',
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function sendImportSummaryNotification(array $result, ?string $skippedCsvPath): void
    {
        $created = (int) ($result['created_students'] ?? 0);
        $updated = (int) ($result['updated_students'] ?? 0);
        $priorRecorded = (int) ($result['prior_credits_recorded'] ?? 0);
        $emailsSent = (int) ($result['activation_emails_sent'] ?? 0);
        $skippedCount = count((array) ($result['skipped_rows'] ?? []));

        $title = $created > 0
            ? "Onboarded {$created} ".Str::plural('student', $created)
            : ($updated > 0
                ? "Updated {$updated} unactivated ".Str::plural('profile', $updated)
                : 'No student records changed');

        $parts = [];
        if ($created > 0) {
            $parts[] = "{$created} new ".Str::plural('student', $created);
        }
        if ($updated > 0) {
            $parts[] = "{$updated} unactivated ".Str::plural('profile', $updated).' updated';
        }
        if ($priorRecorded > 0) {
            $parts[] = "{$priorRecorded} prior ".Str::plural('subject', $priorRecorded).' auto-credited';
        }
        if ($emailsSent > 0) {
            $parts[] = "{$emailsSent} activation ".Str::plural('email', $emailsSent).' sent';
        }
        if ($skippedCount > 0) {
            $parts[] = "{$skippedCount} ".Str::plural('row', $skippedCount).' skipped';
        }

        $notification = Notification::make()
            ->title($title)
            ->body($parts !== [] ? implode(' · ', $parts).'.' : 'Import finished.');

        if ($created > 0 || $updated > 0) {
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
