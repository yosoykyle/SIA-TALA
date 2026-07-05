<?php

namespace App\Filament\Resources\FinancialAccommodations;

use App\Filament\Resources\FinancialAccommodations\Pages\CreateFinancialAccommodation;
use App\Filament\Resources\FinancialAccommodations\Pages\ListFinancialAccommodations;
use App\Filament\Resources\FinancialAccommodations\Pages\ViewFinancialAccommodation;
use App\Filament\Resources\FinancialAccommodations\Schemas\FinancialAccommodationForm;
use App\Filament\Resources\FinancialAccommodations\Schemas\FinancialAccommodationInfolist;
use App\Filament\Resources\FinancialAccommodations\Tables\FinancialAccommodationsTable;
use App\Models\FinancialAccommodation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FinancialAccommodationResource extends Resource
{
    protected static ?string $model = FinancialAccommodation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $navigationLabel = 'Financial Accommodations';

    protected static ?int $navigationSort = 24;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return 'Accounting';
    }

    public static function form(Schema $schema): Schema
    {
        return FinancialAccommodationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FinancialAccommodationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FinancialAccommodationsTable::configure($table);
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
            'index' => ListFinancialAccommodations::route('/'),
            'create' => CreateFinancialAccommodation::route('/create'),
            'view' => ViewFinancialAccommodation::route('/{record}'),
        ];
    }
}
