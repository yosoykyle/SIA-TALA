<?php

namespace App\Filament\Resources\Activities\Schemas;

use App\Support\ActivityPropertiesFormatter;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Spatie\Activitylog\Models\Activity;

class ActivityInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('log_name')
                    ->label('Log'),
                TextEntry::make('event')
                    ->badge(),
                TextEntry::make('description')
                    ->columnSpanFull(),
                TextEntry::make('subject_type')
                    ->label('Subject type'),
                TextEntry::make('subject_id')
                    ->label('Subject ID'),
                TextEntry::make('causer.email')
                    ->label('Actor')
                    ->placeholder('System'),
                TextEntry::make('properties')
                    ->label('Audit metadata')
                    ->state(fn (Activity $record): array => ActivityPropertiesFormatter::lines($record->properties))
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime(),
            ]);
    }
}
