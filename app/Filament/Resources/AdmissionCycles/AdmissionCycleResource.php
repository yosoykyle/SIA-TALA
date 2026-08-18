<?php

namespace App\Filament\Resources\AdmissionCycles;

use App\Filament\Resources\AdmissionCycles\Pages\CreateAdmissionCycle;
use App\Filament\Resources\AdmissionCycles\Pages\EditAdmissionCycle;
use App\Filament\Resources\AdmissionCycles\Pages\ListAdmissionCycles;
use App\Filament\Resources\AdmissionCycles\Pages\ViewAdmissionCycle;
use App\Filament\Resources\AdmissionCycles\RelationManagers\RequirementSetsRelationManager;
use App\Filament\Resources\AdmissionCycles\Schemas\AdmissionCycleForm;
use App\Filament\Resources\AdmissionCycles\Schemas\AdmissionCycleInfolist;
use App\Filament\Resources\AdmissionCycles\Tables\AdmissionCyclesTable;
use App\Models\AdmissionCycle;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AdmissionCycleResource extends Resource
{
    protected static ?string $model = AdmissionCycle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDateRange;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Admission Cycles';

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        return AdmissionCycleForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AdmissionCycleInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdmissionCyclesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RequirementSetsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdmissionCycles::route('/'),
            'create' => CreateAdmissionCycle::route('/create'),
            'view' => ViewAdmissionCycle::route('/{record}'),
            'edit' => EditAdmissionCycle::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'term',
            'registrarOwner',
            'programs',
            'events.actor',
            'requirementSets.requirements',
        ]);
    }
}
