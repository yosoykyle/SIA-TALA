<?php

namespace App\Filament\Resources\Rooms\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class RoomInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('code')->label('Code'),
            TextEntry::make('name')->placeholder('-'),
            TextEntry::make('building')->placeholder('-'),
            TextEntry::make('capacity')->numeric()->placeholder('-'),
            IconEntry::make('is_active')->label('Active')->boolean(),
            TextEntry::make('created_at')->dateTime()->placeholder('-'),
            TextEntry::make('updated_at')->dateTime()->placeholder('-'),
        ]);
    }
}
