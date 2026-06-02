<?php

namespace App\Filament\Resources\PaymentAttempts\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PaymentAttemptForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('student_profile_id')
                    ->required()
                    ->numeric(),
                TextInput::make('term_id')
                    ->numeric(),
                TextInput::make('enrollment_id')
                    ->numeric(),
                TextInput::make('ledger_entry_id')
                    ->numeric(),
                TextInput::make('channel')
                    ->required(),
                TextInput::make('status')
                    ->required()
                    ->default('pending'),
                TextInput::make('provider'),
                TextInput::make('provider_event_id'),
                TextInput::make('provider_checkout_session_id'),
                TextInput::make('provider_payment_id'),
                TextInput::make('provider_payment_intent_id'),
                TextInput::make('webhook_idempotency_key'),
                TextInput::make('amount')
                    ->required()
                    ->numeric(),
                TextInput::make('meta'),
                DateTimePicker::make('paid_at'),
            ]);
    }
}
