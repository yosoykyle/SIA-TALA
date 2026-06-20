<?php

namespace App\Filament\Resources\AdmissionRequirementPolicies\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AdmissionRequirementPolicyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Policy')
                    ->schema([
                        TextEntry::make('admissionOffering.name')->label('Offering'),
                        TextEntry::make('admissionOffering.term.term_name')->label('Term'),
                        TextEntry::make('version')->badge(),
                        TextEntry::make('source_label')->placeholder('-'),
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
