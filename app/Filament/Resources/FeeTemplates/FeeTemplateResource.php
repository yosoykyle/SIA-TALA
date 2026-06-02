<?php

namespace App\Filament\Resources\FeeTemplates;

use App\Filament\Resources\FeeTemplates\Pages\CreateFeeTemplate;
use App\Filament\Resources\FeeTemplates\Pages\EditFeeTemplate;
use App\Filament\Resources\FeeTemplates\Pages\ListFeeTemplates;
use App\Filament\Resources\FeeTemplates\Pages\ViewFeeTemplate;
use App\Filament\Resources\FeeTemplates\Schemas\FeeTemplateForm;
use App\Filament\Resources\FeeTemplates\Schemas\FeeTemplateInfolist;
use App\Filament\Resources\FeeTemplates\Tables\FeeTemplatesTable;
use App\Models\FeeTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FeeTemplateResource extends Resource
{
    protected static ?string $model = FeeTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $navigationLabel = 'Fee Templates';

    protected static ?int $navigationSort = 11;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        if (auth()->user()?->hasRole('academic-head')) {
            return 'Academic Head';
        }

        return 'Accounting';
    }

    public static function form(Schema $schema): Schema
    {
        return FeeTemplateForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FeeTemplateInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FeeTemplatesTable::configure($table);
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
            'index' => ListFeeTemplates::route('/'),
            'create' => CreateFeeTemplate::route('/create'),
            'view' => ViewFeeTemplate::route('/{record}'),
            'edit' => EditFeeTemplate::route('/{record}/edit'),
        ];
    }
}
