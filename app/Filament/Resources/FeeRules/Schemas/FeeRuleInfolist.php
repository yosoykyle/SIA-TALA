<?php

namespace App\Filament\Resources\FeeRules\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FeeRuleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Fee Rule')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('code'),
                                TextEntry::make('name'),
                                TextEntry::make('is_active')
                                    ->label('Active')
                                    ->badge(),
                                TextEntry::make('ledger_category')
                                    ->badge(),
                                TextEntry::make('display_category')
                                    ->badge(),
                                TextEntry::make('calculation_type')
                                    ->badge(),
                                TextEntry::make('program.name')
                                    ->placeholder('All programs'),
                                TextEntry::make('term.label')
                                    ->placeholder('All terms'),
                                TextEntry::make('amount')
                                    ->money('PHP')
                                    ->placeholder('-'),
                                TextEntry::make('rate')
                                    ->label('Per-unit rate')
                                    ->money('PHP')
                                    ->placeholder('-'),
                                TextEntry::make('effective_from')
                                    ->date(),
                                TextEntry::make('effective_until')
                                    ->date()
                                    ->placeholder('-'),
                                TextEntry::make('authority')
                                    ->columnSpanFull(),
                                IconEntry::make('is_active')
                                    ->label('Active State')
                                    ->boolean(),
                            ]),
                    ]),
            ]);
    }
}
