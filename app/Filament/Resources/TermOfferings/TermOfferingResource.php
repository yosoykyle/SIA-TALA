<?php

namespace App\Filament\Resources\TermOfferings;

use App\Filament\Resources\TermOfferings\Pages\ListTermOfferings;
use App\Filament\Resources\TermOfferings\Tables\TermOfferingsTable;
use App\Models\TermOffering;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class TermOfferingResource extends Resource
{
    protected static ?string $model = TermOffering::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Term Offerings';

    public static function table(Table $table): Table
    {
        return TermOfferingsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTermOfferings::route('/'),
        ];
    }
}
