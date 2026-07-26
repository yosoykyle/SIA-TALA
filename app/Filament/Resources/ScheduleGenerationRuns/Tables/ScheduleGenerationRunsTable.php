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
            ->poll('5s')
            ->modifyQueryUsing(fn ($query) => $query
                ->select([
                    'schedule_runs.id',
                    'schedule_runs.term_id',
                    'schedule_runs.status',
                    'schedule_runs.requested_by',
                    'schedule_runs.solver_version',
                    'schedule_runs.model_version',
                    'schedule_runs.runtime_ms',
                    'schedule_runs.created_at',
                    'schedule_runs.updated_at',
                ])
                ->with(['term', 'requester'])
                ->withCount('candidateRows'))
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
