<?php

namespace App\Filament\Resources\Payments\Schemas;

use App\Models\Enrollment;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class PaymentInfolist
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
                TextEntry::make('ledgerEntry.enrollment.id')
                    ->label('Enrollment')
                    ->formatStateUsing(function (?int $state, Payment $record): string {
                        $ledgerEntry = $record->ledgerEntry;
                        $enrollment = $ledgerEntry instanceof LedgerEntry ? $ledgerEntry->enrollment : null;

                        return $enrollment instanceof Enrollment ? $enrollment->displayLabel() : '-';
                    })
                    ->placeholder('-'),
                TextEntry::make('paymentAttempt.id')
                    ->label('Payment Attempt')
                    ->formatStateUsing(function (?int $state, Payment $record): string {
                        $attempt = $record->paymentAttempt;

                        return $attempt instanceof PaymentAttempt ? $attempt->displayLabel() : '-';
                    })
                    ->placeholder('-'),
                TextEntry::make('ledgerEntry.id')
                    ->label('Ledger entry')
                    ->formatStateUsing(fn (?int $state, Payment $record): string => $record->ledgerEntry instanceof LedgerEntry
                        ? $record->ledgerEntry->displayLabel()
                        : '-')
                    ->placeholder('-'),
                TextEntry::make('provider_reference')
                    ->placeholder('-'),
                TextEntry::make('or_number')
                    ->label('OR Number')
                    ->placeholder('-'),
                TextEntry::make('channel'),
                TextEntry::make('amount')
                    ->numeric(),
                TextEntry::make('evidence_status'),
                TextEntry::make('verified_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('verifier.name')
                    ->label('Verified By')
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
