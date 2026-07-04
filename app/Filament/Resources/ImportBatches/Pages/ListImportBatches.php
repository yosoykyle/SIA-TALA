<?php

namespace App\Filament\Resources\ImportBatches\Pages;

use App\Actions\Imports\AcademicImportService;
use App\Actions\Imports\CourseSpecificationImportService;
use App\Actions\Imports\CourseSpecificationImportTemplate;
use App\Actions\Imports\CurriculumImportService;
use App\Actions\Imports\CurriculumImportTemplate;
use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Models\ImportBatch;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Throwable;

class ListImportBatches extends ListRecords
{
    protected static string $resource = ImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->downloadTemplateAction(
                name: 'downloadCourseSpecificationTemplate',
                label: 'Download course spec template',
                filename: 'tala-course-specification-import-template.csv',
                csv: CourseSpecificationImportTemplate::csv(),
            ),
            $this->downloadTemplateAction(
                name: 'downloadCurriculumTemplate',
                label: 'Download curriculum template',
                filename: 'tala-curriculum-import-template.csv',
                csv: CurriculumImportTemplate::csv(),
            ),
            $this->uploadAction(
                name: 'uploadCourseSpecificationImport',
                label: 'Upload course spec import',
                type: ImportBatch::TypeCourseSpecification,
                directory: CourseSpecificationImportService::uploadContract()['directory'],
            ),
            $this->uploadAction(
                name: 'uploadCurriculumImport',
                label: 'Upload curriculum import',
                type: ImportBatch::TypeCurriculum,
                directory: CurriculumImportService::uploadContract()['directory'],
            ),
        ];
    }

    private function downloadTemplateAction(string $name, string $label, string $filename, string $csv): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->authorize('manage')
            ->visible(fn (): bool => self::canManageAcademicImports())
            ->action(fn () => response()->streamDownload(
                fn () => print $csv,
                $filename,
                ['Content-Type' => 'text/csv; charset=UTF-8'],
            ));
    }

    private function uploadAction(string $name, string $label, string $type, string $directory): Action
    {
        $contract = AcademicImportService::uploadContract($type);

        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('primary')
            ->schema([
                FileUpload::make('file')
                    ->label('Completed CSV template')
                    ->disk(AcademicImportService::Disk)
                    ->directory($directory)
                    ->visibility('private')
                    ->acceptedFileTypes($contract['accepted_file_types'])
                    ->maxSize($contract['max_size_kb'])
                    ->required()
                    ->helperText('Upload the strict CSV template. TALA creates a preview batch first; errors block Draft creation, and warnings must be acknowledged.'),
            ])
            ->modalHeading($label)
            ->modalSubmitActionLabel('Create preview')
            ->authorize('manage')
            ->visible(fn (): bool => self::canManageAcademicImports())
            ->action(fn (array $data) => $this->createPreview($type, $data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createPreview(string $type, array $data): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        $path = $data['file'] ?? null;

        if (is_array($path)) {
            $path = collect($path)->first();
        }

        if (! is_string($path) || blank($path)) {
            Notification::make()
                ->title('Import upload failed')
                ->body('No CSV import file was uploaded.')
                ->danger()
                ->send();

            return;
        }

        try {
            $batch = app(AcademicImportService::class)->createPreview($type, $path, $actor);

            Notification::make()
                ->title('Import preview created')
                ->body("Rows: {$batch->row_count}; errors: {$batch->error_count}; warnings: {$batch->warning_count}.")
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Import upload failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    private static function canManageAcademicImports(): bool
    {
        return auth()->user()?->can('manage', ImportBatch::class) ?? false;
    }
}
