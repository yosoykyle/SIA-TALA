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
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RoomForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Room details')
                ->schema([
                    Group::make([
                        TextInput::make('code')->label('Room code')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->validationMessages(['unique' => 'This room code already exists. Edit the existing room.'])
                            ->mutateStateForValidationUsing(fn (?string $state): ?string => $state === null ? null : strtoupper(trim($state)))
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : strtoupper(trim($state))),
                        TextInput::make('name')
                            ->label('Room name')
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
                            ->suffix('seats'),
                        Toggle::make('is_active')
                            ->label('Active room')
                            ->default(true)
                            ->helperText('Inactive rooms are excluded from new scheduling assignments.'),
                    ])->columns(['default' => 1, 'md' => 2]),
                    Section::make('Equipment')
                        ->contained(false)
                        ->description('Record available equipment. For computers, only availability is recorded.')
                        ->schema([
                            Repeater::make('features')->label('Equipment')->relationship()
                                ->schema([RoomEquipment::select('feature_key')->label('Equipment')->required()])
                                ->defaultItems(0)->deleteAction(fn (Action $action): Action => $action->label('Discard unsaved equipment')->visible(fn (array $arguments): bool => ! str_starts_with((string) ($arguments['item'] ?? ''), 'record-')))->reorderable(false)->addActionLabel('Add equipment')->columnSpanFull()
                                ->helperText('Existing equipment can be corrected. Only unsaved additions can be removed.'),
                        ]),
                    Section::make('Notes')
                        ->contained(false)
                        ->schema([
                            Textarea::make('notes')
                                ->rows(3)
                                ->maxLength(2000)
                                ->columnSpanFull(),
                        ]),
                ])
                ->columns(1)
                ->divided()
                ->columnSpanFull(),
        ]);
    }
}
