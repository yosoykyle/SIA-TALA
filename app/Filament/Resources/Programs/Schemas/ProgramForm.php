<?php

namespace App\Filament\Resources\Programs\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class ProgramForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'sm' => 4])
                ->schema([
                    TextInput::make('code')->label('Program code')
                        ->placeholder('e.g., BSIT')
                        ->required()
                        ->maxLength(64)
                        ->unique(ignoreRecord: true)
                        ->columnSpan(['default' => 1, 'sm' => 1])
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : strtoupper(trim($state))),
                    TextInput::make('name')->label('Program name')
                        ->placeholder('e.g., Bachelor of Science in Information Technology')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(['default' => 1, 'sm' => 3]),
                    TextInput::make('duration_years')
                        ->label('Max year (years)')
                        ->integer()
                        ->default(4)
                        ->minValue(1)
                        ->maxValue(5)
                        ->step(1)
                        ->required()
                        ->columnSpan(['default' => 1, 'sm' => 2])
                        ->helperText('Standard program length (1 to 5 years; sets study plan year levels).'),
                    Toggle::make('is_active')
                        ->label('Active program')->inline(false)
                        ->default(true)
                        ->required()
                        ->columnSpan(['default' => 1, 'sm' => 2]),
                    Textarea::make('description')
                        ->label('Program description')
                        ->placeholder('e.g., Prepares students for software development, networking, and IT systems administration.')
                        ->helperText('Displayed to prospective applicants on the public school page and program overview.')
                        ->maxLength(2000)
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
        ]);
    }
}
