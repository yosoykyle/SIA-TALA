<?php

namespace App\Filament\Resources\TranscriptRequests;

use App\Filament\Resources\TranscriptRequests\Pages\ListTranscriptRequests;
use App\Filament\Resources\TranscriptRequests\Pages\ViewTranscriptRequest;
use App\Filament\Resources\TranscriptRequests\Schemas\TranscriptRequestInfolist;
use App\Filament\Resources\TranscriptRequests\Tables\TranscriptRequestsTable;
use App\Models\TranscriptRequest;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TranscriptRequestResource extends Resource
{
    protected static ?string $model = TranscriptRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $modelLabel = 'TOR request';

    protected static ?string $pluralModelLabel = 'TOR requests';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAccounting,
        ]) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'studentProfile',
            'clearances',
            'events',
            'snapshots.events',
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TranscriptRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TranscriptRequestsTable::configure($table);
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
            'index' => ListTranscriptRequests::route('/'),
            'view' => ViewTranscriptRequest::route('/{record}'),
        ];
    }
}
