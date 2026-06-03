<?php

namespace App\Filament\Resources\SectionMeetings\Schemas;

use App\Models\SectionMeeting;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class SectionMeetingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('term.term_name')
                    ->label('Term'),
                TextEntry::make('section.name')
                    ->label('Section'),
                TextEntry::make('subject.code')
                    ->label('Subject'),
                TextEntry::make('subject.description')
                    ->label('Subject Description')
                    ->placeholder('-'),
                TextEntry::make('faculty.name')
                    ->label('Faculty')
                    ->placeholder('-'),
                TextEntry::make('room')
                    ->placeholder('-'),
                TextEntry::make('day_of_week')
                    ->label('Day')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '-' : (SectionMeeting::dayOptions()[$state] ?? '-'))
                    ->placeholder('-'),
                TextEntry::make('starts_at')
                    ->label('Start')
                    ->time()
                    ->placeholder('-'),
                TextEntry::make('ends_at')
                    ->label('End')
                    ->time()
                    ->placeholder('-'),
                TextEntry::make('modality')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (SectionMeeting::modalityOptions()[$state] ?? str($state)->replace('_', ' ')->headline()->toString())),
                TextEntry::make('scheduleGenerationRun.id')
                    ->label('Draft Run')
                    ->placeholder('Manual assignment'),
                TextEntry::make('committer.name')
                    ->label('Committed By'),
                TextEntry::make('committed_at')
                    ->label('Committed At')
                    ->dateTime(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
