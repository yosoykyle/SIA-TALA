<?php

namespace App\Filament\Resources\AcademicCalendarWindows\Tables;

use App\Models\CalendarEvent;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AcademicCalendarWindowsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('term.label')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('process_key')
                    ->label('Process')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => CalendarEvent::academicCalendarWindowProcessOptions()[$state] ?? str($state)->headline()->toString()),
                TextColumn::make('start_at')
                    ->label('Opens')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('end_at')
                    ->label('Closes')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('state')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => CalendarEvent::stateOptions()[$state] ?? str($state)->headline()->toString()),
                TextColumn::make('authority')
                    ->label('Authority / Reference')
                    ->searchable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('term_id')
                    ->label('Term')
                    ->relationship('term', 'label'),
                SelectFilter::make('process_key')
                    ->label('Process')
                    ->options(CalendarEvent::academicCalendarWindowProcessOptions()),
                SelectFilter::make('state')
                    ->options(CalendarEvent::stateOptions()),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([])
            ->defaultSort('start_at');
    }
}
