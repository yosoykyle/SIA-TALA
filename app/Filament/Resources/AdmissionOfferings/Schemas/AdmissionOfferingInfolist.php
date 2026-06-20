<?php

namespace App\Filament\Resources\AdmissionOfferings\Schemas;

use App\Models\AdmissionOffering;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AdmissionOfferingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Offering')
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('term.term_name')->label('Term'),
                        TextEntry::make('program.name')->label('Program')->placeholder('All programs'),
                        TextEntry::make('education_level')
                            ->label('Level')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => AdmissionOffering::educationLevelOptions()[$state] ?? strtoupper($state)),
                        TextEntry::make('entry_route')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => AdmissionOffering::entryRouteOptions()[$state] ?? str($state)->headline()->toString()),
                        TextEntry::make('prior_credential_pathway')
                            ->label('Pathway')
                            ->placeholder('Regular')
                            ->formatStateUsing(fn (?string $state): string => $state === null ? 'Regular' : (AdmissionOffering::priorCredentialOptions()[$state] ?? str($state)->headline()->toString())),
                        TextEntry::make('year_level')->label('Year/Grade')->placeholder('All'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('published_at')->dateTime()->placeholder('Not published'),
                    ])
                    ->columns(2),
            ]);
    }
}
