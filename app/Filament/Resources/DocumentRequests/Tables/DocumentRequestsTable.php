<?php

namespace App\Filament\Resources\DocumentRequests\Tables;

use App\Actions\ServiceRequests\DocumentRequestLifecycleService;
use App\Models\DocumentRequest;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class DocumentRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['studentProfile.user', 'term']))
            ->columns([
                TextColumn::make('studentProfile.student_id')
                    ->label('Student ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('studentProfile.user.name')
                    ->label('Student')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('document_type')
                    ->label('Document')
                    ->formatStateUsing(fn (?string $state): string => DocumentRequest::documentTypeLabel($state))
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'warning' => DocumentRequest::StatusPendingDocumentFee,
                        'info' => DocumentRequest::StatusProcessing,
                        'primary' => DocumentRequest::StatusReadyForPickup,
                        'danger' => DocumentRequest::StatusPendingShippingPayment,
                        'success' => DocumentRequest::StatusCompleted,
                        'gray' => DocumentRequest::StatusCompletedWithDebt,
                    ])
                    ->searchable(),
                TextColumn::make('delivery_mode')
                    ->label('Delivery')
                    ->badge(),
                IconColumn::make('is_free_request')
                    ->label('Free')
                    ->boolean(),
                TextColumn::make('courier_name')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('tracking_number_normalized')
                    ->label('Tracking')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('shipping_fee')
                    ->money('PHP')
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('shipping_grace_ends_at')
                    ->label('Grace Ends')
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
                        DocumentRequest::StatusPendingDocumentFee => 'Pending Document Fee',
                        DocumentRequest::StatusProcessing => 'Processing',
                        DocumentRequest::StatusReadyForPickup => 'Ready for Pickup',
                        DocumentRequest::StatusPendingShippingPayment => 'Pending Shipping Payment',
                        DocumentRequest::StatusCompleted => 'Completed',
                        DocumentRequest::StatusCompletedWithDebt => 'Completed with Debt',
                        DocumentRequest::StatusCancelled => 'Cancelled',
                    ]),
                SelectFilter::make('delivery_mode')
                    ->options([
                        DocumentRequest::DeliveryModePickup => 'Pickup',
                        DocumentRequest::DeliveryModeCourier => 'Courier',
                    ]),
                SelectFilter::make('document_type')
                    ->label('Document')
                    ->options(DocumentRequest::documentTypeOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                self::confirmDocumentFeeAction(),
                self::confirmShippingPaymentAction(),
                self::markReadyForPickupAction(),
                self::completePickupAction(),
                self::markShippedAction(),
                self::cancelAction(),
            ])
            ->toolbarActions([]);
    }

    private static function confirmDocumentFeeAction(): Action
    {
        return Action::make('confirmDocumentFee')
            ->label('Confirm Doc Fee')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (DocumentRequest $record): bool => self::accountingCanProcess()
                && $record->status === DocumentRequest::StatusPendingDocumentFee)
            ->action(fn (DocumentRequest $record) => self::handleLifecycleAction(
                fn (DocumentRequestLifecycleService $service, User $actor) => $service->confirmDocumentFee($record, $actor),
                'Document fee confirmed',
            ));
    }

    private static function confirmShippingPaymentAction(): Action
    {
        return Action::make('confirmShippingPayment')
            ->label('Confirm Shipping')
            ->icon(Heroicon::OutlinedCreditCard)
            ->color('success')
            ->schema([
                TextInput::make('amount')
                    ->label('Amount Paid')
                    ->required()
                    ->numeric()
                    ->minValue(0.01),
                Select::make('channel')
                    ->required()
                    ->options([
                        'cash' => 'Cash',
                        'gcash_manual' => 'GCash Manual',
                        'bank_transfer' => 'Bank Transfer',
                        'paymongo_reconciled' => 'PayMongo Reconciled',
                    ])
                    ->default('cash'),
                TextInput::make('payment_reference')
                    ->label('Reference Number')
                    ->maxLength(255),
            ])
            ->modalSubmitActionLabel('Confirm Shipping Payment')
            ->visible(fn (DocumentRequest $record): bool => self::accountingCanProcess()
                && $record->status === DocumentRequest::StatusPendingShippingPayment)
            ->action(fn (DocumentRequest $record, array $data) => self::handleLifecycleAction(
                fn (DocumentRequestLifecycleService $service, User $actor) => $service->confirmShippingPaymentManually(
                    request: $record,
                    cashier: $actor,
                    amount: (string) $data['amount'],
                    channel: (string) $data['channel'],
                    paymentReference: isset($data['payment_reference']) ? (string) $data['payment_reference'] : null,
                ),
                'Shipping payment confirmed',
            ));
    }

    private static function markReadyForPickupAction(): Action
    {
        return Action::make('markReadyForPickup')
            ->label('Ready')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->color('info')
            ->requiresConfirmation()
            ->visible(fn (DocumentRequest $record): bool => self::registrarCanManage()
                && $record->status === DocumentRequest::StatusProcessing
                && $record->delivery_mode === DocumentRequest::DeliveryModePickup)
            ->action(fn (DocumentRequest $record) => self::handleLifecycleAction(
                fn (DocumentRequestLifecycleService $service, User $actor) => $service->markReadyForPickup($record, $actor),
                'Document marked ready for pickup',
            ));
    }

    private static function completePickupAction(): Action
    {
        return Action::make('completePickup')
            ->label('Complete Pickup')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (DocumentRequest $record): bool => self::registrarCanManage()
                && $record->status === DocumentRequest::StatusReadyForPickup)
            ->action(fn (DocumentRequest $record) => self::handleLifecycleAction(
                fn (DocumentRequestLifecycleService $service, User $actor) => $service->completePickup($record, $actor),
                'Document pickup completed',
            ));
    }

    private static function markShippedAction(): Action
    {
        return Action::make('markShipped')
            ->label('Ship')
            ->icon(Heroicon::OutlinedTruck)
            ->color('warning')
            ->schema([
                TextInput::make('courier_name')
                    ->required()
                    ->maxLength(100),
                TextInput::make('tracking_number')
                    ->required()
                    ->maxLength(100)
                    ->helperText('Use N/A if no tracking number is available.'),
                TextInput::make('shipping_fee')
                    ->required()
                    ->numeric()
                    ->minValue(0.01),
                TextInput::make('courier_receipt_path')
                    ->label('Private receipt path')
                    ->required()
                    ->maxLength(500),
            ])
            ->modalSubmitActionLabel('Record Shipment')
            ->visible(fn (DocumentRequest $record): bool => self::registrarCanManage()
                && $record->status === DocumentRequest::StatusProcessing
                && $record->delivery_mode === DocumentRequest::DeliveryModeCourier)
            ->action(fn (DocumentRequest $record, array $data) => self::handleLifecycleAction(
                fn (DocumentRequestLifecycleService $service, User $actor) => $service->markShipped($record, [
                    'courier_name' => (string) $data['courier_name'],
                    'tracking_number' => (string) $data['tracking_number'],
                    'shipping_fee' => (string) $data['shipping_fee'],
                    'courier_receipt_path' => (string) $data['courier_receipt_path'],
                ], $actor),
                'Shipment recorded and student notified',
            ));
    }

    private static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->schema([
                Textarea::make('reason')
                    ->required()
                    ->maxLength(500),
            ])
            ->requiresConfirmation()
            ->visible(fn (DocumentRequest $record): bool => self::registrarCanManage()
                && in_array($record->status, [
                    DocumentRequest::StatusPendingDocumentFee,
                    DocumentRequest::StatusProcessing,
                ], true))
            ->action(fn (DocumentRequest $record, array $data) => self::handleLifecycleAction(
                fn (DocumentRequestLifecycleService $service, User $actor) => $service->cancel($record, $actor, (string) $data['reason']),
                'Document request cancelled',
            ));
    }

    /**
     * @param  callable(DocumentRequestLifecycleService, User): mixed  $callback
     */
    private static function handleLifecycleAction(callable $callback, string $successTitle): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        try {
            $callback(app(DocumentRequestLifecycleService::class), $actor);

            Notification::make()
                ->title($successTitle)
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Document request action failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    private static function registrarCanManage(): bool
    {
        return auth()->user()?->can('manage-document-requests') ?? false;
    }

    private static function accountingCanProcess(): bool
    {
        return auth()->user()?->can('process-payments') ?? false;
    }
}
