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
                    TextEntry::make('academic_year')->label('Academic Year'),
                    TextEntry::make('education_level')
                        ->label('Education Level')
                        ->badge()
                        ->formatStateUsing(fn (?string $state, $record): string => $record->educationLevelLabel()),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (?string $state, $record): string => $record->statusLabel())
                        ->color(fn (?string $state): string => match ($state) {
                            'active' => 'success',
                            'closed' => 'warning',
                            'archived' => 'danger',
                            default => 'gray',
                        }),
                    TextEntry::make('school_year_start_date')->date(),
                    TextEntry::make('school_year_end_date')->date(),
                    TextEntry::make('reference_note')->placeholder('-')->columnSpanFull(),
                    TextEntry::make('created_at')->dateTime()->placeholder('-'),
                    TextEntry::make('updated_at')->dateTime()->placeholder('-'),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
