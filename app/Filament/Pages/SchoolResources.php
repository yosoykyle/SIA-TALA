<?php

namespace App\Filament\Pages;

use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Filament\Concerns\HasAcademicOversightReturn;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\Rooms\Schemas\RoomForm;
use App\Filament\Resources\Rooms\Schemas\RoomInfolist;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\FacultyQualification;
use App\Models\Program;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermOffering;
use App\Models\User;
use App\Support\RoomEquipment;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\GridDirection;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use UnitEnum;

final class SchoolResources extends Page implements HasTable
{
    use HasAcademicOversightReturn;
    use InteractsWithTable {
        getTableColumnsSessionKey as protected nativeTableColumnsSessionKey;
    }

    #[Url]
    public string $tab = 'faculty';

    #[Url]
    public ?int $termId = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Academic Planning';

    protected static ?string $navigationLabel = 'School Resources';

    protected static ?string $title = 'School Resources';

    protected static ?string $slug = 'school-resources';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.school-resources';

    /**
     * @var array{
     *     options: array<int, string>,
     *     descriptions: array<int, string>,
     *     needed_ids: list<int>,
     *     shared_ids: list<int>,
     *     program_course_ids: array<int, list<int>>,
     *     programs: array<int, string>,
     *     curriculum_course_ids: array<int, list<int>>,
     *     curricula: array<int, string>,
     *     batch_filters: array<string, string>,
     *     curriculum_course_placements: array<int, array<int, list<array{year_level: int, semester_number: int}>>>
     * }|null
     */
    private ?array $catalogSubjectsCache = null;

    public function mount(): void
    {
        if (! in_array($this->tab, ['faculty', 'rooms'], true)) {
            $this->tab = 'faculty';
        }
    }

    public function chooseTab(string $tab): void
    {
        if (! in_array($tab, ['faculty', 'rooms'], true)) {
            $tab = 'faculty';
        }

        $this->tab = $tab;
        $this->tableColumns = [];
        $this->cachedDefaultTableColumnState = null;
        $this->resetTableSearch();
        $this->resetTableColumnSearches();
        $this->resetTableFiltersForm();
        $this->resetTable();
    }

    public function getTableColumnsSessionKey(): string
    {
        return $this->nativeTableColumnsSessionKey().'_'.$this->tab;
    }

    public function getSubheading(): ?string
    {
        if ($this->isReadOnly()) {
            return 'Read-only view of approved faculty subjects and campus rooms reused across every term\'s timetable.';
        }

        return 'Set up approved faculty subjects and campus rooms once. Every semester\'s Term Planning and timetable generator reuses them automatically.';
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->returnToOversightAction('Academic Oversight')
                ->visible(fn (): bool => $this->isReadOnly()),
            Action::make('returnToTermResources')
                ->label('Back to Term Planning')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => TermResources::getUrl([
                    'termId' => $this->termId,
                    'resourceView' => $this->tab === 'faculty' ? 'faculty' : 'rooms',
                ]))
                ->visible(fn (): bool => $this->termId !== null && ! $this->isReadOnly()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $activeRoomCount = Room::query()->where('is_active', true)->count();
        $totalRoomCount = Room::query()->count();

        $activeFacultyQuery = User::query()
            ->where('status', User::StatusActive)
            ->whereHas('roles', fn (Builder $query): Builder => $query->where('name', User::StaffRoleFaculty));

        $activeFacultyCount = (clone $activeFacultyQuery)->count();
        $qualifiedFacultyCount = (clone $activeFacultyQuery)
            ->whereHas('facultyQualifications', fn (Builder $query): Builder => $query->where('is_active', true))
            ->count();

        return [
            'readOnly' => $this->isReadOnly(),
            'activeRoomCount' => $activeRoomCount,
            'totalRoomCount' => $totalRoomCount,
            'activeFacultyCount' => $activeFacultyCount,
            'qualifiedFacultyCount' => $qualifiedFacultyCount,
        ];
    }

    public function table(Table $table): Table
    {
        if ($this->tab === 'rooms') {
            return $this->roomsTable($table);
        }

        return $this->facultyTable($table);
    }

    private function roomsTable(Table $table): Table
    {
        return $table
            ->query(Room::query()->with('features'))
            ->columns([
                TextColumn::make('code')
                    ->label('Room')
                    ->weight('bold')
                    ->searchable(['code', 'name', 'building'])
                    ->sortable()
                    ->description(fn (Room $record): string => $record->name)
                    ->wrap(),
                TextColumn::make('room_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Room::TypeComputerLaboratory => 'info',
                        Room::TypeLaboratory => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => Room::typeOptions()[$state] ?? $state)
                    ->sortable(),
                TextColumn::make('capacity')
                    ->label('Capacity')
                    ->suffix(' seats')
                    ->extraCellAttributes(['class' => 'tabular-nums'])
                    ->sortable(),
                TextColumn::make('building')
                    ->label('Building')
                    ->placeholder('Not recorded')
                    ->sortable()
                    ->wrap(),
                TextColumn::make('features.feature_key')
                    ->label('Equipment')
                    ->formatStateUsing(fn (?string $state): string => RoomEquipment::label($state))
                    ->separator(',')
                    ->wrap()
                    ->placeholder('None recorded')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable(),
            ])
            ->defaultSort('code')
            ->columnManager(false)
            ->filters([
                SelectFilter::make('room_type')
                    ->label('Room type')
                    ->options(Room::typeOptions()),
                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('All rooms')
                    ->trueLabel('Active rooms')
                    ->falseLabel('Inactive rooms'),
            ])
            ->filtersTriggerAction(fn (Action $action): Action => $action->label('Filter rooms')->button()->color('gray')->size('sm'))
            ->toolbarActions([
                CreateAction::make('addRoom')
                    ->label('Add room')
                    ->icon(Heroicon::OutlinedPlus)
                    ->color('primary')
                    ->size('sm')
                    ->model(Room::class)
                    ->modalHeading('Add room')
                    ->modalDescription('Shared campus room catalog used across every term.')
                    ->modalWidth(Width::TwoExtraLarge)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalSubmitActionLabel('Add room')
                    ->createAnother(false)
                    ->schema(fn (Schema $schema): Schema => RoomForm::configure($schema))
                    ->authorize(fn (): bool => ! $this->isReadOnly() && RoomResource::canCreate())
                    ->visible(fn (): bool => ! $this->isReadOnly() && RoomResource::canCreate())
                    ->after(function (): void {
                        $actor = auth()->user();
                        if ($actor instanceof User) {
                            $this->refreshActiveTermScheduling($actor);
                        }
                        $this->flushCachedTableRecords();
                    }),
            ])
            ->recordActionsColumnLabel('Actions')
            ->recordActions([
                EditAction::make('editRoom')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->button()
                    ->color('gray')
                    ->size('sm')
                    ->extraAttributes(['class' => 'whitespace-nowrap'])
                    ->modalHeading(fn (Room $record): string => 'Edit '.$record->code.' · '.$record->name)
                    ->modalDescription(fn (): string => 'Shared campus room catalog used across every term.'.($this->hasActiveDraftTimetable() ? ' Changing this will mark active draft timetables outdated.' : ''))
                    ->modalWidth(Width::TwoExtraLarge)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalSubmitActionLabel('Save room')
                    ->schema(fn (Schema $schema): Schema => RoomForm::configure($schema))
                    ->visible(fn (Room $record): bool => ! $this->isReadOnly() && RoomResource::canEdit($record))
                    ->before(function (Room $record, array $data, Action $action): void {
                        $this->guardRoomEdit($record, $data, $this->getMountedActionSchema(mountedAction: $action));
                    })
                    ->after(function (): void {
                        $actor = auth()->user();
                        if ($actor instanceof User) {
                            $this->refreshActiveTermScheduling($actor);
                        }
                        $this->flushCachedTableRecords();
                    }),
                Action::make('deactivateRoom')
                    ->label('Deactivate')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->button()
                    ->color('gray')
                    ->size('sm')
                    ->extraAttributes(['class' => 'whitespace-nowrap'])
                    ->requiresConfirmation()
                    ->modalHeading(fn (Room $record): string => 'Deactivate '.$record->code.'?')
                    ->modalDescription(fn (Room $record): string => $this->roomDeactivationModalDescription($record))
                    ->modalSubmitActionLabel('Deactivate room')
                    ->modalSubmitAction(fn (Action $action): Action => $action->color('warning'))
                    ->visible(fn (Room $record): bool => ! $this->isReadOnly() && RoomResource::canEdit($record) && $record->is_active)
                    ->action(function (Room $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar) && RoomResource::canEdit($record), 403);

                        $officialMeetings = $this->activeOfficialRoomMeetings($record);
                        if ($officialMeetings->isNotEmpty()) {
                            $summary = $this->formatOfficialMeetingImpact($officialMeetings);

                            Notification::make()
                                ->title("Cannot deactivate {$record->code}")
                                ->body("{$record->code} is assigned to active official classes ({$summary}). Update the published schedule via Timetable → Revise official schedule first.")
                                ->danger()
                                ->persistent()
                                ->send();

                            return;
                        }

                        $record->update(['is_active' => false]);
                        $this->refreshActiveTermScheduling($actor);
                        $this->flushCachedTableRecords();

                        Notification::make()
                            ->title($record->code.' deactivated')
                            ->body('Excluded from new scheduling assignments across non-closed terms.')
                            ->success()
                            ->send();
                    }),
                Action::make('activateRoom')
                    ->label('Activate')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->button()
                    ->color('gray')
                    ->size('sm')
                    ->extraAttributes(['class' => 'whitespace-nowrap'])
                    ->requiresConfirmation()
                    ->modalHeading(fn (Room $record): string => 'Activate '.$record->code.'?')
                    ->modalDescription(fn (Room $record): string => "Reactivating {$record->code} makes it available again for scheduling across all non-closed terms.")
                    ->modalSubmitActionLabel('Activate room')
                    ->visible(fn (Room $record): bool => ! $this->isReadOnly() && RoomResource::canEdit($record) && ! $record->is_active)
                    ->action(function (Room $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar) && RoomResource::canEdit($record), 403);

                        $record->update(['is_active' => true]);
                        $this->refreshActiveTermScheduling($actor);
                        $this->flushCachedTableRecords();

                        Notification::make()
                            ->title($record->code.' activated')
                            ->success()
                            ->send();
                    }),
                Action::make('deleteRoom')
                    ->label('Delete')
                    ->icon(Heroicon::OutlinedTrash)
                    ->button()
                    ->color('danger')
                    ->size('sm')
                    ->extraAttributes(['class' => 'whitespace-nowrap'])
                    ->requiresConfirmation()
                    ->modalHeading(fn (Room $record): string => 'Delete '.$record->code.'?')
                    ->modalDescription(fn (Room $record): string => "Permanently deletes {$record->code} because it has no meetings, closures, commitments, or scheduling requirements. This cannot be undone.")
                    ->modalSubmitActionLabel('Delete room')
                    ->visible(fn (Room $record): bool => ! $this->isReadOnly() && Gate::allows('deleteUnusedRoom', $record))
                    ->action(function (Room $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User && ! $this->isReadOnly(), 403);
                        Gate::forUser($actor)->authorize('deleteUnusedRoom', $record);

                        $code = $record->code;

                        try {
                            DB::transaction(function () use ($record): void {
                                $locked = Room::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
                                $locked->features()->delete();
                                $locked->delete();
                            }, attempts: 3);
                        } catch (QueryException) {
                            Notification::make()
                                ->title("Cannot delete {$code}")
                                ->body("{$code} is already referenced by scheduling records. Deactivate it instead.")
                                ->danger()
                                ->persistent()
                                ->send();

                            return;
                        }

                        $this->refreshActiveTermScheduling($actor);
                        $this->flushCachedTableRecords();

                        Notification::make()
                            ->title("{$code} deleted")
                            ->body('Unused room removed from the shared campus catalog.')
                            ->success()
                            ->send();
                    }),
                Action::make('inspectRoom')
                    ->label('Details')
                    ->icon(Heroicon::OutlinedEye)
                    ->size('sm')
                    ->button()
                    ->color('gray')
                    ->extraAttributes(['class' => 'whitespace-nowrap'])
                    ->authorize(fn (Room $record): bool => RoomResource::canView($record))
                    ->modalHeading(fn (Room $record): string => $record->code.' · '.$record->name)
                    ->modalDescription('Shared campus room catalog used across every term.')
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->schema(fn (Schema $schema, Room $record): Schema => RoomInfolist::configure($schema->record($record), compact: true))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->unsavedChangesAlert(false),
            ])
            ->emptyState(view('filament.partials.collection-empty-state'))
            ->emptyStateHeading(fn (): string => filled($this->getTableSearch()) ? 'No matching rooms' : 'No rooms recorded')
            ->emptyStateDescription(fn (): string => filled($this->getTableSearch())
                ? 'Try another code, name, or building, or clear the search.'
                : ($this->isReadOnly()
                    ? 'The Registrar maintains rooms in the shared room catalog.'
                    : 'Add teaching rooms here so the timetable generator can assign face-to-face classes across terms.'))
            ->stackedOnMobile();
    }

    private function facultyTable(Table $table): Table
    {
        return $table
            ->query(
                User::query()
                    ->where('status', User::StatusActive)
                    ->whereHas('roles', fn (Builder $query): Builder => $query->where('name', User::StaffRoleFaculty))
                    ->with([
                        'facultyQualifications' => fn ($query) => $query
                            ->where('is_active', true)
                            ->with([
                                'course.specifications' => fn ($specQuery) => $specQuery
                                    ->whereIn('state', [CourseSpecification::StateActive, CourseSpecification::StateDraft])
                                    ->orderByRaw('CASE WHEN state = ? THEN 0 ELSE 1 END', [CourseSpecification::StateActive])
                                    ->latest('id'),
                            ]),
                    ])
            )
            ->columns([
                TextColumn::make('name')
                    ->label('Faculty')
                    ->weight('bold')
                    ->searchable(['name', 'email'])
                    ->sortable()
                    ->description(fn (User $record): string => (string) $record->email)
                    ->wrap(),
                TextColumn::make('qualified_count')
                    ->label('Qualified subjects')
                    ->badge()
                    ->state(fn (User $record): int => $record->facultyQualifications->unique('course_id')->count())
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray')
                    ->formatStateUsing(fn (int $state): string => $state.' '.str('subject')->plural($state))
                    ->extraCellAttributes(['class' => 'tabular-nums']),
            ])
            ->defaultSort('name')
            ->columnManager(false)
            ->filters([
                TernaryFilter::make('has_qualifications')
                    ->label('Qualification status')
                    ->placeholder('All active faculty')
                    ->trueLabel('Has qualified subjects')
                    ->falseLabel('No subjects assigned')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('facultyQualifications', fn (Builder $q): Builder => $q->where('is_active', true)),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('facultyQualifications', fn (Builder $q): Builder => $q->where('is_active', true)),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->filtersTriggerAction(fn (Action $action): Action => $action->label('Filter faculty')->button()->color('gray')->size('sm'))
            ->toolbarActions([
                $this->assignSubjectsAction(),
            ])
            ->recordActionsColumnLabel('Actions')
            ->recordActions([
                Action::make('editFacultySubjects')
                    ->label('Subjects')
                    ->icon(Heroicon::OutlinedAcademicCap)
                    ->size('sm')
                    ->button()
                    ->color('gray')
                    ->extraAttributes(['class' => 'whitespace-nowrap'])
                    ->modalHeading(fn (User $record): string => 'Subjects for '.$record->name)
                    ->modalDescription(fn (): string => 'Shared across all terms · Check every catalog subject this teacher is approved to teach.'.($this->hasActiveDraftTimetable() ? ' Changing this will mark active draft timetables outdated.' : ''))
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalSubmitActionLabel('Save subjects')
                    ->modalCancelActionLabel('Cancel')
                    ->visible(fn (): bool => ! $this->isReadOnly())
                    ->fillForm(function (User $record): array {
                        $activeIds = $record->facultyQualifications
                            ->where('is_active', true)
                            ->pluck('course_id')
                            ->unique()
                            ->map(fn (mixed $id): string => (string) $id)
                            ->values()
                            ->all();
                        $defaultFilter = $this->defaultProgramFilterForSelection($activeIds);
                        [$visibleSelected, $retainedSelected] = $this->partitionSelectedCourseIds($activeIds, $defaultFilter, $activeIds);

                        return [
                            'program_filter' => $defaultFilter,
                            'retained_course_ids' => $retainedSelected,
                            'course_ids' => $visibleSelected,
                            'notes' => null,
                        ];
                    })
                    ->schema([
                        Hidden::make('retained_course_ids')
                            ->default([]),
                        Select::make('program_filter')
                            ->label('Filter subjects by program')
                            ->options(fn (): array => $this->programFilterOptions())
                            ->default('all')
                            ->selectablePlaceholder(false)
                            ->native(true)
                            ->dehydrated(false)
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                $this->repartitionSelectedCourseIdsForFilter($get, $set, $state);
                            }),
                        CheckboxList::make('course_ids')
                            ->label('Qualified subjects')
                            ->options(fn (Get $get): array => $this->filteredCatalogSubjectOptions(
                                $get('program_filter'),
                                $this->mergeSelectedCourseIds($get('course_ids'), $get('retained_course_ids')),
                            ))
                            ->descriptions(fn (Get $get): array => $this->filteredCatalogSubjectDescriptions(
                                $get('program_filter'),
                                $this->mergeSelectedCourseIds($get('course_ids'), $get('retained_course_ids')),
                            ))
                            ->searchable()
                            ->searchPrompt('Search by subject code or title...')
                            ->bulkToggleable()
                            ->selectAllAction(fn (Action $action): Action => $action
                                ->button()
                                ->size('xs')
                                ->color('gray')
                                ->outlined()
                                ->label('Select all'))
                            ->deselectAllAction(fn (Action $action): Action => $action
                                ->button()
                                ->size('xs')
                                ->color('gray')
                                ->outlined()
                                ->label('Deselect all'))
                            ->live()
                            ->columns(['default' => 1, 'md' => 2])
                            ->gridDirection(GridDirection::Row)
                            ->extraAttributes(fn (Get $get): array => [
                                'class' => 'tala-card-picker',
                                'wire:key' => 'school-edit-course-picker-'.md5((string) ($get('program_filter') ?? 'all')),
                            ])
                            ->helperText(fn (Get $get): string => $this->subjectSelectionHelperText(
                                $get('retained_course_ids'),
                                $get('program_filter'),
                                $get('course_ids'),
                            )),
                        Textarea::make('notes')
                            ->label('Notes (optional)')
                            ->rows(2)
                            ->maxLength(1000),
                    ])
                    ->action(function (User $record, array $data, Schema $schema): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);
                        $selectedCourseIds = $this->mergeSelectedCourseIds(
                            $data['course_ids'] ?? [],
                            $data['retained_course_ids'] ?? [],
                        );

                        $this->guardFacultySubjectRemoval($record, $selectedCourseIds, $schema);
                        $this->syncFacultySubjects($record, $selectedCourseIds, $actor, $data['notes'] ?? null);

                        Notification::make()
                            ->title('Subjects updated for '.$record->name)
                            ->body(count($selectedCourseIds).' qualified '.str('subject')->plural(count($selectedCourseIds)).' active.')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyState(view('filament.partials.collection-empty-state'))
            ->emptyStateHeading(fn (): string => filled($this->getTableSearch()) ? 'No matching faculty' : 'No active faculty recorded')
            ->emptyStateDescription(fn (): string => filled($this->getTableSearch())
                ? 'Try another name or email, or clear the search.'
                : 'Active faculty accounts appear here so the Registrar can assign their qualified subjects across terms.')
            ->stackedOnMobile();
    }

    public function assignSubjectsAction(): Action
    {
        return Action::make('assignSubjects')
            ->label('Assign subjects')
            ->icon(Heroicon::OutlinedAcademicCap)
            ->color('primary')
            ->size('sm')
            ->modalHeading('Assign qualified subjects')
            ->modalDescription(fn (): string => 'Shared across all terms · Choose a teacher and check the catalog subjects they are approved to teach.'.($this->hasActiveDraftTimetable() ? ' Changing this will mark active draft timetables outdated.' : ''))
            ->modalWidth(Width::ThreeExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Save subjects')
            ->modalCancelActionLabel('Cancel')
            ->visible(fn (): bool => ! $this->isReadOnly())
            ->schema([
                Hidden::make('retained_course_ids')
                    ->default([]),
                Select::make('faculty_user_id')
                    ->label('Faculty member')
                    ->placeholder('Choose a teacher')
                    ->options(fn (): array => User::query()
                        ->where('status', User::StatusActive)
                        ->whereHas('roles', fn (Builder $query): Builder => $query->where('name', User::StaffRoleFaculty))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                        if (blank($state)) {
                            $set('program_filter', 'all');
                            $set('course_ids', []);
                            $set('retained_course_ids', []);

                            return;
                        }

                        $activeIds = FacultyQualification::query()
                            ->active()
                            ->where('faculty_user_id', (int) $state)
                            ->pluck('course_id')
                            ->map(fn (mixed $id): string => (string) $id)
                            ->values()
                            ->all();

                        $defaultFilter = $this->defaultProgramFilterForSelection($activeIds);
                        [$visibleSelected, $retainedSelected] = $this->partitionSelectedCourseIds(
                            $activeIds,
                            $defaultFilter,
                            $activeIds,
                        );

                        $set('program_filter', $defaultFilter);
                        $set('course_ids', $visibleSelected);
                        $set('retained_course_ids', $retainedSelected);
                    }),
                Select::make('program_filter')
                    ->label('Filter subjects by program')
                    ->options(fn (): array => $this->programFilterOptions())
                    ->default('all')
                    ->selectablePlaceholder(false)
                    ->native(true)
                    ->dehydrated(false)
                    ->live()
                    ->visible(fn (Get $get): bool => filled($get('faculty_user_id')))
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                        $this->repartitionSelectedCourseIdsForFilter($get, $set, $state);
                    }),
                CheckboxList::make('course_ids')
                    ->label('Qualified subjects')
                    ->options(fn (Get $get): array => $this->filteredCatalogSubjectOptions(
                        $get('program_filter'),
                        $this->mergeSelectedCourseIds($get('course_ids'), $get('retained_course_ids')),
                    ))
                    ->descriptions(fn (Get $get): array => $this->filteredCatalogSubjectDescriptions(
                        $get('program_filter'),
                        $this->mergeSelectedCourseIds($get('course_ids'), $get('retained_course_ids')),
                    ))
                    ->searchable()
                    ->searchPrompt('Search by subject code or title...')
                    ->bulkToggleable()
                    ->selectAllAction(fn (Action $action): Action => $action
                        ->button()
                        ->size('xs')
                        ->color('gray')
                        ->outlined()
                        ->label('Select all'))
                    ->deselectAllAction(fn (Action $action): Action => $action
                        ->button()
                        ->size('xs')
                        ->color('gray')
                        ->outlined()
                        ->label('Deselect all'))
                    ->live()
                    ->columns(['default' => 1, 'md' => 2])
                    ->gridDirection(GridDirection::Row)
                    ->extraAttributes(fn (Get $get): array => [
                        'class' => 'tala-card-picker',
                        'wire:key' => 'school-assign-course-picker-'.md5((string) ($get('program_filter') ?? 'all')),
                    ])
                    ->visible(fn (Get $get): bool => filled($get('faculty_user_id')))
                    ->helperText(fn (Get $get): string => $this->subjectSelectionHelperText(
                        $get('retained_course_ids'),
                        $get('program_filter'),
                        $get('course_ids'),
                    )),
                Textarea::make('notes')
                    ->label('Notes (optional)')
                    ->rows(2)
                    ->maxLength(1000)
                    ->visible(fn (Get $get): bool => filled($get('faculty_user_id'))),
            ])
            ->action(function (array $data, Schema $schema): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);
                $facultyId = (int) ($data['faculty_user_id'] ?? 0);
                $faculty = User::query()
                    ->whereKey($facultyId)
                    ->where('status', User::StatusActive)
                    ->whereHas('roles', fn (Builder $query): Builder => $query->where('name', User::StaffRoleFaculty))
                    ->firstOrFail();

                $selectedCourseIds = $this->mergeSelectedCourseIds(
                    $data['course_ids'] ?? [],
                    $data['retained_course_ids'] ?? [],
                );

                $this->guardFacultySubjectRemoval($faculty, $selectedCourseIds, $schema);
                $this->syncFacultySubjects($faculty, $selectedCourseIds, $actor, $data['notes'] ?? null);

                Notification::make()
                    ->title('Qualified subjects updated')
                    ->body($faculty->name.' is now qualified for '.count($selectedCourseIds).' '.str('subject')->plural(count($selectedCourseIds)).'.')
                    ->success()
                    ->send();
            });
    }

    /**
     * @return list<string>
     */
    private function formatFacultyQualifiedSubjects(User $faculty): array
    {
        $options = $this->catalogSubjectOptions();

        return $faculty->facultyQualifications
            ->where('is_active', true)
            ->unique('course_id')
            ->map(function (FacultyQualification $qualification) use ($options): ?string {
                $courseId = (int) $qualification->course_id;
                if (isset($options[$courseId])) {
                    return $options[$courseId];
                }

                $course = $qualification->course;
                if (! $course instanceof Course) {
                    return null;
                }

                $title = trim((string) ($course->specifications->first()?->title ?? ''));

                return $title !== '' ? "{$course->code} · {$title}" : $course->code;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function catalogSubjectOptions(): array
    {
        return $this->catalogSubjectsPayload()['options'];
    }

    /**
     * @return array<int, string>
     */
    private function catalogSubjectDescriptions(): array
    {
        return $this->catalogSubjectsPayload()['descriptions'];
    }

    /**
     * @return array<string, string>
     */
    private function programFilterOptions(): array
    {
        $payload = $this->catalogSubjectsPayload();

        $options = [
            'assigned' => 'Currently assigned',
        ];

        if ($payload['needed_ids'] !== []) {
            $options['needed'] = 'Needed in active/draft terms';
        }

        $options['all'] = 'All catalog subjects';
        $options['shared'] = 'General Education / Shared across programs';

        foreach ($payload['programs'] as $programId => $label) {
            $options['program:'.$programId] = $label;
        }

        foreach ($payload['batch_filters'] as $filterKey => $label) {
            $options[$filterKey] = $label;
        }

        return $options;
    }

    /**
     * @param  list<mixed>  $selectedIds
     */
    private function defaultProgramFilterForSelection(array $selectedIds): string
    {
        return $selectedIds !== [] ? 'assigned' : 'all';
    }

    /**
     * @param  list<mixed>  $selectedIds
     * @return array<int, string>
     */
    private function filteredCatalogSubjectOptions(?string $filter, array $selectedIds = []): array
    {
        $payload = $this->catalogSubjectsPayload();
        $allowedIds = $this->filteredCourseIdsForFilter($filter, $payload, $selectedIds);

        if ($allowedIds === null) {
            return $payload['options'];
        }

        return array_intersect_key($payload['options'], array_flip($allowedIds));
    }

    /**
     * @param  list<mixed>  $selectedIds
     * @return array<int, string>
     */
    private function filteredCatalogSubjectDescriptions(?string $filter, array $selectedIds = []): array
    {
        $payload = $this->catalogSubjectsPayload();
        $allowedIds = $this->filteredCourseIdsForFilter($filter, $payload, $selectedIds);

        $descriptions = $allowedIds === null
            ? $payload['descriptions']
            : array_intersect_key($payload['descriptions'], array_flip($allowedIds));

        $normalized = filled($filter) ? (string) $filter : 'all';
        $curriculumId = 0;
        if (str_starts_with($normalized, 'curriculum_batch:')) {
            $parts = explode(':', $normalized);
            $curriculumId = (int) ($parts[1] ?? 0);
        } elseif (str_starts_with($normalized, 'curriculum:')) {
            $curriculumId = (int) substr($normalized, strlen('curriculum:'));
        }

        if ($curriculumId <= 0) {
            return $descriptions;
        }

        $placementsByCourse = $payload['curriculum_course_placements'][$curriculumId] ?? [];
        if ($placementsByCourse === []) {
            return $descriptions;
        }

        foreach ($descriptions as $courseId => $desc) {
            $placements = $placementsByCourse[(int) $courseId] ?? [];
            if ($placements === []) {
                continue;
            }

            $placementLabels = [];
            foreach ($placements as $placement) {
                $yl = (int) $placement['year_level'];
                $sem = (int) $placement['semester_number'];
                if ($yl <= 0) {
                    continue;
                }
                $ylLabel = match ($yl) {
                    1 => '1st Year',
                    2 => '2nd Year',
                    3 => '3rd Year',
                    default => "{$yl}th Year",
                };
                $semLabel = match ($sem) {
                    1 => '1st Sem',
                    2 => '2nd Sem',
                    3 => 'Summer',
                    default => $sem > 0 ? "Sem {$sem}" : null,
                };
                $placementLabels[] = $semLabel !== null ? "{$ylLabel}, {$semLabel}" : $ylLabel;
            }

            if ($placementLabels !== []) {
                $descriptions[$courseId] = $desc.' · '.implode(' / ', array_unique($placementLabels));
            }
        }

        return $descriptions;
    }

    /**
     * @param  array{
     *     options: array<int, string>,
     *     descriptions: array<int, string>,
     *     needed_ids: list<int>,
     *     shared_ids: list<int>,
     *     program_course_ids: array<int, list<int>>,
     *     programs: array<int, string>,
     *     curriculum_course_ids: array<int, list<int>>,
     *     curricula: array<int, string>,
     *     batch_filters: array<string, string>,
     *     curriculum_course_placements: array<int, array<int, list<array{year_level: int, semester_number: int}>>>
     * }  $payload
     * @param  list<mixed>  $selectedIds
     * @return list<int>|null
     */
    private function filteredCourseIdsForFilter(?string $filter, array $payload, array $selectedIds = []): ?array
    {
        $normalized = filled($filter) ? (string) $filter : 'all';

        if ($normalized === 'all') {
            return null;
        }

        if ($normalized === 'assigned') {
            return collect($selectedIds)
                ->map(fn (mixed $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();
        }

        if ($normalized === 'needed') {
            return $payload['needed_ids'];
        }

        if ($normalized === 'shared') {
            return $payload['shared_ids'];
        }

        if (str_starts_with($normalized, 'program:')) {
            $programId = (int) substr($normalized, strlen('program:'));

            return $payload['program_course_ids'][$programId] ?? [];
        }

        if (str_starts_with($normalized, 'curriculum_batch:')) {
            $parts = explode(':', $normalized);
            $curriculumId = (int) ($parts[1] ?? 0);

            return $payload['curriculum_course_ids'][$curriculumId] ?? [];
        }

        if (str_starts_with($normalized, 'curriculum:')) {
            $curriculumId = (int) substr($normalized, strlen('curriculum:'));

            return $payload['curriculum_course_ids'][$curriculumId] ?? [];
        }

        return null;
    }

    /**
     * Split a complete list of selected course IDs into [visibleIds, retainedHiddenIds] for the active filter.
     *
     * @param  list<mixed>  $allSelected
     * @param  list<mixed>|null  $selectedForAssigned
     * @return array{0: list<string>, 1: list<string>}
     */
    private function partitionSelectedCourseIds(array $allSelected, ?string $filter, ?array $selectedForAssigned = null): array
    {
        $normalizedSelected = collect($allSelected)
            ->map(fn (mixed $id): string => (string) (int) $id)
            ->filter(fn (string $id): bool => (int) $id > 0)
            ->unique()
            ->values()
            ->all();

        $visibleKeys = array_map(
            'strval',
            array_keys($this->filteredCatalogSubjectOptions($filter, $selectedForAssigned ?? $normalizedSelected)),
        );
        $visibleLookup = array_flip($visibleKeys);

        $visible = [];
        $retained = [];

        foreach ($normalizedSelected as $id) {
            if (isset($visibleLookup[$id])) {
                $visible[] = $id;
            } else {
                $retained[] = $id;
            }
        }

        return [$visible, $retained];
    }

    private function repartitionSelectedCourseIdsForFilter(Get $get, Set $set, ?string $filter): void
    {
        $allSelected = array_merge(
            is_array($get('course_ids')) ? $get('course_ids') : [],
            is_array($get('retained_course_ids')) ? $get('retained_course_ids') : [],
        );

        [$visible, $retained] = $this->partitionSelectedCourseIds($allSelected, $filter, $allSelected);

        $set('course_ids', $visible);
        $set('retained_course_ids', $retained);
    }

    /**
     * @return list<int>
     */
    private function mergeSelectedCourseIds(mixed $visibleIds, mixed $retainedIds): array
    {
        return collect(is_array($visibleIds) ? $visibleIds : [])
            ->merge(is_array($retainedIds) ? $retainedIds : [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function subjectSelectionHelperText(mixed $retainedIds, ?string $filter = null, mixed $visibleIds = null): string
    {
        $retainedCount = collect(is_array($retainedIds) ? $retainedIds : [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->count();

        if ($retainedCount > 0) {
            return $retainedCount.' additional selected '.str('subject')->plural($retainedCount).' outside this filter will be kept when you save.';
        }

        if ($filter === 'assigned' && (! is_array($visibleIds) || $visibleIds === [])) {
            return 'No subjects selected yet. Switch the filter above to add subjects from the catalog.';
        }

        return 'Check every subject this teacher can be scheduled to teach.';
    }

    /**
     * @return array{
     *     options: array<int, string>,
     *     descriptions: array<int, string>,
     *     needed_ids: list<int>,
     *     shared_ids: list<int>,
     *     program_course_ids: array<int, list<int>>,
     *     programs: array<int, string>,
     *     curriculum_course_ids: array<int, list<int>>,
     *     curricula: array<int, string>,
     *     batch_filters: array<string, string>,
     *     curriculum_course_placements: array<int, array<int, list<array{year_level: int, semester_number: int}>>>
     * }
     */
    private function catalogSubjectsPayload(): array
    {
        if ($this->catalogSubjectsCache !== null) {
            return $this->catalogSubjectsCache;
        }

        $offeredOfferings = TermOffering::query()
            ->when(
                $this->termId !== null,
                fn (Builder $query): Builder => $query->where('term_id', (int) $this->termId),
                fn (Builder $query): Builder => $query->whereHas('term', fn (Builder $termQuery): Builder => $termQuery->whereIn('state', [Term::StateActive, Term::StateDraft])),
            )
            ->where('state', '!=', TermOffering::StateCancelled)
            ->whereHas('sections', fn (Builder $sectionQuery): Builder => $sectionQuery->whereNotIn('state', [
                Section::StateCancelled,
                Section::StateClosed,
            ]))
            ->with([
                'curriculumEntry.courseSpecification',
                'sections' => fn ($sectionQuery) => $sectionQuery
                    ->whereNotIn('state', [Section::StateCancelled, Section::StateClosed])
                    ->with('courseRevision'),
            ])
            ->get();

        $offeredCourseIds = [];
        foreach ($offeredOfferings as $offering) {
            $entrySpec = $offering->curriculumEntry?->courseSpecification;
            if ($entrySpec instanceof CourseSpecification
                && $entrySpec->scheduling_treatment !== CourseSpecification::SchedulingExternallyArranged
                && $entrySpec->course_id !== null) {
                $offeredCourseIds[(int) $entrySpec->course_id] = true;
            }

            foreach ($offering->sections as $section) {
                $sectionSpec = $section->courseRevision;
                if ($sectionSpec instanceof CourseSpecification
                    && $sectionSpec->scheduling_treatment !== CourseSpecification::SchedulingExternallyArranged
                    && $sectionSpec->course_id !== null) {
                    $offeredCourseIds[(int) $sectionSpec->course_id] = true;
                }
            }
        }

        $activePrograms = Program::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $programById = $activePrograms->keyBy('id');
        $programs = [];
        $programCourseIds = [];

        foreach ($activePrograms as $program) {
            $programs[(int) $program->id] = "{$program->code} · {$program->name}";
            $programCourseIds[(int) $program->id] = [];
        }

        $courseProgramIds = [];
        $curriculumCourseIds = [];
        $curriculaOptions = [];
        $batchFilters = [];
        $curriculumCoursePlacements = [];
        $courseBatchTags = [];

        if ($activePrograms->isNotEmpty()) {
            $relevantCurricula = CurriculumVersion::query()
                ->whereIn('state', [
                    CurriculumVersion::StateActive,
                    CurriculumVersion::StateSuperseded,
                    CurriculumVersion::StateDraft,
                ])
                ->whereIn('program_id', $activePrograms->pluck('id'))
                ->with(['program', 'academicYears'])
                ->get();

            $sortedCurricula = $relevantCurricula
                ->groupBy('program_id')
                ->sortBy(fn (Collection $group, int $programId): string => (string) ($programById[$programId]?->code ?? ''))
                ->flatMap(fn (Collection $group): Collection => CurriculumVersion::sortByLatestBatch($group, groupByState: true))
                ->values();

            $curriculumLabelsById = [];
            $curriculumSuffixById = [];
            $curriculumModelsById = [];
            foreach ($sortedCurricula as $curriculum) {
                $programCode = (string) ($programById[$curriculum->program_id]?->code ?? 'Program');
                $batchLabel = $curriculum->batchLabel();
                $suffix = match ($curriculum->state) {
                    CurriculumVersion::StateSuperseded => ' (Retired)',
                    CurriculumVersion::StateDraft => ' (Draft)',
                    default => '',
                };
                $curriculumLabelsById[(int) $curriculum->id] = $batchLabel;
                $curriculumSuffixById[(int) $curriculum->id] = $suffix;
                $curriculumModelsById[(int) $curriculum->id] = $curriculum;
                $curriculaOptions[(int) $curriculum->id] = "{$programCode} · {$batchLabel}{$suffix}";
                $curriculumCourseIds[(int) $curriculum->id] = [];
            }

            $courseProgramRows = DB::table('curriculum_entries')
                ->join('curriculum_versions', 'curriculum_versions.id', '=', 'curriculum_entries.curriculum_version_id')
                ->join('course_specifications', 'course_specifications.id', '=', 'curriculum_entries.course_specification_id')
                ->whereIn('curriculum_versions.state', [
                    CurriculumVersion::StateActive,
                    CurriculumVersion::StateSuperseded,
                    CurriculumVersion::StateDraft,
                ])
                ->whereIn('curriculum_versions.program_id', $activePrograms->pluck('id'))
                ->select(
                    'course_specifications.course_id',
                    'curriculum_versions.program_id',
                    'curriculum_versions.id as curriculum_version_id',
                    'curriculum_entries.year_level',
                    'curriculum_entries.term_type',
                )
                ->distinct()
                ->get();

            foreach ($courseProgramRows as $row) {
                $courseId = (int) $row->course_id;
                $programId = (int) $row->program_id;
                $curriculumId = (int) $row->curriculum_version_id;
                $courseProgramIds[$courseId][$programId] = $programId;
                $programCourseIds[$programId][$courseId] = $courseId;
                if (isset($curriculumCourseIds[$curriculumId])) {
                    $curriculumCourseIds[$curriculumId][$courseId] = $courseId;
                }
                if (isset($curriculumLabelsById[$curriculumId])) {
                    $courseBatchTags[$courseId][$curriculumId] = $curriculumLabelsById[$curriculumId];
                }
                $curriculumCoursePlacements[$curriculumId][$courseId][] = [
                    'year_level' => CurriculumEntry::parseYearLevelNumber($row->year_level),
                    'semester_number' => CurriculumEntry::termTypeSemesterNumber($row->term_type),
                ];
            }

            foreach ($sortedCurricula as $curriculum) {
                $cid = (int) $curriculum->id;
                if ($curriculumCourseIds[$cid] === []) {
                    continue;
                }

                $suffix = $curriculumSuffixById[$cid] ?? '';
                $batches = $curriculum->sortedBatchAcademicYears()
                    ->sortByDesc(fn (AcademicYear $ay): int => CurriculumVersion::extractAcademicYearStart($ay) ?? -1)
                    ->values();

                if ($batches->isNotEmpty()) {
                    foreach ($batches as $batchAy) {
                        $batchFilters['curriculum_batch:'.$cid.':'.$batchAy->id] = $curriculum->formatBatchOptionLabel($batchAy, includeProgram: true).$suffix;
                    }
                } else {
                    $batchFilters['curriculum:'.$cid] = $curriculaOptions[$cid];
                }
            }
        }

        foreach ($programCourseIds as $programId => $ids) {
            $programCourseIds[$programId] = array_values($ids);
        }

        foreach ($curriculumCourseIds as $curriculumId => $ids) {
            $curriculumCourseIds[$curriculumId] = array_values($ids);
        }

        $courses = Course::query()
            ->where('state', Course::StateActive)
            ->with([
                'specifications' => fn ($query) => $query
                    ->whereIn('state', [CourseSpecification::StateActive, CourseSpecification::StateDraft])
                    ->with('components')
                    ->orderByRaw('CASE WHEN state = ? THEN 0 ELSE 1 END', [CourseSpecification::StateActive])
                    ->latest('id'),
            ])
            ->orderBy('code')
            ->get();

        $options = [];
        $descriptions = [];
        $neededIds = [];
        $sharedIds = [];

        foreach ($courses as $course) {
            $courseId = (int) $course->id;
            $spec = $course->specifications->first();
            $title = trim((string) ($spec?->title ?? ''));
            $options[$courseId] = $title !== '' ? "{$course->code} · {$title}" : $course->code;

            $linkedProgramIds = array_values($courseProgramIds[$courseId] ?? []);
            if (count($linkedProgramIds) === 1) {
                $programTag = (string) ($programById[$linkedProgramIds[0]]?->code ?? 'Shared / GE');
            } else {
                $programTag = 'Shared / GE';
                $sharedIds[] = $courseId;
            }

            $isNeeded = isset($offeredCourseIds[$courseId]);
            if ($isNeeded) {
                $neededIds[] = $courseId;
            }

            $parts = [];
            if ($spec instanceof CourseSpecification) {
                $units = rtrim(rtrim(number_format((float) $spec->credit_units, 2, '.', ''), '0'), '.');
                $parts[] = "{$units} ".((float) $spec->credit_units === 1.0 ? 'unit' : 'units');
            }
            $parts[] = $programTag;

            $orderedBatchLabels = [];
            foreach (array_keys($curriculaOptions) as $cid) {
                if (isset($courseBatchTags[$courseId][$cid])) {
                    $orderedBatchLabels[] = $courseBatchTags[$courseId][$cid];
                }
            }
            $uniqueBatchLabels = array_values(array_unique($orderedBatchLabels));
            if ($uniqueBatchLabels !== []) {
                $visibleBatches = array_slice($uniqueBatchLabels, 0, 2);
                $extraBatches = count($uniqueBatchLabels) - count($visibleBatches);
                $parts[] = implode(', ', $visibleBatches).($extraBatches > 0 ? " +{$extraBatches} more" : '');
            }

            if ($isNeeded) {
                $parts[] = 'Needed this term';
            }

            $descriptions[$courseId] = implode(' · ', $parts);
        }

        return $this->catalogSubjectsCache = [
            'options' => $options,
            'descriptions' => $descriptions,
            'needed_ids' => $neededIds,
            'shared_ids' => $sharedIds,
            'program_course_ids' => $programCourseIds,
            'programs' => $programs,
            'curriculum_course_ids' => $curriculumCourseIds,
            'curricula' => $curriculaOptions,
            'batch_filters' => $batchFilters,
            'curriculum_course_placements' => $curriculumCoursePlacements,
        ];
    }

    /**
     * @param  list<int>  $selectedCourseIds
     */
    private function syncFacultySubjects(User $faculty, array $selectedCourseIds, User $actor, ?string $notes = null): void
    {
        DB::transaction(function () use ($faculty, $selectedCourseIds, $actor, $notes): void {
            $now = now();
            $trimmedNotes = filled($notes) ? trim($notes) : null;

            FacultyQualification::query()
                ->where('faculty_user_id', $faculty->id)
                ->where('is_active', true)
                ->when($selectedCourseIds !== [], fn (Builder $query): Builder => $query->whereNotIn('course_id', $selectedCourseIds))
                ->update(['is_active' => false]);

            foreach ($selectedCourseIds as $courseId) {
                $attributes = [
                    'is_active' => true,
                    'recorded_by' => $actor->id,
                    'recorded_at' => $now,
                ];
                if ($trimmedNotes !== null) {
                    $attributes['notes'] = $trimmedNotes;
                }

                FacultyQualification::query()->updateOrCreate(
                    [
                        'faculty_user_id' => $faculty->id,
                        'course_id' => $courseId,
                    ],
                    $attributes,
                );
            }

            activity()
                ->performedOn($faculty)
                ->causedBy($actor)
                ->event('faculty_qualifications_synced')
                ->withProperties([
                    'faculty_user_id' => $faculty->id,
                    'course_ids' => $selectedCourseIds,
                ])
                ->log('Updated faculty qualified subjects');
        });

        $this->refreshActiveTermScheduling($actor);
        $this->flushCachedTableRecords();
    }

    /**
     * @return Collection<int, SectionMeeting>
     */
    public static function activeOfficialRoomMeetingsFor(Room $room): Collection
    {
        return SectionMeeting::query()
            ->activeOfficial()
            ->where('room_id', $room->id)
            ->whereHas('scheduleRun.term', fn (Builder $query): Builder => $query->where('state', '!=', Term::StateClosed))
            ->with([
                'scheduleRun.term',
                'schedulingDemand.sectionDeliveryGroup.section',
                'schedulingDemand.termOffering.term',
                'schedulingDemand.courseComponent',
            ])
            ->get();
    }

    /**
     * @param  Collection<int, SectionMeeting>  $meetings
     */
    public static function formatOfficialMeetingImpactSummary(Collection $meetings): string
    {
        return $meetings
            ->groupBy(fn (SectionMeeting $meeting): string => (string) ($meeting->scheduleRun?->term?->label ?? 'Active term'))
            ->map(function (Collection $termMeetings, string $termLabel): string {
                $sections = $termMeetings
                    ->map(fn (SectionMeeting $meeting): string => (string) ($meeting->schedulingDemand?->sectionDeliveryGroup?->section?->code ?? 'Class'))
                    ->unique()
                    ->implode(', ');

                return "{$termLabel}: {$sections}";
            })
            ->implode('; ');
    }

    private static function resolveMeetingRequiredRoomType(SectionMeeting $meeting): ?string
    {
        $demand = $meeting->schedulingDemand;
        $component = $demand?->courseComponent;
        $offering = $demand?->termOffering;

        if ($component !== null && $offering !== null) {
            return GenerateSchedulingDemand::resolveRoomType($offering->room_type_override, $component);
        }

        if ($component !== null && filled($component->room_type_default)) {
            return (string) $component->room_type_default;
        }

        $snapshotType = $demand?->source_snapshot['room_type_requirement'] ?? null;

        return filled($snapshotType) ? (string) $snapshotType : null;
    }

    public static function roomDeactivationDescription(Room $record): string
    {
        $officialMeetings = self::activeOfficialRoomMeetingsFor($record);
        if ($officialMeetings->isNotEmpty()) {
            $summary = self::formatOfficialMeetingImpactSummary($officialMeetings);

            return "Blocked: {$record->code} is currently assigned to active official classes ({$summary}). Reassign them in Timetable → Revise official schedule before deactivating this room.";
        }

        $draftRuns = ScheduleGenerationRun::query()
            ->whereIn('status', [
                ScheduleGenerationRun::StatusQueued,
                ScheduleGenerationRun::StatusDispatching,
                ScheduleGenerationRun::StatusUnderReview,
            ])
            ->where(function (Builder $query): void {
                $query->whereNull('candidate_state')
                    ->orWhereNotIn('candidate_state', ['Stale', 'Superseded', 'Rejected']);
            })
            ->whereHas('term', fn (Builder $query): Builder => $query
                ->where('state', '!=', Term::StateClosed)
                ->whereHas('calendarPackages', fn (Builder $pkg): Builder => $pkg->where('state', TermCalendarPackage::StateActive)))
            ->with('term')
            ->get();

        if ($draftRuns->isNotEmpty()) {
            $termLabels = $draftRuns
                ->map(fn (ScheduleGenerationRun $run): string => (string) ($run->term?->label ?? 'Active term'))
                ->unique()
                ->implode(', ');
            $count = $draftRuns->count();

            return "Deactivating {$record->code} excludes it from scheduling across all non-closed terms and marks {$count} active draft timetable ".str('attempt')->plural($count)." stale ({$termLabels}).";
        }

        return "Deactivating {$record->code} excludes it from new scheduling assignments across all non-closed terms until reactivated.";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function validateRoomEdit(Room $record, array $data, ?Schema $schema = null): void
    {
        $officialMeetings = self::activeOfficialRoomMeetingsFor($record);
        if ($officialMeetings->isEmpty()) {
            return;
        }

        $summary = self::formatOfficialMeetingImpactSummary($officialMeetings);
        $guidance = 'Update the published schedule via Timetable → Revise official schedule first.';
        $errors = [];

        if (! (bool) ($data['is_active'] ?? true)) {
            $errors['is_active'] = "Cannot deactivate {$record->code} while assigned to active official classes ({$summary}). {$guidance}";
        }

        $newType = (string) ($data['room_type'] ?? $record->room_type);
        $typeConflict = $officialMeetings->contains(function (SectionMeeting $meeting) use ($record, $newType): bool {
            $requiredType = self::resolveMeetingRequiredRoomType($meeting) ?? (string) $record->room_type;

            return $newType !== $requiredType;
        });

        if ($typeConflict) {
            $errors['room_type'] = "Cannot change room type for {$record->code} while assigned to active official classes ({$summary}). {$guidance}";
        }

        $maxRequiredSeats = $officialMeetings->map(
            fn (SectionMeeting $meeting): int => (int) ($meeting->schedulingDemand?->sectionDeliveryGroup?->expected_count
                ?? $meeting->schedulingDemand?->sectionDeliveryGroup?->section?->capacity
                ?? 0)
        )->max() ?? 0;

        if ($maxRequiredSeats > 0 && (int) ($data['capacity'] ?? 0) < $maxRequiredSeats) {
            $errors['capacity'] = "Cannot reduce {$record->code} capacity below {$maxRequiredSeats} seats while assigned to active official classes ({$summary}). {$guidance}";
        }

        $rawFeatures = array_key_exists('features', $data)
            ? $data['features']
            : ($schema?->getRawState()['features'] ?? null);

        if (is_array($rawFeatures)) {
            $submittedFeatureKeys = collect($rawFeatures)
                ->map(function (mixed $item): ?string {
                    if (is_array($item)) {
                        $key = $item['feature_key'] ?? null;

                        return filled($key) ? strtoupper(trim((string) $key)) : null;
                    }

                    return filled($item) ? strtoupper(trim((string) $item)) : null;
                })
                ->filter()
                ->unique()
                ->values()
                ->all();

            $submittedLookup = array_flip($submittedFeatureKeys);
            $missingFeatureKeys = [];
            $featureConflictMeetings = collect();

            foreach ($officialMeetings as $meeting) {
                $snapshotKeys = data_get($meeting->schedulingDemand?->source_snapshot, 'required_room_feature_keys');
                $requiredKeys = is_array($snapshotKeys)
                    ? $snapshotKeys
                    : ($meeting->schedulingDemand?->courseComponent?->required_room_feature_keys ?? []);

                if (! is_array($requiredKeys) || $requiredKeys === []) {
                    continue;
                }

                $meetingMissing = false;
                foreach ($requiredKeys as $requiredKey) {
                    $normalizedKey = strtoupper(trim((string) $requiredKey));
                    if ($normalizedKey === '' || isset($submittedLookup[$normalizedKey])) {
                        continue;
                    }

                    $missingFeatureKeys[$normalizedKey] = $normalizedKey;
                    $meetingMissing = true;
                }

                if ($meetingMissing) {
                    $featureConflictMeetings->push($meeting);
                }
            }

            if ($missingFeatureKeys !== []) {
                $removedLabels = collect($missingFeatureKeys)
                    ->map(fn (string $key): string => RoomEquipment::label($key))
                    ->implode(', ');
                $affectedSummary = self::formatOfficialMeetingImpactSummary($featureConflictMeetings);
                $errors['features'] = "Cannot remove required equipment ({$removedLabels}) from {$record->code} while assigned to active official classes ({$affectedSummary}). {$guidance}";
            }
        }

        if ($errors === []) {
            return;
        }

        Notification::make()
            ->title("Cannot update {$record->code}")
            ->body((string) collect($errors)->first())
            ->danger()
            ->persistent()
            ->send();

        $statePath = $schema?->getStatePath();

        throw ValidationException::withMessages(
            collect($errors)
                ->mapWithKeys(fn (string $message, string $field): array => [filled($statePath) ? $statePath.'.'.$field : $field => $message])
                ->all(),
        );
    }

    /**
     * @return Collection<int, SectionMeeting>
     */
    private function activeOfficialRoomMeetings(Room $room): Collection
    {
        return self::activeOfficialRoomMeetingsFor($room);
    }

    /**
     * @param  Collection<int, SectionMeeting>  $meetings
     */
    private function formatOfficialMeetingImpact(Collection $meetings): string
    {
        return self::formatOfficialMeetingImpactSummary($meetings);
    }

    private function roomDeactivationModalDescription(Room $record): string
    {
        return self::roomDeactivationDescription($record);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function guardRoomEdit(Room $record, array $data, ?Schema $schema = null): void
    {
        self::validateRoomEdit($record, $data, $schema);
    }

    /**
     * @param  list<int>  $selectedCourseIds
     */
    private function guardFacultySubjectRemoval(User $faculty, array $selectedCourseIds, Schema $schema): void
    {
        $meetings = SectionMeeting::query()
            ->activeOfficial()
            ->where('faculty_user_id', $faculty->id)
            ->whereHas('scheduleRun.term', fn (Builder $query): Builder => $query->where('state', '!=', Term::StateClosed))
            ->with([
                'scheduleRun.term',
                'schedulingDemand.sectionDeliveryGroup.section.courseRevision.course',
                'schedulingDemand.courseComponent.courseSpecification.course',
                'schedulingDemand.termOffering.curriculumEntry.courseSpecification.course',
            ])
            ->get();

        if ($meetings->isEmpty()) {
            return;
        }

        $selectedLookup = array_flip($selectedCourseIds);
        $blocked = [];

        foreach ($meetings as $meeting) {
            $demand = $meeting->schedulingDemand;
            $course = $demand?->courseComponent?->courseSpecification?->course
                ?? $demand?->termOffering?->curriculumEntry?->courseSpecification?->course
                ?? $demand?->sectionDeliveryGroup?->section?->courseRevision?->course;

            if (! $course instanceof Course || isset($selectedLookup[(int) $course->id])) {
                continue;
            }

            $termLabel = (string) ($meeting->scheduleRun?->term?->label ?? 'Active term');
            $sectionCode = (string) ($demand?->sectionDeliveryGroup?->section?->code ?? $course->code);
            $blocked[$course->code][] = "{$termLabel} ({$sectionCode})";
        }

        if ($blocked === []) {
            return;
        }

        $details = collect($blocked)
            ->map(fn (array $refs, string $code): string => $code.' in '.implode(', ', array_unique($refs)))
            ->implode('; ');
        $message = "Cannot remove qualified subjects assigned to active official classes ({$details}). Update the published schedule via Timetable → Revise official schedule first.";

        Notification::make()
            ->title("Cannot update subjects for {$faculty->name}")
            ->body($message)
            ->danger()
            ->persistent()
            ->send();

        throw ValidationException::withMessages([
            $schema->getStatePath().'.course_ids' => $message,
        ]);
    }

    private function hasActiveDraftTimetable(): bool
    {
        return ScheduleGenerationRun::query()
            ->whereIn('status', [
                ScheduleGenerationRun::StatusQueued,
                ScheduleGenerationRun::StatusDispatching,
                ScheduleGenerationRun::StatusUnderReview,
            ])
            ->where(function (Builder $query): void {
                $query->whereNull('candidate_state')
                    ->orWhereNotIn('candidate_state', ['Stale', 'Superseded', 'Rejected']);
            })
            ->whereHas('term', fn (Builder $query): Builder => $query->where('state', '!=', Term::StateClosed))
            ->exists();
    }

    private function refreshActiveTermScheduling(User $actor): void
    {
        $activeTerms = Term::query()
            ->where('state', '!=', Term::StateClosed)
            ->whereHas('calendarPackages', fn (Builder $query): Builder => $query->where('state', TermCalendarPackage::StateActive))
            ->orderBy('starts_on')
            ->get();

        $demandGenerator = app(GenerateSchedulingDemand::class);

        foreach ($activeTerms as $targetTerm) {
            $demandGenerator->forTerm($actor, $targetTerm);

            ScheduleGenerationRun::query()
                ->where('term_id', $targetTerm->id)
                ->whereIn('status', [
                    ScheduleGenerationRun::StatusQueued,
                    ScheduleGenerationRun::StatusDispatching,
                    ScheduleGenerationRun::StatusUnderReview,
                ])
                ->where(function (Builder $query): void {
                    $query->whereNull('candidate_state')
                        ->orWhereNotIn('candidate_state', ['Superseded', 'Rejected']);
                })
                ->update(['candidate_state' => 'Stale', 'updated_at' => now()]);
        }
    }

    private function isReadOnly(): bool
    {
        return ! $this->isRegistrarActor();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
        ]);
    }
}
