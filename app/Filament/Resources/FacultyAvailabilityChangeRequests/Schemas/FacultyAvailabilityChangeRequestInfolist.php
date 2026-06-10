<?php

namespace App\Filament\Resources\FacultyAvailabilityChangeRequests\Schemas;

use App\Models\FacultyAvailabilityChangeRequest;
use App\Models\SectionMeeting;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FacultyAvailabilityChangeRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Request Details')
                    ->schema([
                        TextEntry::make('term.term_name')
                            ->label('Term'),
                        TextEntry::make('faculty.name')
                            ->label('Faculty'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => FacultyAvailabilityChangeRequest::statusOptions()[$state] ?? '-')
                            ->color(fn (?string $state): string => match ($state) {
                                FacultyAvailabilityChangeRequest::StatusPending => 'warning',
                                FacultyAvailabilityChangeRequest::StatusApproved => 'success',
                                FacultyAvailabilityChangeRequest::StatusRejected => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('submission.version')
                            ->label('Source Version')
                            ->numeric(),
                        TextEntry::make('createdSubmission.version')
                            ->label('Created Version')
                            ->placeholder('-')
                            ->numeric(),
                        TextEntry::make('requester.name')
                            ->label('Requested By'),
                        TextEntry::make('reviewer.name')
                            ->label('Reviewed By')
                            ->placeholder('-'),
                        TextEntry::make('reviewed_at')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('reason')
                            ->columnSpanFull(),
                        TextEntry::make('review_note')
                            ->label('Review Note')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Requested Windows')
                    ->schema([
                        RepeatableEntry::make('requested_windows')
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
                Section::make('Original Locked Windows')
                    ->schema([
                        RepeatableEntry::make('source_windows')
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
