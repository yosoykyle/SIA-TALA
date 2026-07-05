<?php

namespace App\Filament\Resources\FacultyTermLoadOverrides\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FacultyTermLoadOverrideInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Approved Term Load Override')
                    ->schema([
                        TextEntry::make('faculty.name')->label('Faculty'),
                        TextEntry::make('term.label')->label('Term'),
                        TextEntry::make('default_max_units_snapshot')
                            ->label('Default Max Units')
                            ->numeric(decimalPlaces: 2),
                        TextEntry::make('approved_overload_units')
                            ->label('Approved Overload')
                            ->numeric(decimalPlaces: 2),
                        TextEntry::make('authority'),
                        IconEntry::make('is_active')->label('Active')->boolean(),
                        TextEntry::make('reason')->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
