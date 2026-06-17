<?php

namespace App\Filament\Resources\Rooms\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RoomForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Room Catalog')
                ->description('Maintain active physical rooms used by section planning and the scheduling solver.')
                ->schema([
                    TextInput::make('code')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : strtoupper(trim($state))),
                    TextInput::make('name')
                        ->maxLength(255),
                    TextInput::make('building')
                        ->maxLength(255),
                    TextInput::make('capacity')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(999)
                        ->helperText('Optional physical capacity reference. Section max seats remains capped separately at 30 for TAL-12.'),
                    Toggle::make('is_active')
                        ->label('Active Room')
                        ->default(true),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
