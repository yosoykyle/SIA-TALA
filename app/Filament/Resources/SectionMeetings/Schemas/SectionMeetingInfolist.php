<?php

namespace App\Filament\Resources\SectionMeetings\Schemas;

use App\Models\SectionMeeting;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SectionMeetingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()->schema([
                    Section::make('Published Assignment')->contained(false)
                        ->schema([
                            TextEntry::make('scheduleRun.term.label')
                                ->label('Term'),
                            TextEntry::make('scheduleRun.publication_version')
                                ->label('Publication Version')
                                ->numeric(),
                            TextEntry::make('schedulingDemand.demand_key')
                                ->label('Scheduling Demand'),
                            TextEntry::make('schedulingDemand.sectionDeliveryGroup.section.code')
                                ->label('Section')
                                ->placeholder('-'),
                            TextEntry::make('schedulingDemand.courseComponent.component_type')
                                ->label('Component')
                                ->badge()
                                ->placeholder('-'),
                            TextEntry::make('meeting_sequence')
                                ->label('Meeting Sequence')
                                ->numeric(),
                            TextEntry::make('faculty.name')
                                ->label('Faculty'),
                            TextEntry::make('room.code')
                                ->label('Room')
                                ->placeholder('-'),
                            TextEntry::make('day_of_week')
                                ->label('Day')
                                ->formatStateUsing(fn (int $state): string => SectionMeeting::dayOptions()[$state] ?? '-'),
                            TextEntry::make('starts_at')
                                ->label('Start')
                                ->time('g:i A'),
                            TextEntry::make('ends_at')
                                ->label('End')
                                ->time('g:i A'),
                            TextEntry::make('modality')
                                ->badge()
                                ->formatStateUsing(fn (string $state): string => SectionMeeting::modalityOptions()[$state] ?? str($state)->headline()->toString()),
                            TextEntry::make('state')
                                ->badge()
                                ->color('success'),
                            TextEntry::make('published_at')
                                ->label('Published At')
                                ->dateTime('M j, Y, g:i A')->timezone('Asia/Manila'),
                            TextEntry::make('scheduleRun.publisher.name')
                                ->label('Published By')
                                ->placeholder('-'),
                            TextEntry::make('scheduleRun.publication_note')
                                ->label('Publication Note')
                                ->placeholder('-')
                                ->columnSpanFull(),
                        ])
                        ->columns(2),
                ])->columns(2)->divided()->columnSpanFull(),
            ]);
    }
}
