<?php

namespace App\Filament\Resources\CalendarEvents;

use App\Filament\Resources\CalendarEvents\Pages\CreateCalendarEvent;
use App\Filament\Resources\CalendarEvents\Pages\EditCalendarEvent;
use App\Filament\Resources\CalendarEvents\Pages\ListCalendarEvents;
use App\Filament\Resources\CalendarEvents\Schemas\CalendarEventForm;
use App\Filament\Resources\CalendarEvents\Tables\CalendarEventsTable;
use App\Models\CalendarEvent;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CalendarEventResource extends Resource
{
    protected static ?string $model = CalendarEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?int $navigationSort = 29;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationLabel(): string
    {
        return self::isFacultyWorkspace()
            ? 'My Unavailable Times'
            : 'Scheduling Blocks';
    }

    public static function getModelLabel(): string
    {
        return self::isFacultyWorkspace()
            ? 'unavailable time'
            : 'scheduling block';
    }

    public static function getPluralModelLabel(): string
    {
        return self::isFacultyWorkspace()
            ? 'unavailable times'
            : 'scheduling blocks';
    }

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
        return CalendarEventForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CalendarEventsTable::configure($table);
    }

    /**
     * @return Builder<CalendarEvent>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = CalendarEvent::query()
            ->recurringSchedulingBlocks()
            ->with(['term', 'room', 'faculty']);
        $user = auth()->user();

        if ($user?->hasRole(User::StaffRoleRegistrar)) {
            return $query;
        }

        if ($user?->hasRole(User::StaffRoleAcademicHead)) {
            return $query->where('scope_type', CalendarEvent::ScopeFaculty);
        }

        if ($user?->hasRole(User::StaffRoleFaculty)) {
            return $query
                ->where('event_type', CalendarEvent::TypeUnavailable)
                ->where('scope_type', CalendarEvent::ScopeFaculty)
                ->where('faculty_user_id', $user->id)
                ->where('state', CalendarEvent::StateActive);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCalendarEvents::route('/'),
            'create' => CreateCalendarEvent::route('/create'),
            'edit' => EditCalendarEvent::route('/{record}/edit'),
        ];
    }

    private static function isFacultyWorkspace(): bool
    {
        return auth()->user()?->hasRole(User::StaffRoleFaculty) ?? false;
    }
}
