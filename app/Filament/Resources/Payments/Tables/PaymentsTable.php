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
            ->modifyQueryUsing(fn ($query) => $query->with(['termAccount.credentialUser', 'term', 'verifier'])->withCount('allocations'))
            ->columns([
                TextColumn::make('provider_reference')->label('Posting reference')->searchable(),
                TextColumn::make('termAccount.credentialUser.email')->label('Learner credential')->searchable(),
                TextColumn::make('term.label')->label('Term')->placeholder('Not recorded'),
                TextColumn::make('channel')->formatStateUsing(fn (?string $state): string => str($state ?? 'unknown')->replace('_', ' ')->headline()->toString())->badge(),
                TextColumn::make('amount')->money('PHP')->sortable(),
                TextColumn::make('state')->badge(),
                TextColumn::make('allocations_count')->label('Obligation effects'),
                TextColumn::make('verified_at')->label('Verified')->dateTime()->sortable(),
                TextColumn::make('verifier.name')->label('Verified by')->placeholder('System'),
            ])
            ->filters([
                SelectFilter::make('state')->options([Payment::StatePosted => 'Posted', Payment::StateReversal => 'Reversal']),
                SelectFilter::make('channel')->options(Payment::manualConfirmationChannelOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('acknowledgement')
                    ->label('Acknowledgment')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->url(fn (Payment $record): string => route('finance.payments.acknowledgement', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (Payment $record): bool => $record->state === Payment::StatePosted),
            ]);
    }
}
