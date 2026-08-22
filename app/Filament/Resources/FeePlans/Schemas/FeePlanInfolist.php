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
                    TextEntry::make('authority_date')->label('Authority date')->date()->placeholder('Required before publication'),
                    TextEntry::make('published_at')->dateTime()->placeholder('Draft'),
                ])->columns(3),
                Section::make('Fixed charges and obligations')->schema([
                    RepeatableEntry::make('charges')->label('')->schema([
                        TextEntry::make('code')->label('Code'),
                        TextEntry::make('label')->label('Charge'),
                        TextEntry::make('category')->placeholder('Uncategorized'),
                        TextEntry::make('amount')->money('PHP'),
                    ])->columns(4),
                    RepeatableEntry::make('obligations')->label('Dated obligations')->schema([
                        TextEntry::make('code'),
                        TextEntry::make('label'),
                        TextEntry::make('purpose'),
                        TextEntry::make('amount')->money('PHP'),
                        TextEntry::make('due_at')->dateTime(),
                    ])->columns(5),
                ]),
            ]);
    }
}
