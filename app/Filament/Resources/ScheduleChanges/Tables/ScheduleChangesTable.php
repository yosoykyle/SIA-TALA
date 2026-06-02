<?php

namespace App\Filament\Resources\ScheduleChanges\Tables;

use App\Models\ScheduleChange;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Throwable;

class ScheduleChangesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['term', 'sectionMeeting.section', 'requester', 'approver']))
            ->columns([
                TextColumn::make('term.term_name')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sectionMeeting.section.name')
                    ->label('Section')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'warning' => 'proposed',
                        'info' => 'approved',
                        'success' => 'applied',
                        'danger' => 'rejected',
                    ]),
                TextColumn::make('requester.name')
                    ->label('Requested By')
                    ->placeholder('-'),
                TextColumn::make('approver.name')
                    ->label('Approved By')
                    ->placeholder('-'),
                TextColumn::make('applied_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'proposed' => 'Proposed',
                        'approved' => 'Approved',
                        'applied' => 'Applied',
                        'rejected' => 'Rejected',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (ScheduleChange $record): bool => (auth()->user()?->can('manage-schedules') ?? false)
                        && $record->status === 'proposed'),
                self::approveAction(),
                self::applyAction(),
            ])
            ->toolbarActions([]);
    }

    private static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('info')
            ->requiresConfirmation()
            ->visible(fn (ScheduleChange $record): bool => (auth()->user()?->can('authorize-overrides') ?? false)
                && $record->status === 'proposed')
            ->action(fn (ScheduleChange $record) => self::transition(
                $record,
                'approved',
                'schedule_change_approved',
                'Schedule change approved',
                true,
            ));
    }

    private static function applyAction(): Action
    {
        return Action::make('apply')
            ->label('Apply')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (ScheduleChange $record): bool => (auth()->user()?->can('manage-schedules') ?? false)
                && $record->status === 'approved')
            ->action(fn (ScheduleChange $record) => self::transition(
                $record,
                'applied',
                'schedule_change_applied',
                'Schedule change applied',
                false,
            ));
    }

    private static function transition(
        ScheduleChange $record,
        string $status,
        string $event,
        string $successTitle,
        bool $setApprover,
    ): void {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        try {
            DB::transaction(function () use ($record, $status, $event, $actor, $setApprover): void {
                $timestamp = CarbonImmutable::now(config('app.timezone'));

                $record->forceFill([
                    'status' => $status,
                    'approved_by' => $setApprover ? $actor->id : $record->approved_by,
                    'applied_at' => $status === 'applied' ? $timestamp : $record->applied_at,
                ])->save();

                DB::table('activity_log')->insert([
                    'log_name' => 'scheduling',
                    'description' => 'Schedule change state changed.',
                    'subject_type' => ScheduleChange::class,
                    'subject_id' => $record->id,
                    'event' => $event,
                    'causer_type' => User::class,
                    'causer_id' => $actor->id,
                    'properties' => json_encode([
                        'status_after' => $status,
                        'term_id' => $record->term_id,
                    ], JSON_UNESCAPED_SLASHES),
                    'created_at' => $timestamp->toDateTimeString(),
                    'updated_at' => $timestamp->toDateTimeString(),
                ]);
            });

            Notification::make()
                ->title($successTitle)
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Schedule change action failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
