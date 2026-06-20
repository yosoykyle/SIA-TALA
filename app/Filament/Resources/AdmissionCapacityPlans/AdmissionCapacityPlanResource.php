<?php

namespace App\Filament\Resources\AdmissionCapacityPlans;

use App\Filament\Resources\AdmissionCapacityPlans\Pages\CreateAdmissionCapacityPlan;
use App\Filament\Resources\AdmissionCapacityPlans\Pages\EditAdmissionCapacityPlan;
use App\Filament\Resources\AdmissionCapacityPlans\Pages\ListAdmissionCapacityPlans;
use App\Filament\Resources\AdmissionCapacityPlans\Pages\ViewAdmissionCapacityPlan;
use App\Filament\Resources\AdmissionCapacityPlans\Schemas\AdmissionCapacityPlanForm;
use App\Filament\Resources\AdmissionCapacityPlans\Schemas\AdmissionCapacityPlanInfolist;
use App\Filament\Resources\AdmissionCapacityPlans\Tables\AdmissionCapacityPlansTable;
use App\Models\AdmissionCapacityPlan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AdmissionCapacityPlanResource extends Resource
{
    protected static ?string $model = AdmissionCapacityPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Admission Capacity Plans';

    protected static ?int $navigationSort = 37;

    public static function form(Schema $schema): Schema
    {
        return AdmissionCapacityPlanForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AdmissionCapacityPlanInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdmissionCapacityPlansTable::configure($table);
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
            'index' => ListAdmissionCapacityPlans::route('/'),
            'create' => CreateAdmissionCapacityPlan::route('/create'),
            'view' => ViewAdmissionCapacityPlan::route('/{record}'),
            'edit' => EditAdmissionCapacityPlan::route('/{record}/edit'),
        ];
    }
}
