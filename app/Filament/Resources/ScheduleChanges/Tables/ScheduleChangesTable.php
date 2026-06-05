<?php

namespace App\Filament\Resources\ScheduleChanges\Tables;

use App\Actions\Scheduling\ScheduleChangeLifecycleService;
use App\Models\ScheduleChange;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
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
                    ->colors(ScheduleChange::statusColors()),
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
                    ->options(ScheduleChange::statusOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (ScheduleChange $record): bool => (auth()->user()?->can('manage-schedules') ?? false)
                        && $record->isProposed()),
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
                && $record->isProposed())
            ->action(fn (ScheduleChange $record) => self::runAction(
                fn (ScheduleChangeLifecycleService $service, User $actor): ScheduleChange => $service->approve($record, $actor),
                'Schedule change approved',
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
                && $record->isApproved())
            ->action(fn (ScheduleChange $record) => self::runAction(
                fn (ScheduleChangeLifecycleService $service, User $actor): ScheduleChange => $service->apply($record, $actor),
                'Schedule change applied',
            ));
    }

    /**
     * @param  callable(ScheduleChangeLifecycleService, User): ScheduleChange  $callback
     */
    private static function runAction(callable $callback, string $successTitle): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        try {
            $callback(app(ScheduleChangeLifecycleService::class), $actor);

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
