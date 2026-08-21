<?php

namespace App\Filament\Resources\FeePlans\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FeePlanInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Fee Plan authority')->schema([
                    TextEntry::make('program.code')->label('Program'),
                    TextEntry::make('term.label')->label('Term'),
                    TextEntry::make('version')->badge(),
                    TextEntry::make('state')->badge(),
                    TextEntry::make('authority_reference')->label('Authority')->placeholder('Required before publication'),
                    TextEntry::make('published_at')->dateTime()->placeholder('Draft'),
                ])->columns(3),
                Section::make('Fixed charges and obligations')->schema([
                    RepeatableEntry::make('charges')->label('')->schema([
                        TextEntry::make('code')->label('Code'),
                        TextEntry::make('label')->label('Charge'),
                        TextEntry::make('amount')->money('PHP'),
                    ])->columns(3),
                ]),
            ]);
    }
}
