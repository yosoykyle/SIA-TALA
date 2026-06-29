<?php

namespace App\Filament\Resources\SectionMeetings\Tables;

use App\Models\SectionMeeting;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SectionMeetingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with([
                'scheduleRun.term',
                'schedulingDemand.courseComponent',
                'schedulingDemand.sectionDeliveryGroup.section',
                'faculty',
                'room',
            ]))
            ->columns([
                TextColumn::make('scheduleRun.term.label')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('scheduleRun.publication_version')
                    ->label('Version')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('schedulingDemand.demand_key')
                    ->label('Demand')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('schedulingDemand.sectionDeliveryGroup.section.code')
                    ->label('Section')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('schedulingDemand.courseComponent.component_type')
                    ->label('Component')
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('meeting_sequence')
                    ->label('Meeting')
                    ->numeric(),
                TextColumn::make('faculty.name')
                    ->label('Faculty')
                    ->searchable(),
                TextColumn::make('room.code')
                    ->label('Room')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('day_of_week')
                    ->label('Day')
                    ->formatStateUsing(fn (int $state): string => SectionMeeting::dayOptions()[$state] ?? '-'),
                TextColumn::make('starts_at')
                    ->label('Start'),
                TextColumn::make('ends_at')
                    ->label('End'),
                TextColumn::make('modality')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => SectionMeeting::modalityOptions()[$state] ?? str($state)->headline()->toString()),
                TextColumn::make('published_at')
                    ->label('Published At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('published_at', 'desc')
            ->filters([
                SelectFilter::make('modality')
                    ->options(SectionMeeting::modalityOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
