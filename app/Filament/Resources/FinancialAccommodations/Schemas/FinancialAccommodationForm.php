<?php

namespace App\Filament\Resources\FinancialAccommodations\Schemas;

use App\Models\FinancialAccommodation;
use App\Models\PaymentScheduleRow;
use App\Models\StudentProfile;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class FinancialAccommodationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Accommodation Result')
                    ->schema([
                        Select::make('student_profile_id')
                            ->label('Student')
                            ->relationship('studentProfile', 'student_number')
                            ->getOptionLabelFromRecordUsing(fn (StudentProfile $record): string => FinancialAccommodation::studentOptionLabel($record))
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('term_id')
                            ->label('Covered Term')
                            ->relationship('term', 'label')
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('balance_snapshot')
                            ->label('Outstanding Balance Snapshot')
                            ->prefix('PHP')
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        TextInput::make('covered_amount')
                            ->label('Covered Amount')
                            ->prefix('PHP')
                            ->numeric()
                            ->minValue(0.01)
                            ->required(),
                        Select::make('basis')
                            ->options(FinancialAccommodation::basisOptions())
                            ->required(),
                        Select::make('status')
                            ->options(FinancialAccommodation::creationStatusOptions())
                            ->default(FinancialAccommodation::StatusActive)
                            ->required(),
                        DatePicker::make('effective_from')
                            ->default(fn () => today())
                            ->required(),
                        DatePicker::make('expires_on')
                            ->label('Expires On'),
                        TextInput::make('authority')
                            ->label('Decision Authority')
                            ->maxLength(255)
                            ->required(),
                    ])
                    ->columns(2),
                Section::make('Approved Effects')
                    ->schema([
                        Toggle::make('allows_finance_gate')
                            ->label('Allows Current-term Finance Gate')
                            ->helperText('Bypasses only current-term financial enrollment blocking holds.'),
                        Toggle::make('allows_next_term_enrollment')
                            ->label('Allows Next-term Enrollment'),
                        Toggle::make('allows_reactivation')
                            ->label('Allows Reactivation'),
                        Toggle::make('allows_record_release')
                            ->label('Allows Record Release'),
                        Toggle::make('waives_downpayment')
                            ->label('Waives Downpayment'),
                    ])
                    ->columns(3),
                Section::make('Reference Evidence')
                    ->schema([
                        TextInput::make('certification_reference')
                            ->label('Certification Reference')
                            ->maxLength(255),
                        TextInput::make('private_evidence_reference')
                            ->label('Private Evidence / Document Reference')
                            ->maxLength(255)
                            ->helperText('Accounting-only reference. Not shown on student finance surfaces.'),
                        Toggle::make('promissory_required')
                            ->label('Promissory Note Required')
                            ->live(),
                        TextInput::make('promissory_maker')
                            ->label('Responsible Payer / Maker')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => (bool) $get('promissory_required'))
                            ->required(fn (Get $get): bool => (bool) $get('promissory_required')),
                    ])
                    ->columns(2),
                Section::make('Payment Schedule')
                    ->schema([
                        Repeater::make('paymentScheduleRows')
                            ->label('Accommodation Schedule Rows')
                            ->relationship()
                            ->schema([
                                TextInput::make('sequence')
                                    ->integer()
                                    ->minValue(1)
                                    ->required(),
                                Select::make('category')
                                    ->options([
                                        PaymentScheduleRow::CategoryDownpayment => 'Downpayment',
                                        'installment' => 'Installment',
                                        'midterm' => 'Midterm',
                                        'final' => 'Final',
                                    ])
                                    ->required(),
                                DatePicker::make('due_date')
                                    ->required(),
                                TextInput::make('amount')
                                    ->prefix('PHP')
                                    ->numeric()
                                    ->minValue(0.01)
                                    ->required(),
                                Select::make('state')
                                    ->options([
                                        PaymentScheduleRow::StateDue => 'Due',
                                        'scheduled' => 'Scheduled',
                                        'paid' => 'Paid',
                                        'missed' => 'Missed',
                                        'cancelled' => 'Cancelled',
                                    ])
                                    ->default(PaymentScheduleRow::StateDue)
                                    ->required(),
                            ])
                            ->columns(5)
                            ->defaultItems(0)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
