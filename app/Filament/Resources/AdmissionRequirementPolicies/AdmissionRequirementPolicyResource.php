<?php

namespace App\Filament\Resources\AdmissionRequirementPolicies;

use App\Filament\Resources\AdmissionRequirementPolicies\Pages\CreateAdmissionRequirementPolicy;
use App\Filament\Resources\AdmissionRequirementPolicies\Pages\EditAdmissionRequirementPolicy;
use App\Filament\Resources\AdmissionRequirementPolicies\Pages\ListAdmissionRequirementPolicies;
use App\Filament\Resources\AdmissionRequirementPolicies\Pages\ViewAdmissionRequirementPolicy;
use App\Filament\Resources\AdmissionRequirementPolicies\Schemas\AdmissionRequirementPolicyForm;
use App\Filament\Resources\AdmissionRequirementPolicies\Schemas\AdmissionRequirementPolicyInfolist;
use App\Filament\Resources\AdmissionRequirementPolicies\Tables\AdmissionRequirementPoliciesTable;
use App\Models\AdmissionRequirementPolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AdmissionRequirementPolicyResource extends Resource
{
    protected static ?string $model = AdmissionRequirementPolicy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Admission Requirement Policies';

    protected static ?int $navigationSort = 35;

    public static function form(Schema $schema): Schema
    {
        return AdmissionRequirementPolicyForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AdmissionRequirementPolicyInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdmissionRequirementPoliciesTable::configure($table);
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
            'index' => ListAdmissionRequirementPolicies::route('/'),
            'create' => CreateAdmissionRequirementPolicy::route('/create'),
            'view' => ViewAdmissionRequirementPolicy::route('/{record}'),
            'edit' => EditAdmissionRequirementPolicy::route('/{record}/edit'),
        ];
    }
}
