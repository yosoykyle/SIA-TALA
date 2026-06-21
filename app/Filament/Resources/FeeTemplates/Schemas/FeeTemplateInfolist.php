<?php

namespace App\Filament\Resources\FeeTemplates\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class FeeTemplateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name'),
                TextEntry::make('program.name')
                    ->label('Program')
                    ->placeholder('-'),
                TextEntry::make('year_level')
                    ->label('Year Level')
                    ->placeholder('-'),
                TextEntry::make('tuition_fee')
                    ->numeric(),
                TextEntry::make('laboratory_fee')
                    ->numeric(),
                TextEntry::make('misc_fee')
                    ->numeric(),
                TextEntry::make('other_fee')
                    ->numeric(),
                TextEntry::make('minimum_downpayment_percentage')
                    ->numeric(),
                IconEntry::make('is_active')
                    ->boolean(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
