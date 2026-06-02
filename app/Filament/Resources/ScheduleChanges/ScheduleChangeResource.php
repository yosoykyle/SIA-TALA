<?php

namespace App\Filament\Resources\ScheduleChanges;

use App\Filament\Resources\ScheduleChanges\Pages\CreateScheduleChange;
use App\Filament\Resources\ScheduleChanges\Pages\EditScheduleChange;
use App\Filament\Resources\ScheduleChanges\Pages\ListScheduleChanges;
use App\Filament\Resources\ScheduleChanges\Pages\ViewScheduleChange;
use App\Filament\Resources\ScheduleChanges\Schemas\ScheduleChangeForm;
use App\Filament\Resources\ScheduleChanges\Schemas\ScheduleChangeInfolist;
use App\Filament\Resources\ScheduleChanges\Tables\ScheduleChangesTable;
use App\Models\ScheduleChange;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ScheduleChangeResource extends Resource
{
    protected static ?string $model = ScheduleChange::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Schedule Changes';

    protected static ?int $navigationSort = 32;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        if (auth()->user()?->hasRole('academic-head')) {
            return 'Academic Head';
        }

        return 'Registrar';
    }

    public static function form(Schema $schema): Schema
    {
        return ScheduleChangeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ScheduleChangeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ScheduleChangesTable::configure($table);
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
            'index' => ListScheduleChanges::route('/'),
            'create' => CreateScheduleChange::route('/create'),
            'view' => ViewScheduleChange::route('/{record}'),
            'edit' => EditScheduleChange::route('/{record}/edit'),
        ];
    }
}
