<?php

namespace App\Filament\Resources\ScheduleGenerationRuns;

use App\Filament\Resources\ScheduleGenerationRuns\Pages\ListScheduleGenerationRuns;
use App\Filament\Resources\ScheduleGenerationRuns\Pages\ViewScheduleGenerationRun;
use App\Filament\Resources\ScheduleGenerationRuns\RelationManagers\CandidateRowsRelationManager;
use App\Filament\Resources\ScheduleGenerationRuns\Schemas\ScheduleGenerationRunInfolist;
use App\Filament\Resources\ScheduleGenerationRuns\Tables\ScheduleGenerationRunsTable;
use App\Models\ScheduleGenerationRun;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ScheduleGenerationRunResource extends Resource
{
    protected static ?string $model = ScheduleGenerationRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Solver Runs';

    protected static ?int $navigationSort = 30;

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
        return ScheduleGenerationRunInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ScheduleGenerationRunsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            CandidateRowsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScheduleGenerationRuns::route('/'),
            'view' => ViewScheduleGenerationRun::route('/{record}'),
        ];
    }
}
