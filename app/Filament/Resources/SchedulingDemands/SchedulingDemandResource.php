<?php

namespace App\Filament\Resources\SchedulingDemands;

use App\Filament\Resources\SchedulingDemands\Pages\ListSchedulingDemands;
use App\Filament\Resources\SchedulingDemands\Pages\ViewSchedulingDemand;
use App\Filament\Resources\SchedulingDemands\Schemas\SchedulingDemandInfolist;
use App\Filament\Resources\SchedulingDemands\Tables\SchedulingDemandsTable;
use App\Models\SchedulingDemand;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SchedulingDemandResource extends Resource
{
    protected static ?string $model = SchedulingDemand::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Scheduling Demand';

    protected static ?int $navigationSort = 29;

    protected static ?string $recordTitleAttribute = 'demand_key';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        if (auth()->user()?->hasRole(User::StaffRoleAcademicHead)) {
            return 'Academic Head';
        }

        return 'Registrar';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
        ]) ?? false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return SchedulingDemandInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SchedulingDemandsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSchedulingDemands::route('/'),
            'view' => ViewSchedulingDemand::route('/{record}'),
        ];
    }
}
