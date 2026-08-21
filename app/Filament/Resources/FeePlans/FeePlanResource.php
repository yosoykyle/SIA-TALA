<?php

namespace App\Filament\Resources\FeePlans;

use App\Filament\Resources\FeePlans\Pages\ListFeePlans;
use App\Filament\Resources\FeePlans\Pages\ViewFeePlan;
use App\Filament\Resources\FeePlans\Schemas\FeePlanInfolist;
use App\Filament\Resources\FeePlans\Tables\FeePlansTable;
use App\Models\FeePlan;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FeePlanResource extends Resource
{
    protected static ?string $model = FeePlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $navigationLabel = 'Fee Plans';

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasRole(User::StaffRoleAccounting) ?? false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return FeePlanInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FeePlansTable::configure($table);
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
            'index' => ListFeePlans::route('/'),
            'view' => ViewFeePlan::route('/{record}'),
        ];
    }
}
