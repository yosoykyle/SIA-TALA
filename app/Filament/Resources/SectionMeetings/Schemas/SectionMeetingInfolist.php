<?php

namespace App\Filament\Resources\SectionMeetings\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class SectionMeetingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('term_id')
                    ->numeric(),
                TextEntry::make('section_id')
                    ->numeric(),
                TextEntry::make('subject_id')
                    ->numeric(),
                TextEntry::make('faculty_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('room')
                    ->placeholder('-'),
                TextEntry::make('day_of_week')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('starts_at')
                    ->time()
                    ->placeholder('-'),
                TextEntry::make('ends_at')
                    ->time()
                    ->placeholder('-'),
                TextEntry::make('modality'),
                TextEntry::make('schedule_generation_run_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('committed_by')
                    ->numeric(),
                TextEntry::make('committed_at')
                    ->dateTime(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
