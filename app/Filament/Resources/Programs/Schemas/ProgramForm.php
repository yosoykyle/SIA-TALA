<?php

namespace App\Filament\Resources\Programs\Schemas;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProgramForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Program Details')
                ->description('Maintain the canonical program catalog used by enrollment, finance, curriculum, and scheduling.')
                ->schema([
                    TextInput::make('code')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : strtoupper(trim($state))),
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    Hidden::make('department')
                        ->default('college')
                        ->dehydrated(),
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->required(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
