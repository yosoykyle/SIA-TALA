<?php

namespace App\Filament\Resources\FacultyAvailabilitySubmissions;

use App\Filament\Resources\FacultyAvailabilitySubmissions\Pages\CreateFacultyAvailabilitySubmission;
use App\Filament\Resources\FacultyAvailabilitySubmissions\Pages\ListFacultyAvailabilitySubmissions;
use App\Filament\Resources\FacultyAvailabilitySubmissions\Pages\ViewFacultyAvailabilitySubmission;
use App\Filament\Resources\FacultyAvailabilitySubmissions\Schemas\FacultyAvailabilitySubmissionForm;
use App\Filament\Resources\FacultyAvailabilitySubmissions\Schemas\FacultyAvailabilitySubmissionInfolist;
use App\Filament\Resources\FacultyAvailabilitySubmissions\Tables\FacultyAvailabilitySubmissionsTable;
use App\Models\FacultyAvailabilitySubmission;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class FacultyAvailabilitySubmissionResource extends Resource
{
    protected static ?string $model = FacultyAvailabilitySubmission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Faculty Availability';

    protected static ?string $modelLabel = 'Faculty Availability';

    protected static ?string $pluralModelLabel = 'Faculty Availability';

    protected static ?int $navigationSort = 57;

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

    public static function canCreate(): bool
    {
        return auth()->user()?->can('submit-faculty-availability') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return FacultyAvailabilitySubmissionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FacultyAvailabilitySubmissionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FacultyAvailabilitySubmissionsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['term', 'availabilityPeriod', 'faculty', 'approver', 'windows'])
            ->withCount('windows');

        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        if (self::canViewAllAvailability($user)) {
            return $query;
        }

        if ($user->can('submit-faculty-availability')) {
            return $query->where('faculty_id', $user->id);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFacultyAvailabilitySubmissions::route('/'),
            'create' => CreateFacultyAvailabilitySubmission::route('/create'),
            'view' => ViewFacultyAvailabilitySubmission::route('/{record}'),
        ];
    }

    private static function canViewAllAvailability(User $user): bool
    {
        foreach (['review-lock-faculty-availability', 'view-faculty-availability', 'view-global-records'] as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }
}
