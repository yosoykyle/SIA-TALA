<?php

namespace App\Filament\Resources\StudentLifecycleChanges;

use App\Filament\Resources\StudentLifecycleChanges\Pages\CreateStudentLifecycleChange;
use App\Filament\Resources\StudentLifecycleChanges\Pages\ListStudentLifecycleChanges;
use App\Filament\Resources\StudentLifecycleChanges\Pages\ViewStudentLifecycleChange;
use App\Filament\Resources\StudentLifecycleChanges\Schemas\StudentLifecycleChangeForm;
use App\Filament\Resources\StudentLifecycleChanges\Schemas\StudentLifecycleChangeInfolist;
use App\Filament\Resources\StudentLifecycleChanges\Tables\StudentLifecycleChangesTable;
use App\Models\StudentLifecycleChange;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class StudentLifecycleChangeResource extends Resource
{
    protected static ?string $model = StudentLifecycleChange::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Student Records';

    protected static ?string $navigationLabel = 'Lifecycle Changes';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return StudentLifecycleChangeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return StudentLifecycleChangeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StudentLifecycleChangesTable::configure($table);
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
            'index' => ListStudentLifecycleChanges::route('/'),
            'create' => CreateStudentLifecycleChange::route('/create'),
            'view' => ViewStudentLifecycleChange::route('/{record}'),
        ];
    }
}
