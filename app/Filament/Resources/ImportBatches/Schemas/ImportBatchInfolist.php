<?php

namespace App\Filament\Resources\ImportBatches\Schemas;

use App\Models\ImportBatch;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
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
                        TextEntry::make('type')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => ImportBatch::importTypeOptions()[$state] ?? str((string) $state)->headline()->toString()),
                        TextEntry::make('template_version'),
                        TextEntry::make('state')
                            ->badge()
                            ->color(fn (?string $state): string => ImportBatch::statusColors()[$state] ?? 'gray')
                            ->formatStateUsing(fn (?string $state): string => ImportBatch::statusOptions()[$state] ?? str((string) $state)->headline()->toString()),
                        TextEntry::make('source_path')
                            ->label('Private Source Path'),
                        TextEntry::make('uploader.name')
                            ->label('Uploaded By')
                            ->placeholder('-'),
                        TextEntry::make('acknowledged_at')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('posted_at')
                            ->dateTime()
                            ->placeholder('-'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Preview Summary')
                    ->schema([
                        TextEntry::make('row_count')
                            ->numeric(),
                        TextEntry::make('error_count')
                            ->numeric(),
                        TextEntry::make('warning_count')
                            ->numeric(),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
                Section::make('Complete Row Preview')
                    ->description('Every populated source row is shown with its original CSV row number and values.')
                    ->schema([
                        RepeatableEntry::make('preview_rows')
                            ->hiddenLabel()
                            ->state(fn (ImportBatch $record): array => self::detailList($record, 'rows'))
                            ->schema([
                                TextEntry::make('row')
                                    ->label('Source Row')
                                    ->numeric(),
                                TextEntry::make('status')
                                    ->badge()
                                    ->color(fn (?string $state): string => match ($state) {
                                        'ERROR' => 'danger',
                                        'WARNING' => 'warning',
                                        'VALID' => 'success',
                                        default => 'gray',
                                    }),
                                TextEntry::make('errors')
                                    ->listWithLineBreaks()
                                    ->bulleted()
                                    ->placeholder('No row errors.'),
                                TextEntry::make('warnings')
                                    ->listWithLineBreaks()
                                    ->bulleted()
                                    ->placeholder('No row warnings.'),
                                KeyValueEntry::make('values')
                                    ->label('Source Values')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make('Validation Errors')
                    ->schema([
                        self::findingEntry('errors', 'No validation errors.'),
                    ])
                    ->visible(fn (ImportBatch $record): bool => $record->error_count > 0)
                    ->columnSpanFull(),
                Section::make('Validation Warnings')
                    ->schema([
                        self::findingEntry('warnings', 'No validation warnings.'),
                    ])
                    ->visible(fn (ImportBatch $record): bool => $record->warning_count > 0)
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

    private static function findingEntry(string $key, string $emptyText): RepeatableEntry
    {
        return RepeatableEntry::make($key)
            ->hiddenLabel()
            ->state(fn (ImportBatch $record): array => self::detailList($record, $key))
            ->schema([
                TextEntry::make('row')
                    ->label('Source Row')
                    ->formatStateUsing(fn (mixed $state): string => $state === null ? 'Batch' : (string) $state),
                TextEntry::make('message')
                    ->columnSpanFull(),
                KeyValueEntry::make('values')
                    ->label('Source Values')
                    ->columnSpanFull(),
            ])
            ->columns(2)
            ->placeholder($emptyText)
            ->columnSpanFull();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function detailList(ImportBatch $record, string $key): array
    {
        $rawDetails = $record->getAttribute('validation_details');
        $details = is_array($rawDetails) ? $rawDetails : [];
        $items = $details[$key] ?? [];

        return is_array($items)
            ? collect($items)->filter(fn (mixed $item): bool => is_array($item))->values()->all()
            : [];
    }
}
