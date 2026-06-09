<?php

namespace App\Filament\Resources\FacultyAvailabilitySubmissions\Schemas;

use App\Models\FacultyAvailabilitySubmission;
use App\Models\SectionMeeting;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FacultyAvailabilitySubmissionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Submission Details')
                    ->schema([
                        TextEntry::make('term.term_name')
                            ->label('Term'),
                        TextEntry::make('faculty.name')
                            ->label('Faculty'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => FacultyAvailabilitySubmission::statusOptions()[$state] ?? '-'),
                        TextEntry::make('version')
                            ->numeric(),
                        TextEntry::make('submitted_at')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('locked_at')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('approver.name')
                            ->label('Locked by')
                            ->placeholder('-'),
                        TextEntry::make('approved_at')
                            ->dateTime()
                            ->placeholder('-'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Availability Windows')
                    ->schema([
                        RepeatableEntry::make('windows')
                            ->schema([
                                TextEntry::make('day_of_week')
                                    ->label('Day')
                                    ->formatStateUsing(fn (?int $state): string => $state === null ? '-' : (SectionMeeting::dayOptions()[$state] ?? '-')),
                                TextEntry::make('starts_at')
                                    ->label('Start'),
                                TextEntry::make('ends_at')
                                    ->label('End'),
                                TextEntry::make('notes')
                                    ->placeholder('-'),
                            ])
                            ->columns(4),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
