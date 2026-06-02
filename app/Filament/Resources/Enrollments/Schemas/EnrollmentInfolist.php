<?php

namespace App\Filament\Resources\Enrollments\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EnrollmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Student')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('studentProfile.student_id')
                                    ->label('Student ID'),
                                TextEntry::make('studentProfile.user.name')
                                    ->label('Name'),
                                TextEntry::make('studentProfile.program.name')
                                    ->label('Program')
                                    ->placeholder('-'),
                                TextEntry::make('studentProfile.education_level')
                                    ->label('Education Level')
                                    ->badge()
                                    ->formatStateUsing(fn (?string $state): string => strtoupper((string) $state)),
                                TextEntry::make('student_type')
                                    ->label('Student Type')
                                    ->badge()
                                    ->placeholder('-'),
                                TextEntry::make('year_level')
                                    ->label('Year/Grade')
                                    ->placeholder('-'),
                            ]),
                    ]),
                Section::make('Enrollment')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('term.term_name')
                                    ->label('Term'),
                                TextEntry::make('section.name')
                                    ->label('Section')
                                    ->placeholder('-'),
                                TextEntry::make('status')
                                    ->badge(),
                                TextEntry::make('lis_status')
                                    ->label('LIS Status')
                                    ->badge(),
                                IconEntry::make('is_late_enrollment')
                                    ->label('Late Enrollment')
                                    ->boolean(),
                                IconEntry::make('studentProfile.hard_copy_received')
                                    ->label('Hard Copy Received')
                                    ->boolean(),
                            ]),
                    ]),
                Section::make('Finance')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('studentProfile.current_balance')
                                    ->label('Current Balance')
                                    ->money('PHP'),
                                TextEntry::make('enrolled_at')
                                    ->dateTime()
                                    ->placeholder('-'),
                                TextEntry::make('pre_enrolled_at')
                                    ->dateTime()
                                    ->placeholder('-'),
                            ]),
                    ]),
            ]);
    }
}
