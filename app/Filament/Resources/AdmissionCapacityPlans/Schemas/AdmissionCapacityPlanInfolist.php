<?php

namespace App\Filament\Resources\AdmissionCapacityPlans\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AdmissionCapacityPlanInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Capacity Plan')
                    ->schema([
                        TextEntry::make('term.term_name')->label('Term'),
                        TextEntry::make('scope_type')->label('Scope')->badge(),
                        TextEntry::make('education_level')->label('Level')->placeholder('All'),
                        TextEntry::make('program.name')->label('Program')->placeholder('All'),
                        TextEntry::make('year_level')->label('Year/Grade')->placeholder('All'),
                        TextEntry::make('delivery_setup')->label('Delivery')->placeholder('All'),
                        TextEntry::make('capacity_limit')->numeric(),
                        TextEntry::make('reserved_count')->numeric(),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('effective_from')->dateTime()->placeholder('-'),
                        TextEntry::make('effective_until')->dateTime()->placeholder('-'),
                        TextEntry::make('approver.name')->label('Approved by')->placeholder('-'),
                        TextEntry::make('approved_at')->dateTime()->placeholder('-'),
                    ])
                    ->columns(2),
            ]);
    }
}
