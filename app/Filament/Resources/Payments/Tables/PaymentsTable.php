<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Models\Payment;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['studentProfile.user', 'term', 'enrollment', 'paymentAttempt', 'ledgerEntry', 'confirmer']))
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
                    ->formatStateUsing(fn (?int $state, Payment $record): string => $record->enrollment === null
                        ? '-'
                        : $record->enrollment->displayLabel())
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
                TextColumn::make('payment_reference')
                    ->label('Reference')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('channel')
                    ->badge()
                    ->searchable(),
                TextColumn::make('amount')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->searchable(),
                TextColumn::make('confirmed_at')
                    ->label('Confirmed')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('confirmer.name')
                    ->label('Confirmed By')
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
                SelectFilter::make('status')
                    ->options([
                        'confirmed' => 'Confirmed',
                        'voided' => 'Voided',
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
            ])
            ->toolbarActions([]);
    }
}
