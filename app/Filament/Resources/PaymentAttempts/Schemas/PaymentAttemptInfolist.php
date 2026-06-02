<?php

namespace App\Filament\Resources\PaymentAttempts\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class PaymentAttemptInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('student_profile_id')
                    ->numeric(),
                TextEntry::make('term_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('enrollment_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('ledger_entry_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('channel'),
                TextEntry::make('status'),
                TextEntry::make('provider')
                    ->placeholder('-'),
                TextEntry::make('provider_event_id')
                    ->placeholder('-'),
                TextEntry::make('provider_checkout_session_id')
                    ->placeholder('-'),
                TextEntry::make('provider_payment_id')
                    ->placeholder('-'),
                TextEntry::make('provider_payment_intent_id')
                    ->placeholder('-'),
                TextEntry::make('webhook_idempotency_key')
                    ->placeholder('-'),
                TextEntry::make('amount')
                    ->numeric(),
                TextEntry::make('paid_at')
                    ->dateTime()
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
