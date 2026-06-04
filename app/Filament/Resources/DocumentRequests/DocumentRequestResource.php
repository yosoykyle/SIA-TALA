<?php

namespace App\Filament\Resources\DocumentRequests;

use App\Filament\Resources\DocumentRequests\Pages\ListDocumentRequests;
use App\Filament\Resources\DocumentRequests\Pages\ViewDocumentRequest;
use App\Filament\Resources\DocumentRequests\Schemas\DocumentRequestInfolist;
use App\Filament\Resources\DocumentRequests\Tables\DocumentRequestsTable;
use App\Models\DocumentRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DocumentRequestResource extends Resource
{
    protected static ?string $model = DocumentRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Document Requests';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $user = auth()->user();

        if ($user?->hasRole('accounting')) {
            return 'Accounting';
        }

        if ($user?->hasRole('academic-head')) {
            return 'Academic Head';
        }

        return 'Registrar';
    }

    public static function infolist(Schema $schema): Schema
    {
        return DocumentRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DocumentRequestsTable::configure($table);
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
            'index' => ListDocumentRequests::route('/'),
            'view' => ViewDocumentRequest::route('/{record}'),
        ];
    }
}
