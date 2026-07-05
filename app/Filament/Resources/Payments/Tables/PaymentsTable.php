<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Actions\Finance\MapOfficialReceiptToPayment;
use App\Models\Enrollment;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['studentProfile.user', 'term', 'paymentAttempt', 'ledgerEntry.enrollment', 'verifier']))
            ->columns([
                TextColumn::make('studentProfile.student_number')
                    ->label('Student ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('studentProfile.user.name')
                    ->label('Student')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('term.label')
                    ->label('Term')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('ledgerEntry.enrollment.id')
                    ->label('Enrollment')
                    ->formatStateUsing(function (?int $state, Payment $record): string {
                        $ledgerEntry = $record->ledgerEntry;
                        $enrollment = $ledgerEntry instanceof LedgerEntry ? $ledgerEntry->enrollment : null;

                        return $enrollment instanceof Enrollment ? $enrollment->displayLabel() : '-';
                    })
                    ->placeholder('-'),
                TextColumn::make('paymentAttempt.id')
                    ->label('Payment Attempt')
                    ->formatStateUsing(function (?int $state, Payment $record): string {
                        $attempt = $record->paymentAttempt;

                        return $attempt instanceof PaymentAttempt ? $attempt->displayLabel() : '-';
                    })
                    ->placeholder('-'),
                TextColumn::make('ledgerEntry.id')
                    ->label('Ledger Entry')
                    ->formatStateUsing(fn (?int $state, Payment $record): string => $record->ledgerEntry instanceof LedgerEntry
                        ? $record->ledgerEntry->displayLabel()
                        : '-')
                    ->placeholder('-'),
                TextColumn::make('provider_reference')
                    ->label('Reference')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('or_number')
                    ->label('OR Number')
                    ->placeholder('-')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('channel')
                    ->badge()
                    ->searchable(),
                TextColumn::make('amount')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('evidence_status')
                    ->badge()
                    ->searchable(),
                TextColumn::make('verified_at')
                    ->label('Verified')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('verifier.name')
                    ->label('Verified By')
                    ->placeholder('System')
                    ->searchable(),
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
                SelectFilter::make('evidence_status')
                    ->options([
                        'verified' => 'Verified',
                        'under_review' => 'Under Review',
                        'rejected' => 'Rejected',
                    ]),
                SelectFilter::make('channel')
                    ->options([
                        'cash' => 'Cash',
                        'gcash_manual' => 'GCash Manual',
                        'bank_transfer' => 'Bank Transfer',
                        'paymongo' => 'PayMongo',
                        'paymongo_reconciled' => 'PayMongo Reconciled',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('acknowledgement')
                    ->label('Acknowledgement')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->url(fn (Payment $record): string => route('finance.payments.acknowledgement', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (Payment $record): bool => auth()->user()?->can('viewAcknowledgement', $record) ?? false),
                Action::make('mapOr')
                    ->label('Map OR')
                    ->icon(Heroicon::OutlinedClipboardDocument)
                    ->color('primary')
                    ->schema([
                        TextInput::make('or_number')
                            ->label('OR Number')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->action(function (Payment $record, array $data): void {
                        $actor = auth()->user();

                        if (! $actor instanceof User) {
                            abort(403);
                        }

                        try {
                            app(MapOfficialReceiptToPayment::class)->execute(
                                payment: $record,
                                orNumber: (string) $data['or_number'],
                                actor: $actor,
                            );

                            Notification::make()
                                ->title('Official Receipt mapped successfully')
                                ->success()
                                ->send();
                        } catch (\Throwable $exception) {
                            Notification::make()
                                ->title('Official Receipt was not mapped')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->visible(fn (Payment $record): bool => auth()->user()?->can('mapOfficialReceipt', $record) ?? false),
            ])
            ->toolbarActions([]);
    }
}
