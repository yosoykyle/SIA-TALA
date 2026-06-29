<?php

namespace App\Filament\Resources\LedgerEntries;

use App\Filament\Resources\LedgerEntries\Pages\ListLedgerEntries;
use App\Filament\Resources\LedgerEntries\Pages\ViewLedgerEntry;
use App\Filament\Resources\LedgerEntries\Schemas\LedgerEntryInfolist;
use App\Filament\Resources\LedgerEntries\Tables\LedgerEntriesTable;
use App\Models\LedgerEntry;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class LedgerEntryResource extends Resource
{
    protected static ?string $model = LedgerEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $navigationLabel = 'Ledger Entries';

    protected static ?int $navigationSort = 22;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $user = auth()->user();

        if ($user instanceof User && $user->hasRole(User::StaffRoleRegistrar)) {
            return 'Registrar';
        }

        return 'Accounting';
    }

    public static function infolist(Schema $schema): Schema
    {
        return LedgerEntryInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LedgerEntriesTable::configure($table);
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
            'index' => ListLedgerEntries::route('/'),
            'view' => ViewLedgerEntry::route('/{record}'),
        ];
    }
}
