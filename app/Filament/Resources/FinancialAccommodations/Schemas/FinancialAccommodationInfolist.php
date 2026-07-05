<?php

namespace App\Filament\Resources\FinancialAccommodations\Schemas;

use App\Models\FinancialAccommodation;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FinancialAccommodationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Accommodation Result')
                    ->schema([
                        TextEntry::make('studentProfile.student_number')
                            ->label('Student ID'),
                        TextEntry::make('studentProfile.user.name')
                            ->label('Student')
                            ->placeholder('-'),
                        TextEntry::make('term.label')
                            ->label('Covered Term'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => FinancialAccommodation::statusOptions()[$state] ?? str($state)->headline()->toString()),
                        TextEntry::make('basis')
                            ->formatStateUsing(fn (string $state): string => FinancialAccommodation::basisOptions()[$state] ?? str($state)->replace('_', ' ')->headline()->toString()),
                        TextEntry::make('balance_snapshot')
                            ->money('PHP'),
                        TextEntry::make('covered_amount')
                            ->money('PHP'),
                        TextEntry::make('authority')
                            ->label('Decision Authority'),
                        TextEntry::make('recorder.name')
                            ->label('Recorded By')
                            ->placeholder('System'),
                        TextEntry::make('effective_from')
                            ->date(),
                        TextEntry::make('expires_on')
                            ->date()
                            ->placeholder('-'),
                    ])
                    ->columns(3),
                Section::make('Approved Effects')
                    ->schema([
                        IconEntry::make('allows_finance_gate')
                            ->label('Current-term Finance Gate')
                            ->boolean(),
                        IconEntry::make('allows_next_term_enrollment')
                            ->label('Next-term Enrollment')
                            ->boolean(),
                        IconEntry::make('allows_reactivation')
                            ->label('Reactivation')
                            ->boolean(),
                        IconEntry::make('allows_record_release')
                            ->label('Record Release')
                            ->boolean(),
                        IconEntry::make('waives_downpayment')
                            ->label('Waives Downpayment')
                            ->boolean(),
                    ])
                    ->columns(5),
                Section::make('Accounting Reference Evidence')
                    ->schema([
                        TextEntry::make('certification_reference')
                            ->label('Certification Reference')
                            ->placeholder('-'),
                        TextEntry::make('private_evidence_reference')
                            ->label('Private Evidence Reference')
                            ->placeholder('-'),
                        IconEntry::make('promissory_required')
                            ->label('Promissory Required')
                            ->boolean(),
                        TextEntry::make('promissory_maker')
                            ->label('Responsible Payer / Maker')
                            ->placeholder('-'),
                    ])
                    ->columns(2),
                Section::make('Payment Schedule')
                    ->schema([
                        RepeatableEntry::make('paymentScheduleRows')
                            ->label('Rows')
                            ->schema([
                                TextEntry::make('sequence'),
                                TextEntry::make('category')
                                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                                TextEntry::make('due_date')
                                    ->date(),
                                TextEntry::make('amount')
                                    ->money('PHP'),
                                TextEntry::make('state')
                                    ->badge()
                                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                            ])
                            ->columns(5)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
