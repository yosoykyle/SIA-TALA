<?php

namespace App\Filament\Resources\CalendarEvents\Tables;

use App\Models\CalendarEvent;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CalendarEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('term.label')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('event_type')
                    ->label('Block Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => CalendarEvent::recurringBlockTypeOptions()[$state] ?? str($state)->headline()->toString()),
                TextColumn::make('scope_type')
                    ->label('Scope')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => CalendarEvent::recurringBlockScopeOptions()[$state] ?? str($state)->headline()->toString()),
                TextColumn::make('faculty.name')
                    ->label('Faculty')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('room.code')
                    ->label('Room')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('day_of_week')
                    ->label('Day')
                    ->formatStateUsing(fn (int $state): string => CalendarEvent::dayOptions()[$state] ?? '-'),
                TextColumn::make('starts_at')
                    ->label('Start'),
                TextColumn::make('ends_at')
                    ->label('End'),
                TextColumn::make('state')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => CalendarEvent::stateOptions()[$state] ?? str($state)->headline()->toString()),
                TextColumn::make('authority')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('term_id')
                    ->label('Term')
                    ->relationship('term', 'label'),
                SelectFilter::make('event_type')
                    ->label('Block Type')
                    ->options(CalendarEvent::recurringBlockTypeOptions()),
                SelectFilter::make('scope_type')
                    ->label('Scope')
                    ->options(CalendarEvent::recurringBlockScopeOptions()),
                SelectFilter::make('state')
                    ->options(CalendarEvent::stateOptions()),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([])
            ->defaultSort('day_of_week');
    }
}
