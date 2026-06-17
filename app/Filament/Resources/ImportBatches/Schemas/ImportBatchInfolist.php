<?php

namespace App\Filament\Resources\ImportBatches\Schemas;

use App\Models\ImportBatch;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ImportBatchInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Import Batch')
                    ->schema([
                        TextEntry::make('id')
                            ->label('Batch ID')
                            ->copyable(),
                        TextEntry::make('import_type')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => ImportBatch::importTypeOptions()[$state] ?? str((string) $state)->headline()->toString()),
                        TextEntry::make('file_name')
                            ->label('Uploaded File'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => ImportBatch::statusOptions()[$state] ?? str((string) $state)->headline()->toString()),
                        TextEntry::make('importer.name')
                            ->label('Uploaded By')
                            ->placeholder('-'),
                        TextEntry::make('committer.name')
                            ->label('Committed By')
                            ->placeholder('-'),
                        TextEntry::make('committed_at')
                            ->dateTime()
                            ->placeholder('-'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Preview Summary')
                    ->schema([
                        TextEntry::make('total_rows')
                            ->numeric(),
                        TextEntry::make('valid_rows')
                            ->numeric(),
                        TextEntry::make('error_rows')
                            ->numeric(),
                        TextEntry::make('skipped_rows')
                            ->numeric(),
                        TextEntry::make('preview_summary')
                            ->label('Validation Preview')
                            ->state(fn (ImportBatch $record): string => self::previewSummary($record))
                            ->columnSpanFull(),
                    ])
                    ->columns(4)
                    ->columnSpanFull(),
                Section::make('Timeline')
                    ->schema([
                        TextEntry::make('created_at')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('updated_at')
                            ->dateTime()
                            ->placeholder('-'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    private static function previewSummary(ImportBatch $record): string
    {
        $errorLog = $record->error_log ?? [];
        $errors = collect($errorLog['errors'] ?? [])
            ->take(10)
            ->map(function (array $error): string {
                $messages = implode('; ', $error['messages'] ?? []);

                return 'Row '.($error['row'] ?? '?').': '.$messages;
            });

        if ($errors->isEmpty()) {
            $commitSummary = $errorLog['commit_summary'] ?? null;

            if (is_array($commitSummary)) {
                return 'Ready/committed with '.$commitSummary['committed_rows'].' committed row(s).';
            }

            return 'No validation errors. Ready to commit.';
        }

        return $errors->implode("\n");
    }
}
