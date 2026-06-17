<?php

namespace App\Filament\Resources\PromissoryNotes\Tables;

use App\Actions\Finance\PromissoryNoteLifecycleService;
use App\Models\PromissoryNote;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PromissoryNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['studentProfile.user', 'term', 'enrollment', 'ledgerEntry', 'requester', 'approver', 'rejector', 'canceller', 'settler']))
            ->columns([
                TextColumn::make('studentProfile.student_id')
                    ->label('Student ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('studentProfile.user.name')
                    ->label('Student')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('term.term_name')
                    ->label('Term')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('enrollment.id')
                    ->label('Enrollment')
                    ->formatStateUsing(fn (?int $state, PromissoryNote $record): string => $record->enrollment === null
                        ? '-'
                        : PromissoryNote::enrollmentOptionLabel($record->enrollment))
                    ->placeholder('-'),
                TextColumn::make('ledgerEntry.id')
                    ->label('Ledger Entry')
                    ->formatStateUsing(fn (?int $state, PromissoryNote $record): string => $record->ledgerEntry === null
                        ? '-'
                        : PromissoryNote::ledgerEntryOptionLabel($record->ledgerEntry))
                    ->placeholder('-'),
                TextColumn::make('amount')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label('Due')
                    ->date()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        PromissoryNote::StatusPending => 'warning',
                        PromissoryNote::StatusApproved, 'active' => 'success',
                        PromissoryNote::StatusSettled => 'info',
                        PromissoryNote::StatusRejected, PromissoryNote::StatusCancelled, PromissoryNote::StatusExpired => 'danger',
                        default => 'gray',
                    })
                    ->searchable(),
                TextColumn::make('request_source')
                    ->label('Source')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('requester.name')
                    ->label('Requested By')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('approver.name')
                    ->label('Approved By')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('approved_at')
                    ->label('Approved')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('expired_at')
                    ->label('Expired')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('reason')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'approved' => 'Approved',
                        'expired' => 'Expired',
                        'settled' => 'Settled',
                        'rejected' => 'Rejected',
                        'cancelled' => 'Cancelled',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                self::approveAction(),
                self::rejectAction(),
                self::cancelAction(),
            ])
            ->toolbarActions([]);
    }

    private static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (PromissoryNote $record): bool => auth()->user()?->can('approve', $record) ?? false)
            ->action(fn (PromissoryNote $record) => self::runAction(
                fn (PromissoryNoteLifecycleService $service, User $actor): PromissoryNote => $service->approve($record, $actor),
                'Promissory request approved',
            ));
    }

    private static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->schema([
                Textarea::make('rejection_reason')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->visible(fn (PromissoryNote $record): bool => auth()->user()?->can('reject', $record) ?? false)
            ->action(fn (PromissoryNote $record, array $data) => self::runAction(
                fn (PromissoryNoteLifecycleService $service, User $actor): PromissoryNote => $service->reject(
                    $record,
                    $actor,
                    (string) $data['rejection_reason'],
                ),
                'Promissory request rejected',
            ));
    }

    private static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('gray')
            ->schema([
                Textarea::make('cancellation_reason')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->visible(fn (PromissoryNote $record): bool => auth()->user()?->can('cancel', $record) ?? false)
            ->action(fn (PromissoryNote $record, array $data) => self::runAction(
                fn (PromissoryNoteLifecycleService $service, User $actor): PromissoryNote => $service->cancel(
                    $record,
                    $actor,
                    (string) $data['cancellation_reason'],
                ),
                'Promissory request cancelled',
            ));
    }

    /**
     * @param  callable(PromissoryNoteLifecycleService, User): PromissoryNote  $callback
     */
    private static function runAction(callable $callback, string $successTitle): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        try {
            $callback(app(PromissoryNoteLifecycleService::class), $actor);

            Notification::make()
                ->title($successTitle)
                ->success()
                ->send();
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Promissory action failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
