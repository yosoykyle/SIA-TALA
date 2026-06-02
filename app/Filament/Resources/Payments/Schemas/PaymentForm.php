<?php

namespace App\Filament\Resources\Payments\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PaymentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('student_profile_id')
                    ->relationship('studentProfile', 'id')
                    ->required(),
                TextInput::make('term_id')
                    ->numeric(),
                Select::make('enrollment_id')
                    ->relationship('enrollment', 'id'),
                TextInput::make('payment_attempt_id')
                    ->numeric(),
                Select::make('ledger_entry_id')
                    ->relationship('ledgerEntry', 'id'),
                TextInput::make('payment_reference'),
                TextInput::make('channel')
                    ->required(),
                TextInput::make('amount')
                    ->required()
                    ->numeric(),
                TextInput::make('status')
                    ->required()
                    ->default('confirmed'),
                DateTimePicker::make('confirmed_at'),
                TextInput::make('confirmed_by')
                    ->numeric(),
                TextInput::make('meta'),
            ]);
    }
}
