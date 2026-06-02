<?php

namespace App\Filament\Resources\SectionMeetings;

use App\Filament\Resources\SectionMeetings\Pages\CreateSectionMeeting;
use App\Filament\Resources\SectionMeetings\Pages\EditSectionMeeting;
use App\Filament\Resources\SectionMeetings\Pages\ListSectionMeetings;
use App\Filament\Resources\SectionMeetings\Pages\ViewSectionMeeting;
use App\Filament\Resources\SectionMeetings\Schemas\SectionMeetingForm;
use App\Filament\Resources\SectionMeetings\Schemas\SectionMeetingInfolist;
use App\Filament\Resources\SectionMeetings\Tables\SectionMeetingsTable;
use App\Models\SectionMeeting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SectionMeetingResource extends Resource
{
    protected static ?string $model = SectionMeeting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Official Schedules';

    protected static ?int $navigationSort = 31;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $user = auth()->user();

        if ($user?->hasRole('faculty')) {
            return 'Faculty';
        }

        if ($user?->hasRole('academic-head')) {
            return 'Academic Head';
        }

        return 'Registrar';
    }

    public static function form(Schema $schema): Schema
    {
        return SectionMeetingForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SectionMeetingInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SectionMeetingsTable::configure($table);
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
            'index' => ListSectionMeetings::route('/'),
            'create' => CreateSectionMeeting::route('/create'),
            'view' => ViewSectionMeeting::route('/{record}'),
            'edit' => EditSectionMeeting::route('/{record}/edit'),
        ];
    }
}
