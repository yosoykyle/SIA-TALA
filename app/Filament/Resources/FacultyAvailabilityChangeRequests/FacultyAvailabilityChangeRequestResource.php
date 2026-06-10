<?php

namespace App\Filament\Resources\FacultyAvailabilityChangeRequests;

use App\Filament\Resources\FacultyAvailabilityChangeRequests\Pages\CreateFacultyAvailabilityChangeRequest;
use App\Filament\Resources\FacultyAvailabilityChangeRequests\Pages\ListFacultyAvailabilityChangeRequests;
use App\Filament\Resources\FacultyAvailabilityChangeRequests\Pages\ViewFacultyAvailabilityChangeRequest;
use App\Filament\Resources\FacultyAvailabilityChangeRequests\Schemas\FacultyAvailabilityChangeRequestForm;
use App\Filament\Resources\FacultyAvailabilityChangeRequests\Schemas\FacultyAvailabilityChangeRequestInfolist;
use App\Filament\Resources\FacultyAvailabilityChangeRequests\Tables\FacultyAvailabilityChangeRequestsTable;
use App\Models\FacultyAvailabilityChangeRequest;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class FacultyAvailabilityChangeRequestResource extends Resource
{
    protected static ?string $model = FacultyAvailabilityChangeRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Availability Change Requests';

    protected static ?string $modelLabel = 'Availability Change Request';

    protected static ?string $pluralModelLabel = 'Availability Change Requests';

    protected static ?int $navigationSort = 58;

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
        return FacultyAvailabilityChangeRequestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FacultyAvailabilityChangeRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FacultyAvailabilityChangeRequestsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['term', 'faculty', 'submission', 'requester', 'reviewer', 'createdSubmission']);

        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        if (self::canViewAllRequests($user)) {
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
            'index' => ListFacultyAvailabilityChangeRequests::route('/'),
            'create' => CreateFacultyAvailabilityChangeRequest::route('/create'),
            'view' => ViewFacultyAvailabilityChangeRequest::route('/{record}'),
        ];
    }

    private static function canViewAllRequests(User $user): bool
    {
        foreach (['review-lock-faculty-availability', 'view-faculty-availability', 'view-global-records'] as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }
}
