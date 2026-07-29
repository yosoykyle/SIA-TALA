<?php

namespace App\Filament\Resources\CurriculumVersions;

use App\Filament\Resources\CurriculumVersions\Pages\CreateCurriculumVersion;
use App\Filament\Resources\CurriculumVersions\Pages\EditCurriculumVersion;
use App\Filament\Resources\CurriculumVersions\Pages\ListCurriculumVersions;
use App\Filament\Resources\CurriculumVersions\Pages\ReviewCurriculumVersion;
use App\Filament\Resources\CurriculumVersions\Pages\ViewCurriculumVersion;
use App\Filament\Resources\CurriculumVersions\Schemas\CurriculumVersionForm;
use App\Filament\Resources\CurriculumVersions\Schemas\CurriculumVersionInfolist;
use App\Filament\Resources\CurriculumVersions\Tables\CurriculumVersionsTable;
use App\Models\CurriculumVersion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CurriculumVersionResource extends Resource
{
    protected static ?string $model = CurriculumVersion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Curriculum Versions';

    protected static ?int $navigationSort = 24;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        if (auth()->user()?->hasRole('academic-head')) {
            return 'Academic Head';
        }

        return 'Registrar';
    }

    public static function form(Schema $schema): Schema
    {
        return CurriculumVersionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CurriculumVersionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CurriculumVersionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCurriculumVersions::route('/'),
            'create' => CreateCurriculumVersion::route('/create'),
            'review' => ReviewCurriculumVersion::route('/{record}/review'),
            'view' => ViewCurriculumVersion::route('/{record}'),
            'edit' => EditCurriculumVersion::route('/{record}/edit'),
        ];
    }
}
