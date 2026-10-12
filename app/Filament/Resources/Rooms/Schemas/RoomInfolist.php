<?php

namespace App\Filament\Resources\Rooms\Schemas;

use App\Models\Room;
use App\Support\RoomEquipment;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RoomInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Room details')
                ->schema([
                    TextEntry::make('code')->label('Code')->copyable(),
                    TextEntry::make('name')->placeholder('-'),
                    TextEntry::make('building')->placeholder('-'),
                    TextEntry::make('room_type')
                        ->label('Room type')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (Room::typeOptions()[$state] ?? str($state)->headline()->toString())),
                    TextEntry::make('capacity')->numeric()->suffix(' seats')->placeholder('-'),
                    IconEntry::make('is_active')->label('Active')->boolean(),
                    TextEntry::make('features.feature_key')
                        ->label('Equipment')->formatStateUsing(fn (?string $state): string => RoomEquipment::label($state))
                        ->badge()
                        ->separator(',')
                        ->placeholder('-'),
                    TextEntry::make('notes')->placeholder('-')->columnSpanFull(),

                ])
                ->columns(['default' => 1, 'md' => 2])
                ->columnSpanFull(),
            Section::make('Record details')->collapsible()->collapsed()->schema([
                TextEntry::make('created_at')->label('Created')->dateTime(),
                TextEntry::make('updated_at')->label('Last updated')->dateTime(),
            ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
        ]);
    }
}
