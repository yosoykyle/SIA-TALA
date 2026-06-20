<?php

namespace App\Filament\Resources\DocumentRequirementItems;

use App\Filament\Resources\DocumentRequirementItems\Pages\CreateDocumentRequirementItem;
use App\Filament\Resources\DocumentRequirementItems\Pages\EditDocumentRequirementItem;
use App\Filament\Resources\DocumentRequirementItems\Pages\ListDocumentRequirementItems;
use App\Filament\Resources\DocumentRequirementItems\Pages\ViewDocumentRequirementItem;
use App\Filament\Resources\DocumentRequirementItems\Schemas\DocumentRequirementItemForm;
use App\Filament\Resources\DocumentRequirementItems\Schemas\DocumentRequirementItemInfolist;
use App\Filament\Resources\DocumentRequirementItems\Tables\DocumentRequirementItemsTable;
use App\Models\DocumentRequirementItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DocumentRequirementItemResource extends Resource
{
    protected static ?string $model = DocumentRequirementItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Document Requirement Items';

    protected static ?int $navigationSort = 36;

    protected static ?string $recordTitleAttribute = 'label';

    public static function form(Schema $schema): Schema
    {
        return DocumentRequirementItemForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DocumentRequirementItemInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DocumentRequirementItemsTable::configure($table);
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
            'index' => ListDocumentRequirementItems::route('/'),
            'create' => CreateDocumentRequirementItem::route('/create'),
            'view' => ViewDocumentRequirementItem::route('/{record}'),
            'edit' => EditDocumentRequirementItem::route('/{record}/edit'),
        ];
    }
}
