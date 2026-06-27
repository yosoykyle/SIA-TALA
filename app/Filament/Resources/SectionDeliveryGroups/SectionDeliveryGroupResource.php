<?php

namespace App\Filament\Resources\SectionDeliveryGroups;

use App\Filament\Resources\SectionDeliveryGroups\Pages\CreateSectionDeliveryGroup;
use App\Filament\Resources\SectionDeliveryGroups\Pages\EditSectionDeliveryGroup;
use App\Filament\Resources\SectionDeliveryGroups\Pages\ListSectionDeliveryGroups;
use App\Filament\Resources\SectionDeliveryGroups\Pages\ViewSectionDeliveryGroup;
use App\Filament\Resources\SectionDeliveryGroups\Schemas\SectionDeliveryGroupForm;
use App\Filament\Resources\SectionDeliveryGroups\Schemas\SectionDeliveryGroupInfolist;
use App\Filament\Resources\SectionDeliveryGroups\Tables\SectionDeliveryGroupsTable;
use App\Models\SectionDeliveryGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SectionDeliveryGroupResource extends Resource
{
    protected static ?string $model = SectionDeliveryGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Section Delivery Groups';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        if (auth()->user()?->hasRole('academic-head')) {
            return 'Academic Head';
        }

        return 'Registrar';
    }

    public static function form(Schema $schema): Schema
    {
        return SectionDeliveryGroupForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SectionDeliveryGroupInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SectionDeliveryGroupsTable::configure($table);
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
            'index' => ListSectionDeliveryGroups::route('/'),
            'create' => CreateSectionDeliveryGroup::route('/create'),
            'view' => ViewSectionDeliveryGroup::route('/{record}'),
            'edit' => EditSectionDeliveryGroup::route('/{record}/edit'),
        ];
    }
}
