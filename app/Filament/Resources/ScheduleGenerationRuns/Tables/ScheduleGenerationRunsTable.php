<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Tables;

use App\Models\ScheduleGenerationRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
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
                    ->colors([
                        'info' => 'generated',
                        'warning' => 'draft',
                        'success' => 'committed',
                        'danger' => 'blocked',
                    ])
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
                    ->options([
                        'generated' => 'Generated',
                        'draft' => 'Draft',
                        'committed' => 'Committed',
                        'blocked' => 'Blocked',
                    ]),
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
                && $record->status !== 'committed')
            ->action(function (ScheduleGenerationRun $record): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                try {
                    DB::transaction(function () use ($record, $actor): void {
                        $timestamp = CarbonImmutable::now(config('app.timezone'));

                        $record->forceFill([
                            'status' => 'committed',
                            'committed_by' => $actor->id,
                            'committed_at' => $timestamp,
                        ])->save();

                        DB::table('activity_log')->insert([
                            'log_name' => 'scheduling',
                            'description' => 'Schedule generation run committed from Filament.',
                            'subject_type' => ScheduleGenerationRun::class,
                            'subject_id' => $record->id,
                            'event' => 'schedule_generation_run_committed',
                            'causer_type' => User::class,
                            'causer_id' => $actor->id,
                            'properties' => json_encode([
                                'term_id' => $record->term_id,
                                'status_after' => 'committed',
                            ], JSON_UNESCAPED_SLASHES),
                            'created_at' => $timestamp->toDateTimeString(),
                            'updated_at' => $timestamp->toDateTimeString(),
                        ]);
                    });

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
