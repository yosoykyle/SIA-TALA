<?php

namespace App\Filament\Resources\FacultyAvailabilityPeriods;

use App\Filament\Resources\FacultyAvailabilityPeriods\Pages\CreateFacultyAvailabilityPeriod;
use App\Filament\Resources\FacultyAvailabilityPeriods\Pages\EditFacultyAvailabilityPeriod;
use App\Filament\Resources\FacultyAvailabilityPeriods\Pages\ListFacultyAvailabilityPeriods;
use App\Filament\Resources\FacultyAvailabilityPeriods\Pages\ViewFacultyAvailabilityPeriod;
use App\Filament\Resources\FacultyAvailabilityPeriods\Schemas\FacultyAvailabilityPeriodForm;
use App\Filament\Resources\FacultyAvailabilityPeriods\Schemas\FacultyAvailabilityPeriodInfolist;
use App\Filament\Resources\FacultyAvailabilityPeriods\Tables\FacultyAvailabilityPeriodsTable;
use App\Models\FacultyAvailabilityPeriod;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FacultyAvailabilityPeriodResource extends Resource
{
    protected static ?string $model = FacultyAvailabilityPeriod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Availability Periods';

    protected static ?string $modelLabel = 'Availability Period';

    protected static ?string $pluralModelLabel = 'Availability Periods';

    protected static ?int $navigationSort = 56;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        if (auth()->user()?->hasRole('academic-head')) {
            return 'Academic Head';
        }

        return 'Registrar';
    }

    public static function form(Schema $schema): Schema
    {
        return FacultyAvailabilityPeriodForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FacultyAvailabilityPeriodInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FacultyAvailabilityPeriodsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFacultyAvailabilityPeriods::route('/'),
            'create' => CreateFacultyAvailabilityPeriod::route('/create'),
            'view' => ViewFacultyAvailabilityPeriod::route('/{record}'),
            'edit' => EditFacultyAvailabilityPeriod::route('/{record}/edit'),
        ];
    }
}
