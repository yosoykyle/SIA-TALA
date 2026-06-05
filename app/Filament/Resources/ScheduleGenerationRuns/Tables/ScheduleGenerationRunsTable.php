<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Tables;

use App\Actions\Scheduling\ScheduleCommitService;
use App\Models\ScheduleGenerationRun;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class ScheduleGenerationRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['term', 'requester', 'committer'])->withCount('sectionMeetings'))
            ->columns([
                TextColumn::make('term.term_name')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->colors(ScheduleGenerationRun::statusColors())
                    ->searchable(),
                TextColumn::make('section_meetings_count')
                    ->label('Committed Meetings')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('requester.name')
                    ->label('Requested By')
                    ->placeholder('-'),
                TextColumn::make('generated_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('committer.name')
                    ->label('Committed By')
                    ->placeholder('-'),
                TextColumn::make('committed_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ScheduleGenerationRun::statusOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                self::commitAction(),
            ])
            ->toolbarActions([]);
    }

    private static function commitAction(): Action
    {
        return Action::make('commitSchedule')
            ->label('Commit')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (ScheduleGenerationRun $record): bool => self::registrarCanSchedule()
                && $record->canBeCommitted())
            ->action(function (ScheduleGenerationRun $record): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                try {
                    app(ScheduleCommitService::class)->commit($record, $actor);

                    Notification::make()
                        ->title('Schedule run committed')
                        ->success()
                        ->send();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Schedule commit failed')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    private static function registrarCanSchedule(): bool
    {
        return auth()->user()?->can('manage-schedules') ?? false;
    }
}
