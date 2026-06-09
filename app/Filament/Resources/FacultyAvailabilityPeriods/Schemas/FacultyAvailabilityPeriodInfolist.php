<?php

namespace App\Filament\Resources\FacultyAvailabilityPeriods\Schemas;

use App\Models\FacultyAvailabilityPeriod;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FacultyAvailabilityPeriodInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Period Details')
                    ->schema([
                        TextEntry::make('term.term_name')
                            ->label('Term'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => FacultyAvailabilityPeriod::statusOptions()[$state] ?? '-'),
                        TextEntry::make('opens_at')
                            ->dateTime(),
                        TextEntry::make('closes_at')
                            ->dateTime(),
                        TextEntry::make('creator.name')
                            ->label('Created by')
                            ->placeholder('-'),
                        TextEntry::make('locked_at')
                            ->dateTime()
                            ->placeholder('-'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Submissions')
                    ->schema([
                        RepeatableEntry::make('submissions')
                            ->schema([
                                TextEntry::make('faculty.name')
                                    ->label('Faculty'),
                                TextEntry::make('status')
                                    ->badge()
                                    ->formatStateUsing(fn (?string $state): string => $state ?? '-'),
                                TextEntry::make('submitted_at')
                                    ->dateTime()
                                    ->placeholder('-'),
                                TextEntry::make('locked_at')
                                    ->dateTime()
                                    ->placeholder('-'),
                            ])
                            ->columns(4),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
