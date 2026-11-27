<?php

namespace App\Filament\Resources\Courses\Pages;

use App\Actions\AcademicSetup\CurriculumSpreadsheetExportService;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\CatalogCurriculaWorkbench;
use App\Filament\Resources\Courses\CourseResource;
use App\Models\Course;
use App\Models\User;
use App\Support\CurriculumSpreadsheetWizard;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListCourses extends ListRecords
{
    protected static string $resource = CourseResource::class;

    #[Url]
    public ?int $oversightTermId = null;

    #[Locked]
    public ?string $lastSkippedImportCsvPath = null;

    public function getTitle(): string
    {
        return 'Subject catalog';
    }

    public function getSubheading(): string
    {
        return 'Subjects available to use in program curricula.';
    }

    public function getBreadcrumbs(): array
    {
        return [
            CatalogCurriculaWorkbench::getUrl(array_filter([
                'oversightTermId' => $this->oversightTermId,
            ], fn (mixed $value): bool => $value !== null)) => 'Programs & Curricula',
            'Subject catalog',
        ];
    }

    /**
     * @return array<Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        $oversightParameters = array_filter([
            'termId' => $this->oversightTermId,
        ], fn (mixed $value): bool => $value !== null);

        $canImport = auth()->user()?->can('create', Course::class) ?? false;

        return [
            Action::make('returnToOversight')
                ->label('Return to Academic Oversight')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(AcademicApprovals::getUrl($oversightParameters))
                ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleAcademicHead) ?? false),
            ActionGroup::make([
                $this->importSubjectsAction(),
                $this->exportXlsxAction(),
                $this->exportCsvAction(),
                $this->downloadXlsxTemplateAction()
                    ->visible(fn (): bool => auth()->user()?->can('create', Course::class) ?? false),
                $this->downloadCsvTemplateAction()
                    ->visible(fn (): bool => auth()->user()?->can('create', Course::class) ?? false),
            ])
                ->label($canImport ? 'Import / Export' : 'Export')
                ->icon($canImport ? Heroicon::OutlinedArrowsUpDown : Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->button()
                ->visible(fn (): bool => auth()->user()?->can('viewAny', Course::class) ?? false),
        ];
    }

    public function importSubjectsAction(): Action
    {
        return CurriculumSpreadsheetWizard::makeCatalogImportAction(
            onSkippedCsvGenerated: function (string $path): void {
                $this->lastSkippedImportCsvPath = $path;
            },
        )->url(fn (): string => CourseResource::getUrl('import', array_filter([
            'oversightTermId' => $this->oversightTermId,
        ], fn (mixed $value): bool => $value !== null)));
    }

    public function exportXlsxAction(): Action
    {
        return Action::make('exportXlsx')
            ->label('Export as Excel (.xlsx)')
            ->icon(Heroicon::OutlinedTableCells)
            ->authorize(fn (): bool => auth()->user()?->can('viewAny', Course::class) ?? false)
            ->visible(fn (): bool => auth()->user()?->can('viewAny', Course::class) ?? false)
            ->action(fn (): BinaryFileResponse => $this->exportCatalogSubjects('xlsx'));
    }

    public function exportCsvAction(): Action
    {
        return Action::make('exportCsv')
            ->label('Export as CSV (.csv)')
            ->icon(Heroicon::OutlinedDocumentText)
            ->authorize(fn (): bool => auth()->user()?->can('viewAny', Course::class) ?? false)
            ->visible(fn (): bool => auth()->user()?->can('viewAny', Course::class) ?? false)
            ->action(fn (): BinaryFileResponse => $this->exportCatalogSubjects('csv'));
    }

    public function exportSubjectsXlsxAction(): Action
    {
        return Action::make('exportSubjectsXlsx')
            ->label('Export as Excel (.xlsx)')
            ->authorize(fn (): bool => auth()->user()?->can('viewAny', Course::class) ?? false)
            ->action(fn (): BinaryFileResponse => $this->exportCatalogSubjects('xlsx'));
    }

    public function exportSubjectsCsvAction(): Action
    {
        return Action::make('exportSubjectsCsv')
            ->label('Export as CSV (.csv)')
            ->authorize(fn (): bool => auth()->user()?->can('viewAny', Course::class) ?? false)
            ->action(fn (): BinaryFileResponse => $this->exportCatalogSubjects('csv'));
    }

    public function downloadXlsxTemplateAction(): Action
    {
        return CurriculumSpreadsheetWizard::makeTemplateDownloadAction(
            name: 'downloadXlsxTemplate',
            scope: 'subjects',
            format: 'xlsx',
            label: 'Download XLSX template',
        );
    }

    public function downloadCsvTemplateAction(): Action
    {
        return CurriculumSpreadsheetWizard::makeTemplateDownloadAction(
            name: 'downloadCsvTemplate',
            scope: 'subjects',
            format: 'csv',
            label: 'Download CSV template',
        );
    }

    public function downloadTemplateAction(): Action
    {
        return Action::make('downloadTemplate')
            ->label('Download template')
            ->color('gray')
            ->action(fn (array $arguments): BinaryFileResponse => $this->downloadTemplate((string) ($arguments['format'] ?? 'xlsx')));
    }

    public function downloadTemplate(string $format = 'xlsx'): BinaryFileResponse
    {
        $exportService = app(CurriculumSpreadsheetExportService::class);
        $path = $exportService->exportTemplate('subjects', $format);

        return $exportService->downloadAndDelete($path, basename($path), $format);
    }

    public function downloadSkippedRowsCsvAction(): Action
    {
        return Action::make('downloadSkippedRowsCsv')
            ->label('Download skipped rows (.csv)')
            ->color('gray')
            ->visible(fn (): bool => (is_string($this->lastSkippedImportCsvPath) && is_file($this->lastSkippedImportCsvPath))
                || (is_string(session('tala.skipped_import_csv_path')) && is_file((string) session('tala.skipped_import_csv_path'))))
            ->action(fn (): ?BinaryFileResponse => $this->downloadSkippedRowsCsv());
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

    public function exportCatalogSubjects(string $format = 'xlsx'): BinaryFileResponse
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        Gate::forUser($actor)->authorize('viewAny', Course::class);

        $courses = $this->resolveCoursesForExport();
        $exportService = app(CurriculumSpreadsheetExportService::class);
        $path = $exportService->exportSubjects($courses, $format);

        return $exportService->downloadAndDelete($path, basename($path), $format);
    }

    /**
     * @return Collection<int, Course>
     */
    private function resolveCoursesForExport(): Collection
    {
        try {
            $query = $this->getFilteredSortedTableQuery() ?? $this->getFilteredTableQuery();

            if ($query !== null) {
                return $query
                    ->with(['specifications.components', 'specifications.requirements.relatedCourse'])
                    ->get();
            }
        } catch (\Throwable) {
            // Fall back to direct ordered query if table has not been initialized yet.
        }

        return Course::query()
            ->orderBy('code')
            ->with(['specifications.components', 'specifications.requirements.relatedCourse'])
            ->get();
    }
}
