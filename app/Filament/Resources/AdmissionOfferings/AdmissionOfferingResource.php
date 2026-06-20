<?php

namespace App\Filament\Resources\AdmissionOfferings;

use App\Filament\Resources\AdmissionOfferings\Pages\CreateAdmissionOffering;
use App\Filament\Resources\AdmissionOfferings\Pages\EditAdmissionOffering;
use App\Filament\Resources\AdmissionOfferings\Pages\ListAdmissionOfferings;
use App\Filament\Resources\AdmissionOfferings\Pages\ViewAdmissionOffering;
use App\Filament\Resources\AdmissionOfferings\Schemas\AdmissionOfferingForm;
use App\Filament\Resources\AdmissionOfferings\Schemas\AdmissionOfferingInfolist;
use App\Filament\Resources\AdmissionOfferings\Tables\AdmissionOfferingsTable;
use App\Models\AdmissionOffering;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AdmissionOfferingResource extends Resource
{
    protected static ?string $model = AdmissionOffering::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Admission Offerings';

    protected static ?int $navigationSort = 34;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return AdmissionOfferingForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AdmissionOfferingInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdmissionOfferingsTable::configure($table);
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
            'index' => ListAdmissionOfferings::route('/'),
            'create' => CreateAdmissionOffering::route('/create'),
            'view' => ViewAdmissionOffering::route('/{record}'),
            'edit' => EditAdmissionOffering::route('/{record}/edit'),
        ];
    }
}
