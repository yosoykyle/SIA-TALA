<?php

namespace App\Filament\Resources\AccountingAdjustments\Schemas;

use App\Models\AccountingAdjustment;
use App\Models\Enrollment;
use App\Models\LedgerEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AccountingAdjustmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('studentProfile.student_number')
                    ->label('Student ID'),
                TextEntry::make('studentProfile.user.name')
                    ->label('Student')
                    ->placeholder('-'),
                TextEntry::make('term.label')
                    ->label('Term')
                    ->placeholder('-'),
                TextEntry::make('enrollment.id')
                    ->label('Enrollment')
                    ->formatStateUsing(function (?int $state, AccountingAdjustment $record): string {
                        $enrollment = $record->enrollment;

                        return $enrollment instanceof Enrollment
                            ? AccountingAdjustment::enrollmentOptionLabel($enrollment)
                            : '-';
                    }),
                TextEntry::make('adjustment_type')
                    ->label('Type')
                    ->badge(),
                TextEntry::make('amount')
                    ->numeric(),
                TextEntry::make('sourceLedgerEntry.id')
                    ->label('Source Ledger Entry')
                    ->formatStateUsing(function (?int $state, AccountingAdjustment $record): string {
                        $ledgerEntry = $record->sourceLedgerEntry;

                        return $ledgerEntry instanceof LedgerEntry
                            ? AccountingAdjustment::sourceLedgerOptionLabel($ledgerEntry)
                            : '-';
                    }),
                TextEntry::make('ledgerEntry.id')
                    ->label('Posted Ledger Entry')
                    ->formatStateUsing(function (?int $state, AccountingAdjustment $record): string {
                        $ledgerEntry = $record->ledgerEntry;

                        return $ledgerEntry instanceof LedgerEntry
                            ? AccountingAdjustment::sourceLedgerOptionLabel($ledgerEntry)
                            : '-';
                    }),
                TextEntry::make('ledgerEntry.direction')
                    ->label('Posted Ledger Direction')
                    ->badge()
                    ->placeholder('-'),
                TextEntry::make('ledgerEntry.amount')
                    ->label('Posted Ledger Amount')
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
