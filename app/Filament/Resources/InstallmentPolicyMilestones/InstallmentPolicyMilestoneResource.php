<?php

namespace App\Filament\Resources\InstallmentPolicyMilestones;

use App\Filament\Resources\InstallmentPolicyMilestones\Pages\ListInstallmentPolicyMilestones;
use App\Filament\Resources\InstallmentPolicyMilestones\Pages\ViewInstallmentPolicyMilestone;
use App\Filament\Resources\InstallmentPolicyMilestones\Schemas\InstallmentPolicyMilestoneInfolist;
use App\Filament\Resources\InstallmentPolicyMilestones\Tables\InstallmentPolicyMilestonesTable;
use App\Models\InstallmentPolicyMilestone;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class InstallmentPolicyMilestoneResource extends Resource
{
    protected static ?string $model = InstallmentPolicyMilestone::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $navigationLabel = 'Installment Milestones';

    protected static ?int $navigationSort = 41;

    public static function infolist(Schema $schema): Schema
    {
        return InstallmentPolicyMilestoneInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InstallmentPolicyMilestonesTable::configure($table);
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
            'index' => ListInstallmentPolicyMilestones::route('/'),
            'view' => ViewInstallmentPolicyMilestone::route('/{record}'),
        ];
    }
}
