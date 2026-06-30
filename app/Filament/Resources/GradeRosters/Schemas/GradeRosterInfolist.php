<?php

namespace App\Filament\Resources\GradeRosters\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class GradeRosterInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Roster')
                ->schema([
                    TextEntry::make('termOffering.term.label')->label('Term'),
                    TextEntry::make('section.code')->label('Section'),
                    TextEntry::make('faculty.name')->label('Faculty'),
                    TextEntry::make('state')->badge(),
                    TextEntry::make('submitted_at')->dateTime(),
                    TextEntry::make('released_at')->dateTime(),
                    TextEntry::make('return_reason')->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }
}
