<?php

namespace App\Filament\Pages;

use App\Actions\AcademicSetup\AcademicReadinessService;
use App\Filament\Resources\AcademicCalendarWindows\AcademicCalendarWindowResource;
use App\Filament\Resources\AcademicYears\AcademicYearResource;
use App\Filament\Resources\Courses\CourseResource;
use App\Filament\Resources\CourseSpecifications\CourseSpecificationResource;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Filament\Resources\Programs\ProgramResource;
use App\Filament\Resources\Terms\TermResource;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class AcademicReadiness extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'Academic Setup';

    protected static ?string $navigationLabel = 'Academic Readiness';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'academic-readiness';

    protected string $view = 'filament.pages.academic-readiness';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
        ]);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getTitle(): string
    {
        return 'Academic Readiness';
    }

    public function getSubheading(): string
    {
        return 'Confirm each program curriculum, resolve the stated blocker, and activate only complete approved records before building offerings.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Program::query()->with([
                    'curriculumVersions.entries.courseSpecification.components',
                    'curriculumVersions.entries.courseSpecification.course',
                ]),
            )
            ->columns([
                TextColumn::make('code')
                    ->label('Program')
                    ->description(fn (Program $record): string => $record->name)
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('current_curriculum')
                    ->label('Current Curriculum')
                    ->state(function (Program $record): string {
                        $curriculum = $this->readiness()->currentCurriculum($record);

                        return $curriculum instanceof CurriculumVersion
                            ? "{$curriculum->version_code} · ".(CurriculumVersion::stateOptions()[$curriculum->state] ?? str($curriculum->state)->headline())
                            : 'None recorded';
                    })
                    ->wrap(),
                TextColumn::make('curriculum_rows')
                    ->label('Rows')
                    ->state(fn (Program $record): int => $this->readiness()->programReadiness($record)['entries'])
                    ->alignCenter(),
                TextColumn::make('readiness')
                    ->label('Readiness')
                    ->state(fn (Program $record): string => $this->readiness()->programReadiness($record)['status'])
                    ->badge()
                    ->color(fn (Program $record): string => $this->readiness()->programReadiness($record)['color']),
                TextColumn::make('blocker')
                    ->label('What blocks progress')
                    ->state(fn (Program $record): string => $this->readiness()->programReadiness($record)['blocker'])
                    ->wrap(),
                TextColumn::make('next_action')
                    ->label('Next action')
                    ->state(fn (Program $record): string => $this->readiness()->programReadiness($record)['next_action'])
                    ->weight('medium')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('is_active')
                    ->label('Program status')
                    ->options([
                        1 => 'Active programs',
                        0 => 'Inactive programs',
                    ]),
            ])
            ->recordActions([
                Action::make('openCurriculum')
                    ->label(function (Program $record): string {
                        return $this->readiness()->currentCurriculum($record) instanceof CurriculumVersion
                            ? 'Review curriculum'
                            : 'Create draft';
                    })
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(function (Program $record): string {
                        $curriculum = $this->readiness()->currentCurriculum($record);

                        return $curriculum instanceof CurriculumVersion
                            ? CurriculumVersionResource::getUrl('review', ['record' => $curriculum])
                            : CurriculumVersionResource::getUrl('create');
                    })
                    ->visible(function (Program $record): bool {
                        return $this->readiness()->currentCurriculum($record) instanceof CurriculumVersion
                            || $this->canManage();
                    }),
            ])
            ->stackedOnMobile()
            ->defaultSort('code')
            ->emptyStateHeading('No programs are configured')
            ->emptyStateDescription('Create the institution program records before encoding or importing a curriculum.');
    }

    /**
     * @return list<Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('classPlanning')
                ->label('Open Class Planning')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->url(ClassPlanning::getUrl())
                ->visible(fn (): bool => ClassPlanning::canAccess()),
            Action::make('createCurriculum')
                ->label('Create curriculum draft')
                ->icon(Heroicon::OutlinedPlus)
                ->color('primary')
                ->url(CurriculumVersionResource::getUrl('create'))
                ->visible(fn (): bool => $this->canManage()),
            Action::make('importCurriculum')
                ->label('Import or review CSV')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->url(ImportBatchResource::getUrl('index'))
                ->visible(fn (): bool => $this->canManage()),
            ActionGroup::make([
                Action::make('academicYears')
                    ->label('Academic years')
                    ->url(AcademicYearResource::getUrl('index')),
                Action::make('terms')
                    ->label('Terms')
                    ->url(TermResource::getUrl('index')),
                Action::make('calendarWindows')
                    ->label('Academic calendar windows')
                    ->url(AcademicCalendarWindowResource::getUrl('index')),
                Action::make('programs')
                    ->label('Programs')
                    ->url(ProgramResource::getUrl('index')),
                Action::make('courses')
                    ->label('Course catalog')
                    ->url(CourseResource::getUrl('index')),
                Action::make('courseSpecifications')
                    ->label('Course specifications')
                    ->url(CourseSpecificationResource::getUrl('index')),
                Action::make('curriculumVersions')
                    ->label('Curriculum versions')
                    ->url(CurriculumVersionResource::getUrl('index')),
            ])
                ->label('Source records')
                ->icon(Heroicon::OutlinedCircleStack)
                ->color('gray'),
        ];
    }

    private function readiness(): AcademicReadinessService
    {
        return app(AcademicReadinessService::class);
    }

    private function canManage(): bool
    {
        return auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false;
    }
}
