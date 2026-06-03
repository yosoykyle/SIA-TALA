<?php

namespace App\Filament\Resources\DocumentUploads;

use App\Filament\Resources\DocumentUploads\Pages\ListDocumentUploads;
use App\Filament\Resources\DocumentUploads\Pages\ViewDocumentUpload;
use App\Filament\Resources\DocumentUploads\Schemas\DocumentUploadInfolist;
use App\Filament\Resources\DocumentUploads\Tables\DocumentUploadsTable;
use App\Models\DocumentUpload;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DocumentUploadResource extends Resource
{
    protected static ?string $model = DocumentUpload::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Document Review';

    protected static ?int $navigationSort = 22;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        if (auth()->user()?->hasRole('academic-head')) {
            return 'Academic Head';
        }

        return 'Registrar';
    }

    public static function infolist(Schema $schema): Schema
    {
        return DocumentUploadInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DocumentUploadsTable::configure($table);
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
            'index' => ListDocumentUploads::route('/'),
            'view' => ViewDocumentUpload::route('/{record}'),
        ];
    }
}
