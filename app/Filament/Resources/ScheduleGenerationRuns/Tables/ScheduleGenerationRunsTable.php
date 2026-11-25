<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Tables;

use App\Models\ScheduleGenerationRun;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ScheduleGenerationRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll(fn (): ?string => ScheduleGenerationRun::query()
                ->whereIn('status', [ScheduleGenerationRun::StatusQueued, ScheduleGenerationRun::StatusDispatching])
                ->exists() ? '5s' : null)
            ->modifyQueryUsing(fn ($query) => $query
                ->select([
                    'schedule_runs.id',
                    'schedule_runs.term_id',
                    'schedule_runs.status',
                    'schedule_runs.candidate_state',
                    'schedule_runs.requested_by',
                    'schedule_runs.solver_version',
                    'schedule_runs.model_version',
                    'schedule_runs.runtime_ms',
                    'schedule_runs.created_at',
                    'schedule_runs.updated_at',
                    'diagnostic_flags.dispatch_status_flag',
                    'diagnostic_flags.solver_status_flag',
                ])
                ->joinSub(self::diagnosticFlags(), 'diagnostic_flags', 'diagnostic_flags.run_id', '=', 'schedule_runs.id')
                ->with(['term', 'requester'])
                ->withCount('candidateRows'))
            ->columns([
                TextColumn::make('term.label')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('result_summary')
                    ->label('Result')
                    ->state(fn (ScheduleGenerationRun $record): string => self::resultLabel($record))
                    ->badge()
                    ->color(fn (ScheduleGenerationRun $record): string => self::resultColor($record))
                    ->searchable(['status']),
                TextColumn::make('assignment_summary')
                    ->label('Assignments')
                    ->state(fn (ScheduleGenerationRun $record): string => "{$record->candidate_rows_count} assignments"),
                TextColumn::make('requester.name')
                    ->label('Requested By')
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->label('Requested at')
                    ->dateTime('M j, Y, g:i A')->timezone('Asia/Manila')
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
                    ->dateTime('M j, Y, g:i A')->timezone('Asia/Manila')
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
                TernaryFilter::make('hide_manual_adjustments')
                    ->label('Attempt scope')
                    ->placeholder('Hide superseded draft edits (default)')
                    ->trueLabel('Show all including draft edit snapshots')
                    ->falseLabel('Only manual draft edit snapshots')
                    ->default(null)
                    ->queries(
                        true: fn (Builder $query): Builder => $query,
                        false: fn (Builder $query): Builder => $query->where('diagnostic_flags.candidate_origin', 'LocalAdjustment'),
                        blank: fn (Builder $query): Builder => $query->where(function (Builder $inner): void {
                            $inner->whereNull('diagnostic_flags.candidate_origin')
                                ->orWhere('diagnostic_flags.candidate_origin', '!=', 'LocalAdjustment')
                                ->orWhere('status', '!=', ScheduleGenerationRun::StatusSuperseded);
                        }),
                    ),
            ])
            ->defaultSort('created_at', 'desc')
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filter')->color('gray')->labeledFrom(null))
            ->recordActions([
                ViewAction::make()
                    ->label('Review timetable')->button()->color('gray'),
            ])
            ->toolbarActions([])
            ->stackedOnMobile()
            ->emptyStateHeading('No generated timetables are recorded')
            ->emptyStateDescription('Prepare the Term inputs, then generate a timetable from Timetable.')
            ->emptyState(view('filament.partials.collection-empty-state'));
    }

    private static function diagnosticFlags(): Builder
    {
        // Keep large diagnostics off the table reference used for ordering and pagination.
        return ScheduleGenerationRun::query()
            ->select('id as run_id')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(diagnostics, '$.solver_dispatch.status')) as dispatch_status_flag")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(diagnostics, '$.solver_result.solver_status')) as solver_status_flag")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(diagnostics, '$.candidate_origin')) as candidate_origin");
    }

    private static function resultLabel(ScheduleGenerationRun $record): string
    {
        if ($record->status === ScheduleGenerationRun::StatusFailed
            && $record->getAttribute('dispatch_status_flag') === 'cancelled') {
            return 'Cancelled';
        }

        if ($record->candidate_state === 'Stale') {
            return 'Stale';
        }

        if ($record->candidate_state === 'Rejected') {
            return 'Discarded';
        }

        if ($record->status === ScheduleGenerationRun::StatusBlocked
            && $record->getAttribute('solver_status_flag') === 'infeasible') {
            return 'Infeasible';
        }

        return ScheduleGenerationRun::statusOptions()[$record->status] ?? str($record->status)->headline();
    }

    private static function resultColor(ScheduleGenerationRun $record): string
    {
        if ($record->status === ScheduleGenerationRun::StatusFailed
            && $record->getAttribute('dispatch_status_flag') === 'cancelled') {
            return 'gray';
        }

        if (in_array($record->candidate_state, ['Stale', 'Rejected'], true)) {
            return $record->candidate_state === 'Stale' ? 'warning' : 'gray';
        }

        return ScheduleGenerationRun::statusColors()[$record->status] ?? 'gray';
    }
}
