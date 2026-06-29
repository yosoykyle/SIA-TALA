<?php

namespace App\Filament\Resources\Enrollments\Schemas;

use App\Models\Enrollment;
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
                                TextEntry::make('studentProfile.student_number')
                                    ->label('Student No.'),
                                TextEntry::make('studentProfile.last_name')
                                    ->label('Name')
                                    ->state(fn (Enrollment $record): string => collect([
                                        $record->studentProfile?->last_name,
                                        $record->studentProfile?->first_name,
                                    ])->filter()->implode(', ')),
                                TextEntry::make('studentProfile.program.name')
                                    ->label('Program')
                                    ->placeholder('-'),
                                TextEntry::make('student_type')
                                    ->label('Student Type')
                                    ->badge()
                                    ->placeholder('-'),
                            ]),
                    ]),
                Section::make('Enrollment')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('term.term_name')
                                    ->label('Term'),
                                TextEntry::make('status')
                                    ->badge(),
                                TextEntry::make('registered_at')
                                    ->label('Registered At')
                                    ->dateTime()
                                    ->placeholder('-'),
                                TextEntry::make('officially_enrolled_at')
                                    ->label('Officially Enrolled At')
                                    ->dateTime()
                                    ->placeholder('-'),
                                TextEntry::make('cancelled_at')
                                    ->dateTime()
                                    ->placeholder('-'),
                                TextEntry::make('dropped_at')
                                    ->dateTime()
                                    ->placeholder('-'),
                                TextEntry::make('withdrawn_at')
                                    ->dateTime()
                                    ->placeholder('-'),
                                TextEntry::make('status_reason')
                                    ->columnSpanFull()
                                    ->placeholder('-'),
                            ]),
                    ]),
            ]);
    }
}
