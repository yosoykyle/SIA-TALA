<?php

namespace App\Filament\Resources\CorVerifications;

use App\Filament\Resources\CorVerifications\Pages\CreateCorVerification;
use App\Filament\Resources\CorVerifications\Pages\EditCorVerification;
use App\Filament\Resources\CorVerifications\Pages\ListCorVerifications;
use App\Filament\Resources\CorVerifications\Pages\ViewCorVerification;
use App\Filament\Resources\CorVerifications\Schemas\CorVerificationForm;
use App\Filament\Resources\CorVerifications\Schemas\CorVerificationInfolist;
use App\Filament\Resources\CorVerifications\Tables\CorVerificationsTable;
use App\Models\CorVerification;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CorVerificationResource extends Resource
{
    protected static ?string $model = CorVerification::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'COR Controls';

    protected static ?int $navigationSort = 24;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        if (auth()->user()?->hasRole('academic-head')) {
            return 'Academic Head';
        }

        return 'Registrar';
    }

    public static function form(Schema $schema): Schema
    {
        return CorVerificationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CorVerificationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CorVerificationsTable::configure($table);
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
            'index' => ListCorVerifications::route('/'),
            'create' => CreateCorVerification::route('/create'),
            'view' => ViewCorVerification::route('/{record}'),
            'edit' => EditCorVerification::route('/{record}/edit'),
        ];
    }
}
