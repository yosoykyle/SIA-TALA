<?php

namespace App\Filament\Resources\PaymentAttempts\Tables;

use App\Models\PaymentAttempt;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaymentAttemptsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['studentProfile.user', 'term', 'enrollment', 'ledgerEntry']))
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
                    ->formatStateUsing(fn (?int $state, PaymentAttempt $record): string => $record->enrollment === null
                        ? '-'
                        : $record->enrollment->displayLabel())
                    ->placeholder('-'),
                TextColumn::make('ledgerEntry.id')
                    ->label('Ledger Entry')
                    ->formatStateUsing(fn (?int $state, PaymentAttempt $record): string => $record->ledgerEntry === null
                        ? '-'
                        : $record->ledgerEntry->displayLabel())
                    ->placeholder('-'),
                TextColumn::make('channel')
                    ->badge()
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->searchable(),
                TextColumn::make('provider')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('provider_event_id')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('provider_checkout_session_id')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('provider_payment_id')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('provider_payment_intent_id')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('webhook_idempotency_key')
                    ->label('Webhook Key')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('amount')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('paid_at')
                    ->label('Paid')
                    ->dateTime()
                    ->sortable(),
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
                        'pending' => 'Pending',
                        'paid' => 'Paid',
                        'failed' => 'Failed',
                        'expired' => 'Expired',
                    ]),
                SelectFilter::make('channel')
                    ->options([
                        'checkout' => 'Checkout',
                        'gcash' => 'GCash',
                        'card' => 'Card',
                        'cash' => 'Cash',
                    ]),
                SelectFilter::make('provider')
                    ->options([
                        'mock' => 'Mock',
                        'paymongo' => 'PayMongo',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
