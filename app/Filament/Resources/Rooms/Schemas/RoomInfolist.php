<?php

namespace App\Filament\Resources\Rooms\Schemas;

use App\Models\Room;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RoomInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Room and Facility Source Record')
                ->schema([
                    TextEntry::make('code')->label('Code')->copyable(),
                    TextEntry::make('name')->placeholder('-'),
                    TextEntry::make('building')->placeholder('-'),
                    TextEntry::make('room_type')
                        ->label('Room Type')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (Room::typeOptions()[$state] ?? str($state)->headline()->toString())),
                    TextEntry::make('capacity')->numeric()->suffix(' seats')->placeholder('-'),
                    IconEntry::make('is_active')->label('Active')->boolean(),
                    TextEntry::make('features.feature_key')
                        ->label('Features')
                        ->badge()
                        ->separator(',')
                        ->placeholder('-'),
                    TextEntry::make('notes')->placeholder('-')->columnSpanFull(),
                    TextEntry::make('created_at')->dateTime()->placeholder('-'),
                    TextEntry::make('updated_at')->dateTime()->placeholder('-'),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
