<?php

namespace App\Filament\Resources\AcademicYears\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AcademicYearInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Academic Year')
                ->schema([
                    TextEntry::make('label')->label('Academic Year'),
                    TextEntry::make('state')
                        ->badge()
                        ->formatStateUsing(fn (?string $state, $record): string => $record->statusLabel())
                        ->color(fn (?string $state): string => match ($state) {
                            'ACTIVE' => 'success',
                            'CLOSED' => 'warning',
                            'ARCHIVED' => 'danger',
                            default => 'gray',
                        }),
                    TextEntry::make('starts_on')->date(),
                    TextEntry::make('ends_on')->date(),
                    TextEntry::make('created_at')->dateTime()->placeholder('-'),
                    TextEntry::make('updated_at')->dateTime()->placeholder('-'),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
