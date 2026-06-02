<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ScheduleGenerationRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('term_id')
                    ->numeric(),
                TextEntry::make('status'),
                TextEntry::make('requested_by')
                    ->numeric(),
                TextEntry::make('generated_at')
                    ->dateTime(),
                TextEntry::make('committed_by')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('committed_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('notes')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
