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
                'schedulingDemand.courseComponent.courseSpecification.course',
                'schedulingDemand.sectionDeliveryGroup.section',
                'faculty',
                'room',
            ]))
            ->columns([
                TextColumn::make('course_and_section')
                    ->label('Class')
                    ->state(fn (SectionMeeting $record): string => collect([
                        $record->schedulingDemand?->courseComponent?->courseSpecification?->course?->code,
                        $record->schedulingDemand?->sectionDeliveryGroup?->section?->code,
                    ])->filter()->implode(' · '))
                    ->description(fn (SectionMeeting $record): string => $record->schedulingDemand?->courseComponent?->courseSpecification->title ?? 'Course title not recorded')
                    ->weight('bold')
                    ->wrap(),
                TextColumn::make('meeting_time')
                    ->label('Meeting time')
                    ->state(fn (SectionMeeting $record): string => collect([
                        SectionMeeting::dayOptions()[$record->day_of_week] ?? null,
                        filled($record->starts_at) && filled($record->ends_at)
                            ? mb_substr((string) $record->starts_at, 0, 5).' - '.mb_substr((string) $record->ends_at, 0, 5)
                            : null,
                    ])->filter()->implode(' · '))
                    ->wrap(),
                TextColumn::make('scheduleRun.term.label')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('faculty.name')
                    ->label('Faculty')
                    ->searchable(),
                TextColumn::make('room.code')
                    ->label('Room')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('modality')
                    ->label('Teaching mode')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => SectionMeeting::modalityOptions()[$state] ?? str($state)->headline()->toString()),
                TextColumn::make('scheduleRun.publication_version')
                    ->label('Published version')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('schedulingDemand.demand_key')
                    ->label('Technical requirement key')
                    ->searchable()
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('meeting_sequence')
                    ->label('Meeting sequence')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('published_at')
                    ->label('Published at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('published_at', 'desc')
            ->filters([
                SelectFilter::make('modality')
                    ->options(SectionMeeting::modalityOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([])
            ->stackedOnMobile()
            ->emptyStateHeading('No published timetable is available')
            ->emptyStateDescription('A generated timetable becomes official only after Registrar review, validation, and explicit publication.');
    }
}
