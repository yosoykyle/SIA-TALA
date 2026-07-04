<?php

namespace App\Filament\Resources\ImportBatches;

use App\Actions\Imports\AcademicImportService;
use App\Models\ImportBatch;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

class ImportBatchDownloadActions
{
    public static function validationFindings(): Action
    {
        return Action::make('downloadValidationFindings')
            ->label('Download validation CSV')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->authorize('download')
            ->visible(fn (ImportBatch $record): bool => (int) $record->error_count > 0 || (int) $record->warning_count > 0)
            ->action(function (ImportBatch $record) {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $csv = app(AcademicImportService::class)->validationFindingsCsv($record, $actor);

                return response()->streamDownload(
                    fn () => print $csv,
                    "tala-import-{$record->id}-validation.csv",
                    ['Content-Type' => 'text/csv; charset=UTF-8'],
                );
            });
    }

    public static function source(): Action
    {
        return Action::make('downloadImportSource')
            ->label('Download source CSV')
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->color('gray')
            ->authorize('download')
            ->action(function (ImportBatch $record) {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $csv = app(AcademicImportService::class)->sourceCsv($record, $actor);

                return response()->streamDownload(
                    fn () => print $csv,
                    "tala-import-{$record->id}-source.csv",
                    ['Content-Type' => 'text/csv; charset=UTF-8'],
                );
            });
    }
}
