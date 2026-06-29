<?php

namespace App\Filament\Resources\SectionMeetings;

use App\Filament\Resources\SectionMeetings\Pages\ListSectionMeetings;
use App\Filament\Resources\SectionMeetings\Pages\ViewSectionMeeting;
use App\Filament\Resources\SectionMeetings\Schemas\SectionMeetingInfolist;
use App\Filament\Resources\SectionMeetings\Tables\SectionMeetingsTable;
use App\Models\SectionMeeting;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SectionMeetingResource extends Resource
{
    protected static ?string $model = SectionMeeting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Official Schedules';

    protected static ?int $navigationSort = 31;

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
            User::StaffRoleSystemSuperAdmin,
        ]) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return SectionMeetingInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SectionMeetingsTable::configure($table);
    }

    /**
     * @return Builder<SectionMeeting>
     */
    public static function getEloquentQuery(): Builder
    {
        return SectionMeeting::query()->activeOfficial();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSectionMeetings::route('/'),
            'view' => ViewSectionMeeting::route('/{record}'),
        ];
    }
}
