<?php

namespace App\Filament\Resources\PaymentAttempts\Schemas;

use App\Models\PaymentAttempt;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class PaymentAttemptInfolist
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
                TextEntry::make('assessment.enrollment.term.term_name')
                    ->label('Term')
                    ->placeholder('-'),
                TextEntry::make('assessment.enrollment.id')
                    ->label('Enrollment')
                    ->formatStateUsing(fn (?int $state, PaymentAttempt $record): string => $record->assessment?->enrollment === null
                        ? '-'
                        : $record->assessment->enrollment->displayLabel()),
                TextEntry::make('channel'),
                TextEntry::make('status'),
                TextEntry::make('provider')
                    ->placeholder('-'),
                TextEntry::make('internal_reference')
                    ->placeholder('-'),
                TextEntry::make('provider_checkout_id')
                    ->placeholder('-'),
                TextEntry::make('provider_intent_id')
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
