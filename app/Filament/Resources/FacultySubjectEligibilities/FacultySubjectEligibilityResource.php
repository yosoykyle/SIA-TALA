<?php

namespace App\Filament\Resources\FacultySubjectEligibilities;

use App\Filament\Resources\FacultySubjectEligibilities\Pages\CreateFacultySubjectEligibility;
use App\Filament\Resources\FacultySubjectEligibilities\Pages\EditFacultySubjectEligibility;
use App\Filament\Resources\FacultySubjectEligibilities\Pages\ListFacultySubjectEligibilities;
use App\Filament\Resources\FacultySubjectEligibilities\Schemas\FacultySubjectEligibilityForm;
use App\Filament\Resources\FacultySubjectEligibilities\Tables\FacultySubjectEligibilitiesTable;
use App\Models\FacultySubjectEligibility;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class FacultySubjectEligibilityResource extends Resource
{
    protected static ?string $model = FacultySubjectEligibility::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Faculty Subject Eligibility';

    protected static ?string $modelLabel = 'Faculty Subject Eligibility';

    protected static ?string $pluralModelLabel = 'Faculty Subject Eligibilities';

    protected static ?int $navigationSort = 30;

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
        return FacultySubjectEligibilityForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FacultySubjectEligibilitiesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['faculty', 'subject', 'term', 'approver']);

        $user = auth()->user();

        if ($user instanceof User
            && $user->hasRole(User::StaffRoleFaculty)
            && ! $user->can('manage-faculty-subject-eligibilities')) {
            $query->where('faculty_id', $user->id);
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
            'index' => ListFacultySubjectEligibilities::route('/'),
            'create' => CreateFacultySubjectEligibility::route('/create'),
            'edit' => EditFacultySubjectEligibility::route('/{record}/edit'),
        ];
    }
}
