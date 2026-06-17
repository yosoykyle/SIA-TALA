<?php

namespace App\Filament\Resources\DeliveryPatterns;

use App\Filament\Resources\DeliveryPatterns\Pages\CreateDeliveryPattern;
use App\Filament\Resources\DeliveryPatterns\Pages\EditDeliveryPattern;
use App\Filament\Resources\DeliveryPatterns\Pages\ListDeliveryPatterns;
use App\Filament\Resources\DeliveryPatterns\Pages\ViewDeliveryPattern;
use App\Filament\Resources\DeliveryPatterns\Schemas\DeliveryPatternForm;
use App\Filament\Resources\DeliveryPatterns\Schemas\DeliveryPatternInfolist;
use App\Filament\Resources\DeliveryPatterns\Tables\DeliveryPatternsTable;
use App\Models\DeliveryPattern;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DeliveryPatternResource extends Resource
{
    protected static ?string $model = DeliveryPattern::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Delivery Patterns';

    protected static ?int $navigationSort = 28;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return DeliveryPatternForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DeliveryPatternInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DeliveryPatternsTable::configure($table);
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
            'index' => ListDeliveryPatterns::route('/'),
            'create' => CreateDeliveryPattern::route('/create'),
            'view' => ViewDeliveryPattern::route('/{record}'),
            'edit' => EditDeliveryPattern::route('/{record}/edit'),
        ];
    }
}
