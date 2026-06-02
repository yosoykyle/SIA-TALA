<?php

namespace App\Filament\Resources\ServiceRequests\Tables;

use App\Actions\ServiceRequests\ServiceRequestLifecycleService;
use App\Models\ServiceRequest;
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

class ServiceRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['studentProfile.user', 'term', 'assignee', 'resolver']))
            ->columns([
                TextColumn::make('studentProfile.student_id')
                    ->label('Student ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('studentProfile.user.name')
                    ->label('Student')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->badge()
                    ->searchable(),
                TextColumn::make('sub_type')
                    ->label('Type')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'warning' => ServiceRequest::StatusSubmitted,
                        'info' => ServiceRequest::StatusUnderReview,
                        'success' => ServiceRequest::StatusResolved,
                        'danger' => ServiceRequest::StatusRejected,
                        'gray' => ServiceRequest::StatusCancelled,
                    ])
                    ->searchable(),
                TextColumn::make('assignee.name')
                    ->label('Assigned To')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('resolver.name')
                    ->label('Resolved By')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('resolved_at')
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
                        ServiceRequest::StatusSubmitted => 'Submitted',
                        ServiceRequest::StatusUnderReview => 'Under Review',
                        ServiceRequest::StatusResolved => 'Resolved',
                        ServiceRequest::StatusRejected => 'Rejected',
                        ServiceRequest::StatusCancelled => 'Cancelled',
                    ]),
                SelectFilter::make('category')
                    ->options([
                        'student_service' => 'Student Service',
                        'registrar' => 'Registrar',
                        'document' => 'Document',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                self::startReviewAction(),
                self::resolveAction(),
                self::rejectAction(),
                self::cancelAction(),
            ])
            ->toolbarActions([]);
    }

    private static function startReviewAction(): Action
    {
        return Action::make('startReview')
            ->label('Review')
            ->icon(Heroicon::OutlinedEye)
            ->color('info')
            ->requiresConfirmation()
            ->visible(fn (ServiceRequest $record): bool => self::registrarCanManage()
                && $record->status === ServiceRequest::StatusSubmitted)
            ->action(fn (ServiceRequest $record) => self::handleLifecycleAction(
                fn (ServiceRequestLifecycleService $service, User $actor) => $service->startReview($record, $actor),
                'Service request moved under review',
            ));
    }

    private static function resolveAction(): Action
    {
        return Action::make('resolve')
            ->label('Resolve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (ServiceRequest $record): bool => self::registrarCanManage()
                && in_array($record->status, [
                    ServiceRequest::StatusSubmitted,
                    ServiceRequest::StatusUnderReview,
                ], true))
            ->action(fn (ServiceRequest $record) => self::handleLifecycleAction(
                fn (ServiceRequestLifecycleService $service, User $actor) => $service->resolve($record, $actor),
                'Service request resolved',
            ));
    }

    private static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (ServiceRequest $record): bool => self::registrarCanManage()
                && in_array($record->status, [
                    ServiceRequest::StatusSubmitted,
                    ServiceRequest::StatusUnderReview,
                ], true))
            ->action(fn (ServiceRequest $record) => self::handleLifecycleAction(
                fn (ServiceRequestLifecycleService $service, User $actor) => $service->reject($record, $actor),
                'Service request rejected',
            ));
    }

    private static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('gray')
            ->schema([
                Textarea::make('note')
                    ->label('Internal note')
                    ->maxLength(500),
            ])
            ->visible(fn (ServiceRequest $record): bool => self::registrarCanManage()
                && in_array($record->status, [
                    ServiceRequest::StatusSubmitted,
                    ServiceRequest::StatusUnderReview,
                ], true))
            ->action(fn (ServiceRequest $record) => self::handleLifecycleAction(
                fn (ServiceRequestLifecycleService $service, User $actor) => $service->cancel($record, $actor),
                'Service request cancelled',
            ));
    }

    /**
     * @param  callable(ServiceRequestLifecycleService, User): mixed  $callback
     */
    private static function handleLifecycleAction(callable $callback, string $successTitle): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        try {
            $callback(app(ServiceRequestLifecycleService::class), $actor);

            Notification::make()
                ->title($successTitle)
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Service request action failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    private static function registrarCanManage(): bool
    {
        return auth()->user()?->can('manage-document-requests') ?? false;
    }
}
