<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Models\Payment;
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
                TextColumn::make('ledgerEntry.enrollment.id')
                    ->label('Enrollment')
                    ->formatStateUsing(fn (?int $state, Payment $record): string => $record->ledgerEntry?->enrollment === null
                        ? '-'
                        : $record->ledgerEntry->enrollment->displayLabel())
                    ->placeholder('-'),
                TextColumn::make('paymentAttempt.id')
                    ->label('Payment Attempt')
                    ->formatStateUsing(fn (?int $state, Payment $record): string => $record->paymentAttempt === null
                        ? '-'
                        : $record->paymentAttempt->displayLabel())
                    ->placeholder('-'),
                TextColumn::make('ledgerEntry.id')
                    ->label('Ledger Entry')
                    ->formatStateUsing(fn (?int $state, Payment $record): string => $record->ledgerEntry === null
                        ? '-'
                        : $record->ledgerEntry->displayLabel())
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
                    ->form([
                        TextInput::make('or_number')
                            ->label('OR Number')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->action(function (Payment $record, array $data): void {
                        if (Payment::query()->where('or_number', $data['or_number'])->where('id', '!=', $record->id)->exists()) {
                            Notification::make()
                                ->title('OR Number already exists')
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->update([
                            'or_number' => $data['or_number'],
                            'or_mapped_by' => auth()->id(),
                            'or_mapped_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Official Receipt mapped successfully')
                            ->success()
                            ->send();
                    })
                    ->visible(fn (Payment $record): bool => (auth()->user()?->can('process-payments') ?? false)
                        && $record->evidence_status === 'verified'
                        && $record->ledgerEntry !== null
                        && empty($record->or_number)),
            ])
            ->toolbarActions([]);
    }
}
