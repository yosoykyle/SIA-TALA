<?php

namespace App\Filament\Resources\Programs\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class ProgramForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)
                ->schema([
                    TextInput::make('code')->label('Program code')
                        ->required()
                        ->maxLength(64)
                        ->unique(ignoreRecord: true)
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : strtoupper(trim($state))),
                    TextInput::make('name')->label('Program name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('duration_years')
                        ->label('Length (years)')
                        ->integer()
                        ->default(4)
                        ->minValue(1)
                        ->maxValue(8)
                        ->step(1)
                        ->required()
                        ->helperText('Standard program length in years (sets study plan year levels).'),
                    Toggle::make('is_active')
                        ->label('Active program')->inline(false)
                        ->default(true)
                        ->required(),
                ])
                ->columnSpanFull(),
        ]);
    }
}
