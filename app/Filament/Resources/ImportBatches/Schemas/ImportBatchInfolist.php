<?php

namespace App\Filament\Resources\ImportBatches\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ImportBatchInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('id')
                    ->label('ID'),
                TextEntry::make('import_type')
                    ->badge(),
                TextEntry::make('file_name'),
                TextEntry::make('file_path'),
                TextEntry::make('total_rows')
                    ->numeric(),
                TextEntry::make('valid_rows')
                    ->numeric(),
                TextEntry::make('error_rows')
                    ->numeric(),
                TextEntry::make('skipped_rows')
                    ->numeric(),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('imported_by')
                    ->numeric(),
                TextEntry::make('committed_by')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('committed_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
