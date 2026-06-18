<?php

namespace App\Filament\Resources\AccountingAdjustments;

use App\Filament\Resources\AccountingAdjustments\Pages\CreateAccountingAdjustment;
use App\Filament\Resources\AccountingAdjustments\Pages\ListAccountingAdjustments;
use App\Filament\Resources\AccountingAdjustments\Pages\ViewAccountingAdjustment;
use App\Filament\Resources\AccountingAdjustments\Schemas\AccountingAdjustmentForm;
use App\Filament\Resources\AccountingAdjustments\Schemas\AccountingAdjustmentInfolist;
use App\Filament\Resources\AccountingAdjustments\Tables\AccountingAdjustmentsTable;
use App\Models\AccountingAdjustment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AccountingAdjustmentResource extends Resource
{
    protected static ?string $model = AccountingAdjustment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $navigationLabel = 'Accounting Adjustments';

    protected static ?int $navigationSort = 23;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        if (auth()->user()?->hasRole('academic-head')) {
            return 'Academic Head';
        }

        return 'Accounting';
    }

    public static function form(Schema $schema): Schema
    {
        return AccountingAdjustmentForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AccountingAdjustmentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AccountingAdjustmentsTable::configure($table);
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
            'index' => ListAccountingAdjustments::route('/'),
            'create' => CreateAccountingAdjustment::route('/create'),
            'view' => ViewAccountingAdjustment::route('/{record}'),
        ];
    }
}
