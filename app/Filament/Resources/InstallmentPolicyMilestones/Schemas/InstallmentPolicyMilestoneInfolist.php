<?php

namespace App\Filament\Resources\InstallmentPolicyMilestones\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class InstallmentPolicyMilestoneInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('installment_policy_id')
                    ->numeric(),
                TextEntry::make('sequence')
                    ->numeric(),
                TextEntry::make('month_offset')
                    ->numeric(),
                TextEntry::make('required_percentage')
                    ->numeric(),
                TextEntry::make('status'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
