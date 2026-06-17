<?php

namespace App\Filament\Resources\ImportBatches\Pages;

use App\Actions\Imports\CurriculumImportService;
use App\Actions\Imports\CurriculumImportTemplate;
use App\Filament\Resources\ImportBatches\ImportBatchResource;
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
            Action::make('downloadCurriculumTemplate')
                ->label('Download curriculum template')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn (): bool => self::canManageCurriculumImports())
                ->action(fn () => response()->streamDownload(
                    fn () => print CurriculumImportTemplate::csv(),
                    'tala-curriculum-import-template.csv',
                    ['Content-Type' => 'text/csv; charset=UTF-8'],
                )),
            Action::make('uploadCurriculumImport')
                ->label('Upload curriculum import')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('primary')
                ->schema([
                    FileUpload::make('file')
                        ->label('Completed curriculum template')
                        ->disk('local')
                        ->directory('imports/curriculum/uploads')
                        ->visibility('private')
                        ->acceptedFileTypes(CurriculumImportService::uploadContract()['accepted_file_types'])
                        ->maxSize(CurriculumImportService::uploadContract()['max_size_kb'])
                        ->required()
                        ->helperText('Upload the strict curriculum CSV/XLSX template. The system creates a preview batch; it does not commit rows until the batch has zero validation errors and is explicitly committed.'),
                ])
                ->modalHeading('Upload curriculum import')
                ->modalSubmitActionLabel('Create preview')
                ->visible(fn (): bool => self::canManageCurriculumImports())
                ->action(fn (array $data) => $this->createPreview($data)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createPreview(array $data): void
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
                ->body('No curriculum import file was uploaded.')
                ->danger()
                ->send();

            return;
        }

        try {
            $batch = app(CurriculumImportService::class)->createPreview($path, basename($path), $actor);

            Notification::make()
                ->title('Curriculum import preview created')
                ->body("Valid rows: {$batch->valid_rows}; errors: {$batch->error_rows}.")
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

    private static function canManageCurriculumImports(): bool
    {
        return auth()->user()?->can('manage-curricula') ?? false;
    }
}
