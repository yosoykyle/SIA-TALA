<?php

namespace App\Filament\Resources\FacultyTermLoadOverrides;

use App\Filament\Resources\FacultyTermLoadOverrides\Pages\CreateFacultyTermLoadOverride;
use App\Filament\Resources\FacultyTermLoadOverrides\Pages\EditFacultyTermLoadOverride;
use App\Filament\Resources\FacultyTermLoadOverrides\Pages\ListFacultyTermLoadOverrides;
use App\Filament\Resources\FacultyTermLoadOverrides\Pages\ViewFacultyTermLoadOverride;
use App\Filament\Resources\FacultyTermLoadOverrides\Schemas\FacultyTermLoadOverrideForm;
use App\Filament\Resources\FacultyTermLoadOverrides\Schemas\FacultyTermLoadOverrideInfolist;
use App\Filament\Resources\FacultyTermLoadOverrides\Tables\FacultyTermLoadOverridesTable;
use App\Models\FacultyTermLoadOverride;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class FacultyTermLoadOverrideResource extends Resource
{
    protected static ?string $model = FacultyTermLoadOverride::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Faculty Term Load Overrides';

    protected static ?string $modelLabel = 'Faculty Term Load Override';

    protected static ?string $pluralModelLabel = 'Faculty Term Load Overrides';

    protected static ?int $navigationSort = 32;

    protected static ?string $recordTitleAttribute = 'id';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        if (auth()->user()?->hasRole(User::StaffRoleAcademicHead)) {
            return 'Academic Head';
        }

        return 'Registrar';
    }

    public static function form(Schema $schema): Schema
    {
        return FacultyTermLoadOverrideForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FacultyTermLoadOverrideInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FacultyTermLoadOverridesTable::configure($table);
    }

    /**
     * @return Builder<FacultyTermLoadOverride>
     */
    public static function getEloquentQuery(): Builder
    {
        return FacultyTermLoadOverride::query()
            ->with(['faculty', 'term.academicYear']);
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
            'index' => ListFacultyTermLoadOverrides::route('/'),
            'create' => CreateFacultyTermLoadOverride::route('/create'),
            'view' => ViewFacultyTermLoadOverride::route('/{record}'),
            'edit' => EditFacultyTermLoadOverride::route('/{record}/edit'),
        ];
    }
}
