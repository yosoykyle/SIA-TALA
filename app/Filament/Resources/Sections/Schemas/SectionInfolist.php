<?php

namespace App\Filament\Resources\Sections\Schemas;

use App\Models\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class SectionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('term.term_name')
                    ->label('Term'),
                TextEntry::make('program.name')
                    ->label('Program'),
                TextEntry::make('curriculum.version_name')
                    ->label('Curriculum')
                    ->placeholder('-'),
                TextEntry::make('year_level')
                    ->label('Year / Grade')
                    ->placeholder('-'),
                TextEntry::make('curriculum_period')
                    ->label('Curriculum Period')
                    ->placeholder('-'),
                TextEntry::make('name')
                    ->label('Section Name'),
                TextEntry::make('modality')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (Section::modalityOptions()[$state] ?? str($state)->replace('_', ' ')->headline()->toString())),
                TextEntry::make('room')
                    ->label('Fixed Room')
                    ->placeholder('-'),
                TextEntry::make('max_seats')
                    ->numeric(),
                TextEntry::make('enrolled_count')
                    ->numeric(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
