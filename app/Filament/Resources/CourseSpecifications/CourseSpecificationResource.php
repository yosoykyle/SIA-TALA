<?php

namespace App\Filament\Resources\CourseSpecifications;

use App\Filament\Resources\CourseSpecifications\Pages\CreateCourseSpecification;
use App\Filament\Resources\CourseSpecifications\Pages\EditCourseSpecification;
use App\Filament\Resources\CourseSpecifications\Pages\ListCourseSpecifications;
use App\Filament\Resources\CourseSpecifications\Pages\ViewCourseSpecification;
use App\Filament\Resources\CourseSpecifications\Schemas\CourseSpecificationForm;
use App\Filament\Resources\CourseSpecifications\Schemas\CourseSpecificationInfolist;
use App\Filament\Resources\CourseSpecifications\Tables\CourseSpecificationsTable;
use App\Models\CourseSpecification;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CourseSpecificationResource extends Resource
{
    protected static ?string $model = CourseSpecification::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Course Specifications';

    protected static ?int $navigationSort = 22;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        if (auth()->user()?->hasRole('academic-head')) {
            return 'Academic Head';
        }

        return 'Registrar';
    }

    public static function form(Schema $schema): Schema
    {
        return CourseSpecificationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CourseSpecificationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CourseSpecificationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCourseSpecifications::route('/'),
            'create' => CreateCourseSpecification::route('/create'),
            'view' => ViewCourseSpecification::route('/{record}'),
            'edit' => EditCourseSpecification::route('/{record}/edit'),
        ];
    }
}
