<?php

namespace App\Filament\Resources\Courses;

use App\Actions\AcademicSetup\CurriculumWorkbenchService;
use App\Filament\Resources\Courses\Pages\ImportCatalogSubjects;
use App\Filament\Resources\Courses\Pages\ListCourses;
use App\Filament\Resources\Courses\Pages\ViewCourse;
use App\Filament\Resources\Courses\Schemas\CourseForm;
use App\Filament\Resources\Courses\Schemas\CourseInfolist;
use App\Filament\Resources\Courses\Tables\CoursesTable;
use App\Filament\Resources\CourseSpecifications\Schemas\CourseSpecificationForm;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\FacultyQualification;
use App\Models\GradeOutcomeEvent;
use App\Models\ProgramShiftCreditEntry;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\TermOffering;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class CourseResource extends Resource
{
    protected static ?string $model = Course::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Subject catalog';

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
            ->schema(CourseSpecificationForm::newSubjectFields(withCurriculumShortcut: true))
            ->using(function (array $data): Course {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                $result = app(CurriculumWorkbenchService::class)->createDraftSubjectAndAssignToCurricula($actor, $data);
                $applyTo = (string) ($data['apply_to'] ?? 'none');
                $added = $result['added_curricula'];

                if ($applyTo !== 'none' && $applyTo !== '') {
                    self::sendCurriculumPlacementNotification(
                        added: $added,
                        skippedPrograms: $result['skipped_programs'],
                        creating: true,
                    );
                } else {
                    Notification::make()
                        ->title('Draft subject created')
                        ->success()
                        ->send();
                }

                return $result['course'];
            })
            ->modalWidth(Width::FiveExtraLarge)->stickyModalHeader()->stickyModalFooter()->createAnother(false)
            ->successNotification(null);
    }

    public static function assignToCurriculaAction(): Action
    {
        return Action::make('assignToCurricula')
            ->label('Assign to curricula')
            ->button()
            ->color('gray')
            ->icon(Heroicon::OutlinedSquaresPlus)
            ->modalHeading(fn (Course $record): string => "Assign {$record->code} to curricula")
            ->modalDescription('Place this subject into open draft curricula without duplicating it.')
            ->modalSubmitActionLabel('Assign to curricula')
            ->modalWidth(Width::ThreeExtraLarge)
            ->authorize(fn (Course $record): bool => (auth()->user()?->can('create', Course::class) ?? false)
                && self::latestAssignableSpecification($record) instanceof CourseSpecification)
            ->schema(CourseSpecificationForm::curriculumAssignmentFields(includeDoNotAssign: false))
            ->action(function (Course $record, array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                $spec = self::latestAssignableSpecification($record);
                abort_unless($spec instanceof CourseSpecification, 404);

                $result = app(CurriculumWorkbenchService::class)->assignSpecificationToCurricula($actor, $spec, $data);

                self::sendCurriculumPlacementNotification(
                    added: $result['added_curricula'],
                    skippedPrograms: $result['skipped_programs'],
                    creating: false,
                );
            });
    }

    public static function deleteSubjectAction(): Action
    {
        return Action::make('delete')
            ->label('Delete')
            ->button()
            ->color('danger')
            ->icon(Heroicon::OutlinedTrash)
            ->requiresConfirmation()
            ->modalHeading(fn (Course $record): string => "Delete draft {$record->code}?")
            ->modalDescription('Only unused, unpublished draft subjects can be permanently deleted. Published (Active), retired, or referenced subjects cannot be deleted.')
            ->modalSubmitActionLabel('Delete draft subject')
            ->authorize(fn (Course $record): bool => (auth()->user()?->can('create', Course::class) ?? false)
                && self::canDeleteUnusedSubject($record))
            ->visible(fn (Course $record): bool => (auth()->user()?->can('create', Course::class) ?? false)
                && self::canDeleteUnusedSubject($record))
            ->action(function (Course $record): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                try {
                    self::deleteUnusedSubject($record, $actor);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Subject cannot be deleted')
                        ->body(collect($exception->errors())->flatten()->implode(' '))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Subject deleted')
                    ->success()
                    ->send();
            });
    }

    public static function canDeleteUnusedSubject(Course $course): bool
    {
        if ($course->state === Course::StateRetired) {
            return false;
        }

        if (CourseSpecification::query()
            ->where('course_id', $course->id)
            ->whereIn('state', [CourseSpecification::StateActive, CourseSpecification::StateRetired])
            ->exists()) {
            return false;
        }

        return CurriculumEntry::query()
            ->whereHas('courseSpecification', fn ($query) => $query->where('course_id', $course->id))
            ->doesntExist()
            && TermOffering::query()
                ->whereHas('curriculumEntry.courseSpecification', fn ($query) => $query->where('course_id', $course->id))
                ->doesntExist()
            && Section::query()
                ->whereHas('courseRevision', fn ($query) => $query->where('course_id', $course->id))
                ->doesntExist()
            && SchedulingDemand::query()
                ->whereHas('courseComponent.courseSpecification', fn ($query) => $query->where('course_id', $course->id))
                ->doesntExist()
            && GradeOutcomeEvent::query()
                ->whereHas('row.courseEnrollment.termOffering.curriculumEntry.courseSpecification', fn ($query) => $query->where('course_id', $course->id))
                ->doesntExist()
            && GradeOutcomeEvent::query()
                ->whereHas('row.roster.section.courseRevision', fn ($query) => $query->where('course_id', $course->id))
                ->doesntExist()
            && CourseRequirement::query()
                ->where('related_course_id', $course->id)
                ->doesntExist()
            && FacultyQualification::query()
                ->where('course_id', $course->id)
                ->doesntExist()
            && ProgramShiftCreditEntry::query()
                ->where('source_course_id', $course->id)
                ->doesntExist();
    }

    public static function deleteUnusedSubject(Course $course, User $actor): void
    {
        Gate::forUser($actor)->authorize('create', Course::class);

        DB::transaction(function () use ($course, $actor): void {
            $lockedCourse = Course::query()->lockForUpdate()->findOrFail($course->id);

            if (! self::canDeleteUnusedSubject($lockedCourse)) {
                throw ValidationException::withMessages([
                    'course' => 'Only unused, unpublished draft subjects can be deleted. Published (Active), retired, or referenced subjects cannot be deleted.',
                ]);
            }

            $specIds = $lockedCourse->specifications()->pluck('id');

            activity()
                ->performedOn($lockedCourse)
                ->causedBy($actor)
                ->event('course_deleted')
                ->withProperties([
                    'code' => $lockedCourse->code,
                    'specification_count' => $specIds->count(),
                ])
                ->log('Unused subject deleted');

            if ($specIds->isNotEmpty()) {
                CourseComponent::query()->whereIn('course_specification_id', $specIds)->delete();
                CourseRequirement::query()->whereIn('course_specification_id', $specIds)->delete();
                CourseSpecification::query()->whereIn('id', $specIds)->delete();
            }

            $lockedCourse->delete();
        });
    }

    private static function latestAssignableSpecification(Course $course): ?CourseSpecification
    {
        return $course->specifications()
            ->whereIn('state', [CourseSpecification::StateActive, CourseSpecification::StateDraft])
            ->orderByRaw('CASE WHEN state = ? THEN 0 ELSE 1 END', [CourseSpecification::StateActive])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  list<string>  $skippedPrograms
     */
    private static function sendCurriculumPlacementNotification(int $added, array $skippedPrograms, bool $creating): void
    {
        if ($added > 0) {
            $prefix = $creating ? 'Subject created and added' : 'Subject added';
            $notification = Notification::make()
                ->title("{$prefix} to {$added} ".Str::plural('curriculum', $added))
                ->success();

            if ($skippedPrograms !== []) {
                $notification->body('Skipped: '.implode(', ', $skippedPrograms).'.');
            }

            $notification->send();

            return;
        }

        $skippedNote = $skippedPrograms !== []
            ? ' ('.implode(', ', $skippedPrograms).')'
            : '';

        Notification::make()
            ->title($creating ? 'Draft subject created' : 'No curricula updated')
            ->body("No editable draft curricula received this subject{$skippedNote}. Create or duplicate a draft curriculum first.")
            ->warning()
            ->send();
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCourses::route('/'),
            'import' => ImportCatalogSubjects::route('/import'),
            'view' => ViewCourse::route('/{record}'),
        ];
    }
}
