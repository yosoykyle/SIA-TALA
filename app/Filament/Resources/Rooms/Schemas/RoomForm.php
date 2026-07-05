<?php

namespace App\Filament\Resources\Rooms\Schemas;

use App\Models\Room;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RoomForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Room and Facility Source Record')
                ->description('Maintain room capacity, type, active status, and notes used by section planning and scheduling readiness.')
                ->schema([
                    TextInput::make('code')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : strtoupper(trim($state))),
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('building')
                        ->maxLength(255),
                    Select::make('room_type')
                        ->options(Room::typeOptions())
                        ->required()
                        ->searchable()
                        ->native(false),
                    TextInput::make('capacity')
                        ->required()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(999)
                        ->helperText('Physical capacity used by readiness and room suitability checks.'),
                    Toggle::make('is_active')
                        ->label('Active Room')
                        ->default(true),
                    Textarea::make('notes')
                        ->rows(3)
                        ->maxLength(2000)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
