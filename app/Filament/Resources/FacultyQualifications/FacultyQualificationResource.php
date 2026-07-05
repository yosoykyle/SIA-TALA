<?php

namespace App\Filament\Resources\FacultyQualifications;

use App\Filament\Resources\FacultyQualifications\Pages\CreateFacultyQualification;
use App\Filament\Resources\FacultyQualifications\Pages\EditFacultyQualification;
use App\Filament\Resources\FacultyQualifications\Pages\ListFacultyQualifications;
use App\Filament\Resources\FacultyQualifications\Pages\ViewFacultyQualification;
use App\Filament\Resources\FacultyQualifications\Schemas\FacultyQualificationForm;
use App\Filament\Resources\FacultyQualifications\Schemas\FacultyQualificationInfolist;
use App\Filament\Resources\FacultyQualifications\Tables\FacultyQualificationsTable;
use App\Models\FacultyQualification;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class FacultyQualificationResource extends Resource
{
    protected static ?string $model = FacultyQualification::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Faculty Qualifications';

    protected static ?string $modelLabel = 'Faculty Qualification';

    protected static ?string $pluralModelLabel = 'Faculty Qualifications';

    protected static ?int $navigationSort = 31;

    protected static ?string $recordTitleAttribute = 'id';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $user = auth()->user();

        if ($user?->hasRole(User::StaffRoleFaculty)) {
            return 'Faculty';
        }

        if ($user?->hasRole(User::StaffRoleAcademicHead)) {
            return 'Academic Head';
        }

        return 'Registrar';
    }

    public static function form(Schema $schema): Schema
    {
        return FacultyQualificationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FacultyQualificationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FacultyQualificationsTable::configure($table);
    }

    /**
     * @return Builder<FacultyQualification>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = FacultyQualification::query()
            ->with(['faculty', 'course', 'recorder']);

        $user = auth()->user();

        if ($user instanceof User && $user->hasRole(User::StaffRoleFaculty)) {
            return $query->where('faculty_user_id', $user->id);
        }

        return $query;
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
            'index' => ListFacultyQualifications::route('/'),
            'create' => CreateFacultyQualification::route('/create'),
            'view' => ViewFacultyQualification::route('/{record}'),
            'edit' => EditFacultyQualification::route('/{record}/edit'),
        ];
    }
}
