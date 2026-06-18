<?php

namespace App\Filament\Resources\AccountingAdjustments\Schemas;

use App\Models\AccountingAdjustment;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AccountingAdjustmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('studentProfile.student_id')
                    ->label('Student ID'),
                TextEntry::make('studentProfile.user.name')
                    ->label('Student')
                    ->placeholder('-'),
                TextEntry::make('term.term_name')
                    ->label('Term')
                    ->placeholder('-'),
                TextEntry::make('enrollment.id')
                    ->label('Enrollment')
                    ->formatStateUsing(fn (?int $state, AccountingAdjustment $record): string => $record->enrollment === null
                        ? '-'
                        : AccountingAdjustment::enrollmentOptionLabel($record->enrollment)),
                TextEntry::make('adjustment_type')
                    ->label('Type')
                    ->badge(),
                TextEntry::make('amount')
                    ->numeric(),
                TextEntry::make('sourceLedgerEntry.id')
                    ->label('Source Ledger Entry')
                    ->formatStateUsing(fn (?int $state, AccountingAdjustment $record): string => $record->sourceLedgerEntry === null
                        ? '-'
                        : AccountingAdjustment::sourceLedgerOptionLabel($record->sourceLedgerEntry)),
                TextEntry::make('ledgerEntry.id')
                    ->label('Posted Ledger Entry')
                    ->formatStateUsing(fn (?int $state, AccountingAdjustment $record): string => $record->ledgerEntry === null
                        ? '-'
                        : AccountingAdjustment::sourceLedgerOptionLabel($record->ledgerEntry)),
                TextEntry::make('ledgerEntry.running_balance')
                    ->label('Balance After')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('posted_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('poster.name')
                    ->label('Posted By')
                    ->placeholder('System'),
                TextEntry::make('evidence_reference')
                    ->label('Evidence Reference')
                    ->placeholder('-'),
                TextEntry::make('reason')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
