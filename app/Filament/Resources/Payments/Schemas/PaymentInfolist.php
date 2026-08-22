<?php

namespace App\Filament\Resources\Payments\Schemas;

use App\Models\PaymentAllocation;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PaymentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Immutable posting')->schema([
                TextEntry::make('provider_reference')->label('Posting reference'),
                TextEntry::make('termAccount.credentialUser.email')->label('Learner credential'),
                TextEntry::make('term.label')->label('Term'),
                TextEntry::make('channel')->badge(),
                TextEntry::make('amount')->money('PHP'),
                TextEntry::make('state')->badge(),
                TextEntry::make('verification_basis')->label('Verification basis'),
                TextEntry::make('external_check_reference')->label('Independent source result'),
                TextEntry::make('paid_at')->dateTime(),
                TextEntry::make('verified_at')->dateTime(),
                TextEntry::make('verifier.name')->label('Verified by')->placeholder('System'),
                TextEntry::make('reversal_authority_reference')->label('Reversal authority')->placeholder('Not reversed'),
            ])->columns(3),
            Section::make('Exact obligation effects')->schema([
                RepeatableEntry::make('allocations')->hiddenLabel()->schema([
                    TextEntry::make('sequence'),
                    TextEntry::make('target')->label('Applied to')->state(fn (PaymentAllocation $record): string => $record->assessmentObligation->label),
                    TextEntry::make('amount')->money('PHP'),
                ])->columns(3)->columnSpanFull(),
            ]),
        ]);
    }
}
