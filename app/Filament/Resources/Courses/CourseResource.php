<?php

namespace App\Filament\Resources\Courses;

use App\Actions\AcademicSetup\CurriculumWorkbenchService;
use App\Filament\Resources\Courses\Pages\ListCourses;
use App\Filament\Resources\Courses\Pages\ViewCourse;
use App\Filament\Resources\Courses\Schemas\CourseForm;
use App\Filament\Resources\Courses\Schemas\CourseInfolist;
use App\Filament\Resources\Courses\Tables\CoursesTable;
use App\Filament\Resources\CourseSpecifications\Schemas\CourseSpecificationForm;
use App\Models\Course;
use App\Models\User;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CourseResource extends Resource
{
    protected static ?string $model = Course::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Subjects';

    protected static ?string $modelLabel = 'subject';

    protected static ?int $navigationSort = 21;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        if (auth()->user()?->hasRole('academic-head')) {
            return 'Academic Head';
        }

        return 'Registrar';
    }

    public static function form(Schema $schema): Schema
    {
        return CourseForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CourseInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CoursesTable::configure($table);
    }

    public static function createSubjectAction(): CreateAction
    {
        return CreateAction::make()->label('New subject')->model(Course::class)->modal()
            ->modalHeading('New subject')
            ->modalDescription('Create a reusable subject. It stays in draft until its teaching details are ready.')
            ->modalSubmitActionLabel('Create draft subject')
            ->authorize(fn (): bool => auth()->user()?->can('create', Course::class) ?? false)
            ->schema(CourseSpecificationForm::newSubjectFields())
            ->using(function (array $data): Course {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                return app(CurriculumWorkbenchService::class)->createDraftSubject($actor, $data)->course;
            })
            ->modalWidth(Width::FiveExtraLarge)->stickyModalHeader()->stickyModalFooter()->createAnother(false)
            ->successNotificationTitle('Draft subject created');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCourses::route('/'),
            'view' => ViewCourse::route('/{record}'),
        ];
    }
}
