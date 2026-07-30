<?php

namespace App\Filament\Resources\Payments\Schemas;

use App\Actions\Finance\PaymentAcademicContextResolver;
use App\Models\Enrollment;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentAttempt;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
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
                TextEntry::make('studentProfile.program.code')
                    ->label('Program')
                    ->placeholder('-'),
                TextEntry::make('year_level')
                    ->label('Year Level')
                    ->state(fn (Payment $record): string => (string) (self::academicContext($record)['curriculum_level_label'] ?? 'Not recorded')),
                TextEntry::make('section')
                    ->label('Section')
                    ->state(fn (Payment $record): string => collect(self::academicContext($record)['section_labels'] ?? [])->implode(', ') ?: 'Not assigned'),
                TextEntry::make('term.label')
                    ->label('Term')
                    ->placeholder('-'),
                TextEntry::make('academic_enrollment')
                    ->label('Enrollment')
                    ->state(fn (Payment $record): string => self::academicEnrollment($record)?->displayLabel() ?? '-')
                    ->placeholder('-'),
                TextEntry::make('paymentAttempt.id')
                    ->label('Payment Attempt')
                    ->formatStateUsing(function (?int $state, Payment $record): string {
                        $attempt = $record->paymentAttempt;

                        return $attempt instanceof PaymentAttempt ? $attempt->displayLabel() : '-';
                    })
                    ->placeholder('-'),
                Section::make('Allocation-Linked Ledger Postings')
                    ->description('One verified payment may be split across several eligible account items while retaining one payment reference and one optional OR number.')
                    ->schema([
                        RepeatableEntry::make('allocations')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('target')
                                    ->label('Applied To')
                                    ->state(fn (PaymentAllocation $record): string => $record->targetLabel()),
                                TextEntry::make('amount')
                                    ->label('Amount')
                                    ->money('PHP'),
                                TextEntry::make('ledgerEntry.id')
                                    ->label('Ledger Posting')
                                    ->formatStateUsing(fn (?int $state, PaymentAllocation $record): string => $record->ledgerEntry instanceof LedgerEntry
                                        ? '#'.$record->ledgerEntry->id.' - Posted'
                                        : 'Not posted'),
                            ])
                            ->columns(3)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
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

    /** @return array<string, mixed> */
    private static function academicContext(Payment $payment): array
    {
        return app(PaymentAcademicContextResolver::class)->forPayment($payment);
    }

    private static function academicEnrollment(Payment $payment): ?Enrollment
    {
        return app(PaymentAcademicContextResolver::class)->enrollment($payment);
    }
}
