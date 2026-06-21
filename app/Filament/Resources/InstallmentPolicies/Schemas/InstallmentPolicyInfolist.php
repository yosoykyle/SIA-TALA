<?php

namespace App\Filament\Resources\InstallmentPolicies\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class InstallmentPolicyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name'),
                TextEntry::make('program_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('year_level')
                    ->label('Year Level')
                    ->placeholder('-'),
                TextEntry::make('max_months')
                    ->numeric(),
                TextEntry::make('due_day_rule'),
                TextEntry::make('grace_days')
                    ->numeric(),
                TextEntry::make('penalty_rate')
                    ->numeric(),
                TextEntry::make('penalty_frequency'),
                IconEntry::make('allow_partial_payments')
                    ->boolean(),
                IconEntry::make('promissory_is_non_clearing')
                    ->boolean(),
                IconEntry::make('is_active')
                    ->boolean(),
                RepeatableEntry::make('milestones')
                    ->schema([
                        TextEntry::make('sequence')
                            ->numeric(),
                        TextEntry::make('month_offset')
                            ->label('Month Offset')
                            ->numeric(),
                        TextEntry::make('required_percentage')
                            ->label('Required')
                            ->suffix('%')
                            ->numeric(),
                        TextEntry::make('status')
                            ->badge(),
                    ])
                    ->columns(4),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
