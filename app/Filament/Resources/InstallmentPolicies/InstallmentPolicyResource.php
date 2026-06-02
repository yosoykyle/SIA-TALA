<?php

namespace App\Filament\Resources\InstallmentPolicies;

use App\Filament\Resources\InstallmentPolicies\Pages\CreateInstallmentPolicy;
use App\Filament\Resources\InstallmentPolicies\Pages\EditInstallmentPolicy;
use App\Filament\Resources\InstallmentPolicies\Pages\ListInstallmentPolicies;
use App\Filament\Resources\InstallmentPolicies\Pages\ViewInstallmentPolicy;
use App\Filament\Resources\InstallmentPolicies\Schemas\InstallmentPolicyForm;
use App\Filament\Resources\InstallmentPolicies\Schemas\InstallmentPolicyInfolist;
use App\Filament\Resources\InstallmentPolicies\Tables\InstallmentPoliciesTable;
use App\Models\InstallmentPolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class InstallmentPolicyResource extends Resource
{
    protected static ?string $model = InstallmentPolicy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $navigationLabel = 'Installment Policies';

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        if (auth()->user()?->hasRole('academic-head')) {
            return 'Academic Head';
        }

        return 'Accounting';
    }

    public static function form(Schema $schema): Schema
    {
        return InstallmentPolicyForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return InstallmentPolicyInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InstallmentPoliciesTable::configure($table);
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
            'index' => ListInstallmentPolicies::route('/'),
            'create' => CreateInstallmentPolicy::route('/create'),
            'view' => ViewInstallmentPolicy::route('/{record}'),
            'edit' => EditInstallmentPolicy::route('/{record}/edit'),
        ];
    }
}
