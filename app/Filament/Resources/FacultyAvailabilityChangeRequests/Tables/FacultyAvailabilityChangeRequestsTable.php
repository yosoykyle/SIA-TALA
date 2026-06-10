<?php

namespace App\Filament\Resources\FacultyAvailabilityChangeRequests\Tables;

use App\Actions\Scheduling\FacultyAvailabilityChangeRequestService;
use App\Models\FacultyAvailabilityChangeRequest;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class FacultyAvailabilityChangeRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['term', 'faculty', 'submission', 'requester', 'reviewer', 'createdSubmission']))
            ->columns([
                TextColumn::make('term.term_name')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('faculty.name')
                    ->label('Faculty')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => FacultyAvailabilityChangeRequest::statusOptions()[$state] ?? '-')
                    ->color(fn (?string $state): string => match ($state) {
                        FacultyAvailabilityChangeRequest::StatusPending => 'warning',
                        FacultyAvailabilityChangeRequest::StatusApproved => 'success',
                        FacultyAvailabilityChangeRequest::StatusRejected => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('submission.version')
                    ->label('Source Version')
                    ->numeric(),
                TextColumn::make('createdSubmission.version')
                    ->label('Created Version')
                    ->numeric()
                    ->placeholder('-'),
                TextColumn::make('requester.name')
                    ->label('Requested By')
                    ->placeholder('-'),
                TextColumn::make('reviewer.name')
                    ->label('Reviewed By')
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('reviewed_at')
                    ->dateTime()
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(FacultyAvailabilityChangeRequest::statusOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                self::approveAction(),
                self::rejectAction(),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }

    private static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->schema([
                Textarea::make('review_note')
                    ->label('Review note')
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->requiresConfirmation()
            ->visible(fn (FacultyAvailabilityChangeRequest $record): bool => self::canReviewAvailability()
                && $record->isPending())
            ->action(fn (FacultyAvailabilityChangeRequest $record, array $data) => self::runAction(
                fn (FacultyAvailabilityChangeRequestService $service, User $actor): FacultyAvailabilityChangeRequest => $service->approve(
                    $record,
                    $actor,
                    $data['review_note'] ?? null,
                ),
                'Availability change request approved',
            ));
    }

    private static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->schema([
                Textarea::make('review_note')
                    ->label('Review note')
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->requiresConfirmation()
            ->visible(fn (FacultyAvailabilityChangeRequest $record): bool => self::canReviewAvailability()
                && $record->isPending())
            ->action(fn (FacultyAvailabilityChangeRequest $record, array $data) => self::runAction(
                fn (FacultyAvailabilityChangeRequestService $service, User $actor): FacultyAvailabilityChangeRequest => $service->reject(
                    $record,
                    $actor,
                    $data['review_note'] ?? null,
                ),
                'Availability change request rejected',
            ));
    }

    /**
     * @param  callable(FacultyAvailabilityChangeRequestService, User): FacultyAvailabilityChangeRequest  $callback
     */
    private static function runAction(callable $callback, string $successTitle): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        try {
            $callback(app(FacultyAvailabilityChangeRequestService::class), $actor);

            Notification::make()
                ->title($successTitle)
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Availability change request action failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    private static function canReviewAvailability(): bool
    {
        return auth()->user()?->can('review-lock-faculty-availability') ?? false;
    }
}
