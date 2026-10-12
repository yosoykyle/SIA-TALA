<?php

namespace App\Filament\Resources\Rooms\Schemas;

use App\Models\Room;
use App\Support\RoomEquipment;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
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
            Section::make('Room details')
                ->description('Record capacity, room type, active status and notes for class scheduling.')
                ->schema([
                    TextInput::make('code')->label('Room code')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->validationMessages(['unique' => 'This room code already exists. Edit the existing room.'])
                        ->mutateStateForValidationUsing(fn (?string $state): ?string => $state === null ? null : strtoupper(trim($state)))
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
                        ->label('Active room')
                        ->default(true),
                    Repeater::make('features')->label('Equipment')->relationship()
                        ->schema([RoomEquipment::select('feature_key')->label('Equipment')->required()])
                        ->defaultItems(0)->deleteAction(fn (Action $action): Action => $action->label('Discard unsaved equipment')->visible(fn (array $arguments): bool => ! str_starts_with((string) ($arguments['item'] ?? ''), 'record-')))->reorderable(false)->addActionLabel('Add equipment')->columnSpanFull()
                        ->helperText('Choose the equipment available in this room. Computers means computers are available; no count is recorded. You can correct existing equipment or discard unsaved additions.'),
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
