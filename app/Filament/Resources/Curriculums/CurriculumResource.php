<?php

namespace App\Filament\Resources\Curriculums;

use App\Filament\Resources\Curriculums\Pages\CreateCurriculum;
use App\Filament\Resources\Curriculums\Pages\EditCurriculum;
use App\Filament\Resources\Curriculums\Pages\ListCurriculums;
use App\Filament\Resources\Curriculums\Pages\ViewCurriculum;
use App\Filament\Resources\Curriculums\Schemas\CurriculumForm;
use App\Filament\Resources\Curriculums\Schemas\CurriculumInfolist;
use App\Filament\Resources\Curriculums\Tables\CurriculumsTable;
use App\Models\Curriculum;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CurriculumResource extends Resource
{
    protected static ?string $model = Curriculum::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Curricula';

    protected static ?string $modelLabel = 'Curriculum';

    protected static ?string $pluralModelLabel = 'Curricula';

    protected static ?string $slug = 'curricula';

    protected static ?int $navigationSort = 22;

    protected static ?string $recordTitleAttribute = 'version_name';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        if (auth()->user()?->hasRole('academic-head')) {
            return 'Academic Head';
        }

        return 'Registrar';
    }

    public static function form(Schema $schema): Schema
    {
        return CurriculumForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CurriculumInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CurriculumsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCurriculums::route('/'),
            'create' => CreateCurriculum::route('/create'),
            'view' => ViewCurriculum::route('/{record}'),
            'edit' => EditCurriculum::route('/{record}/edit'),
        ];
    }
}
