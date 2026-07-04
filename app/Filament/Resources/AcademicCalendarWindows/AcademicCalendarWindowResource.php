<?php

namespace App\Filament\Resources\AcademicCalendarWindows;

use App\Filament\Resources\AcademicCalendarWindows\Pages\CreateAcademicCalendarWindow;
use App\Filament\Resources\AcademicCalendarWindows\Pages\EditAcademicCalendarWindow;
use App\Filament\Resources\AcademicCalendarWindows\Pages\ListAcademicCalendarWindows;
use App\Filament\Resources\AcademicCalendarWindows\Schemas\AcademicCalendarWindowForm;
use App\Filament\Resources\AcademicCalendarWindows\Tables\AcademicCalendarWindowsTable;
use App\Models\CalendarEvent;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class AcademicCalendarWindowResource extends Resource
{
    protected static ?string $model = CalendarEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Academic Calendar Windows';

    protected static ?string $modelLabel = 'Academic Calendar Window';

    protected static ?string $pluralModelLabel = 'Academic Calendar Windows';

    protected static ?string $slug = 'academic-calendar-windows';

    protected static ?int $navigationSort = 28;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        if (auth()->user()?->hasRole(User::StaffRoleAcademicHead)) {
            return 'Academic Head';
        }

        return 'Registrar';
    }

    public static function canAccess(): bool
    {
        return Gate::allows('viewAcademicCalendarWindows', CalendarEvent::class);
    }

    public static function canCreate(): bool
    {
        return Gate::allows('createAcademicCalendarWindow', CalendarEvent::class);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return AcademicCalendarWindowForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AcademicCalendarWindowsTable::configure($table);
    }

    /**
     * @return Builder<CalendarEvent>
     */
    public static function getEloquentQuery(): Builder
    {
        return CalendarEvent::query()
            ->academicCalendarWindows()
            ->with('term');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAcademicCalendarWindows::route('/'),
            'create' => CreateAcademicCalendarWindow::route('/create'),
            'edit' => EditAcademicCalendarWindow::route('/{record}/edit'),
        ];
    }
}
