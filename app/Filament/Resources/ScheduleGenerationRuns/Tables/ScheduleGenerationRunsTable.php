<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Tables;

use App\Models\ScheduleGenerationRun;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ScheduleGenerationRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['term', 'requester'])->withCount('candidateRows'))
            ->columns([
                TextColumn::make('term.label')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => ScheduleGenerationRun::statusColors()[$state] ?? 'gray')
                    ->searchable(),
                TextColumn::make('candidate_rows_count')
                    ->label('Candidate Rows')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('requester.name')
                    ->label('Requested By')
                    ->placeholder('-'),
                TextColumn::make('solver_version')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('model_version')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('runtime_ms')
                    ->label('Runtime ms')
                    ->numeric()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ScheduleGenerationRun::statusOptions()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
