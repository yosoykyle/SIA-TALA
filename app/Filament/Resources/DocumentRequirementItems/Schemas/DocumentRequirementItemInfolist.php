<?php

namespace App\Filament\Resources\DocumentRequirementItems\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DocumentRequirementItemInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Requirement Item')
                    ->schema([
                        TextEntry::make('label'),
                        TextEntry::make('key')->copyable(),
                        TextEntry::make('admissionRequirementPolicy.admissionOffering.name')->label('Offering'),
                        TextEntry::make('admissionRequirementPolicy.version')->label('Policy version')->badge(),
                        TextEntry::make('gate_type')->badge(),
                        TextEntry::make('permitted_evidence_methods')->badge()->separator(','),
                        TextEntry::make('storage_class')->badge(),
                        TextEntry::make('sensitivity_class')->badge(),
                        TextEntry::make('ocr_policy')->badge(),
                        TextEntry::make('deadline_strategy')->placeholder('-'),
                        TextEntry::make('retention_policy')->placeholder('-'),
                    ])
                    ->columns(2),
            ]);
    }
}
