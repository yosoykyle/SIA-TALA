<?php

namespace App\Filament\Resources\PromissoryNotes;

use App\Filament\Resources\PromissoryNotes\Pages\CreatePromissoryNote;
use App\Filament\Resources\PromissoryNotes\Pages\ListPromissoryNotes;
use App\Filament\Resources\PromissoryNotes\Pages\ViewPromissoryNote;
use App\Filament\Resources\PromissoryNotes\Schemas\PromissoryNoteForm;
use App\Filament\Resources\PromissoryNotes\Schemas\PromissoryNoteInfolist;
use App\Filament\Resources\PromissoryNotes\Tables\PromissoryNotesTable;
use App\Models\PromissoryNote;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class PromissoryNoteResource extends Resource
{
    protected static ?string $model = PromissoryNote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $navigationLabel = 'Promissory Notes';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        if (auth()->user()?->hasRole('academic-head')) {
            return 'Academic Head';
        }

        return 'Accounting';
    }

    public static function form(Schema $schema): Schema
    {
        return PromissoryNoteForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PromissoryNoteInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PromissoryNotesTable::configure($table);
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
            'index' => ListPromissoryNotes::route('/'),
            'create' => CreatePromissoryNote::route('/create'),
            'view' => ViewPromissoryNote::route('/{record}'),
        ];
    }
}
