<?php

namespace App\Filament\Resources\OperationalEvents;

use App\Filament\Resources\OperationalEvents\Pages\ListOperationalEvents;
use App\Filament\Resources\OperationalEvents\Pages\ViewOperationalEvent;
use App\Filament\Resources\OperationalEvents\Schemas\OperationalEventInfolist;
use App\Filament\Resources\OperationalEvents\Tables\OperationalEventsTable;
use App\Models\OperationalEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class OperationalEventResource extends Resource
{
    protected static ?string $model = OperationalEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'System Administration';

    protected static ?string $navigationLabel = 'Operational Events';

    protected static ?int $navigationSort = 6;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return OperationalEventInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OperationalEventsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOperationalEvents::route('/'),
            'view' => ViewOperationalEvent::route('/{record}'),
        ];
    }
}
