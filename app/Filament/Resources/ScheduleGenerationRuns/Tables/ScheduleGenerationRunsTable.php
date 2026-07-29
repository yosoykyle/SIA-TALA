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
                TextColumn::make('result_summary')
                    ->label('Result')
                    ->state(fn (ScheduleGenerationRun $record): string => ScheduleGenerationRun::statusOptions()[$record->status] ?? str($record->status)->headline())
                    ->badge()
                    ->color(fn (ScheduleGenerationRun $record): string => ScheduleGenerationRun::statusColors()[$record->status] ?? 'gray')
                    ->searchable(),
                TextColumn::make('assignment_summary')
                    ->label('Assignments')
                    ->state(fn (ScheduleGenerationRun $record): string => "{$record->candidate_rows_count} candidate assignments"),
                TextColumn::make('requester.name')
                    ->label('Requested By')
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->label('Requested at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('solver_version')
                    ->label('Solver version')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('model_version')
                    ->label('Model version')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('runtime_ms')
                    ->label('Runtime ms')
                    ->numeric()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Last updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('term_id')
                    ->label('Term')
                    ->relationship('term', 'label')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->options(ScheduleGenerationRun::statusOptions()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make()
                    ->label('Review timetable'),
            ])
            ->toolbarActions([])
            ->stackedOnMobile()
            ->emptyStateHeading('No generated timetables are recorded')
            ->emptyStateDescription('Resolve every schedule requirement first, then request timetable generation from Class Planning.');
    }
}
