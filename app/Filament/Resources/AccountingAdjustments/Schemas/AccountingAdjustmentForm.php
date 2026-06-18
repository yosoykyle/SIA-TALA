<?php

namespace App\Filament\Resources\AccountingAdjustments\Schemas;

use App\Models\AccountingAdjustment;
use App\Models\StudentProfile;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class AccountingAdjustmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('student_profile_id')
                    ->label('Student')
                    ->relationship('studentProfile', 'student_id')
                    ->getOptionLabelFromRecordUsing(fn (StudentProfile $record): string => AccountingAdjustment::studentOptionLabel($record))
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('term_id', null);
                        $set('enrollment_id', null);
                        $set('source_ledger_entry_id', null);
                    })
                    ->required(),
                Select::make('term_id')
                    ->relationship('term', 'term_name')
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('enrollment_id', null);
                        $set('source_ledger_entry_id', null);
                    }),
                Select::make('enrollment_id')
                    ->label('Enrollment')
                    ->options(fn (Get $get): array => AccountingAdjustment::enrollmentOptionsFor(
                        $get('student_profile_id'),
                        $get('term_id'),
                    ))
                    ->searchable()
                    ->preload()
                    ->live()
                    ->disabled(fn (Get $get): bool => blank($get('student_profile_id')))
                    ->helperText('Optional, but recommended when the adjustment belongs to a specific assessed enrollment.')
                    ->afterStateUpdated(function (Set $set): void {
                        $set('source_ledger_entry_id', null);
                    }),
                Select::make('adjustment_type')
                    ->label('Adjustment Type')
                    ->options(AccountingAdjustment::typeOptions())
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('source_ledger_entry_id', null);
                        $set('amount', null);
                    })
                    ->required(),
                Select::make('source_ledger_entry_id')
                    ->label('Source Ledger Entry')
                    ->options(fn (Get $get): array => AccountingAdjustment::sourceLedgerOptionsFor(
                        $get('student_profile_id'),
                        $get('term_id'),
                        $get('enrollment_id'),
                    ))
                    ->searchable()
                    ->preload()
                    ->disabled(fn (Get $get): bool => blank($get('student_profile_id')))
                    ->visible(fn (Get $get): bool => $get('adjustment_type') === AccountingAdjustment::TypeLedgerEntryReversal)
                    ->required(fn (Get $get): bool => $get('adjustment_type') === AccountingAdjustment::TypeLedgerEntryReversal)
                    ->helperText('Required for reversals. The service posts the exact opposite amount and blocks duplicate reversal.'),
                TextInput::make('amount')
                    ->prefix('PHP')
                    ->numeric()
                    ->minValue(0.01)
                    ->visible(fn (Get $get): bool => $get('adjustment_type') !== AccountingAdjustment::TypeLedgerEntryReversal)
                    ->required(fn (Get $get): bool => $get('adjustment_type') !== AccountingAdjustment::TypeLedgerEntryReversal)
                    ->helperText('Debit increases balance. Credit decreases balance. Reversal amount is computed from the source ledger entry.'),
                TextInput::make('evidence_reference')
                    ->label('Evidence Reference')
                    ->maxLength(255),
                DateTimePicker::make('posted_at')
                    ->label('Posted At')
                    ->default(fn (): CarbonImmutable => CarbonImmutable::now(config('app.timezone')))
                    ->maxDate(fn (): CarbonImmutable => CarbonImmutable::now(config('app.timezone')))
                    ->seconds(false)
                    ->required(),
                Textarea::make('reason')
                    ->required()
                    ->rows(3)
                    ->minLength(10)
                    ->maxLength(2000)
                    ->columnSpanFull(),
            ]);
    }
}
