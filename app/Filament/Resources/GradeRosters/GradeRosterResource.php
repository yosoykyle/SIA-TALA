<?php

namespace App\Filament\Resources\GradeRosters;

use App\Filament\Resources\GradeRosters\Pages\ListGradeRosters;
use App\Filament\Resources\GradeRosters\Pages\ViewGradeRoster;
use App\Filament\Resources\GradeRosters\Schemas\GradeRosterInfolist;
use App\Filament\Resources\GradeRosters\Tables\GradeRostersTable;
use App\Models\GradeRoster;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class GradeRosterResource extends Resource
{
    protected static ?string $model = GradeRoster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Grade Rosters';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return auth()->user()?->hasRole(User::StaffRoleAcademicHead) ? 'Academic Head' : 'Registrar';
    }

    public static function table(Table $table): Table
    {
        return GradeRostersTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return GradeRosterInfolist::configure($schema);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAcademicHead]) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGradeRosters::route('/'),
            'view' => ViewGradeRoster::route('/{record}'),
        ];
    }
}
