<?php

namespace App\Filament\Resources\AdmissionRequirementPolicies\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AdmissionRequirementPolicyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Admission Requirement Policy')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('admission_category')
                                    ->badge(),
                                TextEntry::make('credential_basis')
                                    ->badge(),
                                TextEntry::make('requirement_type'),
                                TextEntry::make('evidence_method')
                                    ->badge(),
                                TextEntry::make('blocking_level')
                                    ->badge(),
                                TextEntry::make('state')
                                    ->badge(),
                                TextEntry::make('effective_from')
                                    ->date(),
                                TextEntry::make('effective_until')
                                    ->date()
                                    ->placeholder('-'),
                                TextEntry::make('authority')
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }
}
