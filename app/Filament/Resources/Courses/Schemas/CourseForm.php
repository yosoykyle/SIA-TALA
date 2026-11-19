<?php

namespace App\Filament\Resources\Courses\Schemas;

use App\Models\Course;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class CourseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)
                ->schema([
                    TextInput::make('code')
                        ->label('Subject code')
                        ->required()
                        ->maxLength(64)
                        ->unique(ignoreRecord: true)
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : strtoupper(trim($state))),
                    Select::make('state')->label('Status')
                        ->options(Course::stateOptions())
                        ->default(Course::StateActive)
                        ->required(),
                ])
                ->columnSpanFull(),
        ]);
    }
}
