<?php

namespace App\Filament\Pages;

use App\Actions\Scheduling\FacultyAvailabilityRequestService;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\Rooms\Schemas\RoomInfolist;
use App\Models\CalendarEvent;
use App\Models\Course;
use App\Models\CourseSpecification;
use App\Models\CurriculumVersion;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\OperationalEvent;
use App\Models\Program;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use App\Support\PlanningTableRecords;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use UnitEnum;

class TermResources extends TermPlanningWorkbench implements HasTable
{
    use InteractsWithTable {
        getTableColumnsSessionKey as protected nativeTableColumnsSessionKey;
    }

    #[Url]
    public string $resourceView = 'faculty';

    public function chooseResourceView(string $view): void
    {
        if (in_array($view, ['limits', 'unavailable'], true)) {
            $view = 'rooms';
        }
        abort_unless(in_array($view, ['faculty', 'rooms'], true), 404);
        $this->resourceView = $view;
        $this->tableColumns = [];
        $this->cachedDefaultTableColumnState = null;
        $this->resetTableSearch();
        $this->resetTableColumnSearches();
        $this->resetTableFiltersForm();
        $this->resetTable();
    }

    public function getTableColumnsSessionKey(): string
    {
        return $this->nativeTableColumnsSessionKey().'_'.$this->resourceView;
    }

    private ?array $resourceViewData = null;

    /**
     * @var array{
     *     options: array<int, string>,
     *     descriptions: array<int, string>,
     *     needed_ids: list<int>,
     *     shared_ids: list<int>,
     *     program_course_ids: array<int, list<int>>,
     *     programs: array<int, string>
     * }|null
     */
    private ?array $catalogSubjectsCache = null;

    protected static ?string $cluster = TermPlanningCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Faculty & rooms';

    protected static ?string $title = 'Faculty & rooms';

    protected static ?string $slug = 'resources';

    protected static ?int $navigationSort = 3;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static bool $shouldRegisterNavigation = true;

    protected static string $planningTab = 'resources';

    protected string $view = 'filament.pages.term-resources';

    public function mount(): void
    {
        parent::mount();
        if (in_array($this->resourceView, ['limits', 'unavailable'], true)) {
            $this->resourceView = 'rooms';
        }
        abort_unless(in_array($this->resourceView, ['faculty', 'rooms'], true), 404);
    }

    protected function getViewData(): array
    {
        if ($this->resourceViewData === null) {
            $this->resourceViewData = parent::getViewData();
            $projection = $this->resourceViewData['availabilityProjection'] ?? null;

            $rows = collect($projection['all_faculty_rows'] ?? [])
                ->map(fn (array $row): array => $this->enrichFacultyRow($row))
                ->values()
                ->all();

            $activeRoomClosures = $this->termId !== null
                ? CalendarEvent::query()
                    ->recurringSchedulingBlocks()
                    ->where('term_id', $this->termId)
                    ->where('scope_type', CalendarEvent::ScopeRoom)
                    ->where('event_type', CalendarEvent::TypeUnavailable)
                    ->where('state', CalendarEvent::StateActive)
                    ->get(['id', 'room_id', 'day_of_week', 'starts_at', 'ends_at'])
                : collect();

            $this->resourceViewData['facultyPlanningRows'] = $rows;
            $this->resourceViewData['termFacultyCount'] = (int) ($projection['total_faculty'] ?? 0);
            $this->resourceViewData['facultyResponseCount'] = (int) ($projection['replied_count'] ?? 0);
            $this->resourceViewData['noResponseCount'] = (int) ($projection['no_response_count'] ?? 0);
            $this->resourceViewData['cannotTeachCount'] = (int) ($projection['cannot_teach_count'] ?? 0);
            $this->resourceViewData['needsGridReviewCount'] = (int) ($projection['needs_grid_review_count'] ?? 0);
            $this->resourceViewData['lateImpactCount'] = (int) ($projection['late_impact_count'] ?? 0);
            $this->resourceViewData['unresolvedLateImpacts'] = $projection['unresolved_late_impacts'] ?? [];
            $this->resourceViewData['activeRoomClosureCount'] = $activeRoomClosures->count();
            $this->resourceViewData['roomClosureCountByRoom'] = $activeRoomClosures
                ->groupBy('room_id')
                ->map(fn ($items): int => $items->count())
                ->all();
        }

        return $this->resourceViewData;
    }

    public function sendAvailabilityReminderAction(): Action
    {
        return parent::sendAvailabilityReminderAction()
            ->size('sm')
            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar)
                && $this->termId !== null
                && ! $this->isTermClosed()
                && ! $this->hasPublishedTimetable());
    }

    public function assignSubjectsAction(): Action
    {
        return Action::make('assignSubjects')
            ->label('Assign subjects')
            ->icon(Heroicon::OutlinedAcademicCap)
            ->color('gray')
            ->size('sm')
            ->modalHeading('Assign qualified subjects')
            ->modalDescription('School-wide qualification shared across all terms · Choose a teacher and check the catalog subjects they are approved to teach.')
            ->modalWidth(Width::ThreeExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Save subjects')
            ->modalCancelActionLabel('Cancel')
            ->visible(fn (): bool => $this->termId !== null
                && ! ($this->getViewData()['readOnly'] ?? false)
                && ! $this->isTermClosed())
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
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                        if (blank($state)) {
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

                        [$visibleSelected, $retainedSelected] = $this->partitionSelectedCourseIds(
                            $activeIds,
                            (string) ($get('program_filter') ?? 'all'),
                        );

                        $set('course_ids', $visibleSelected);
                        $set('retained_course_ids', $retainedSelected);
                    }),
                Select::make('program_filter')
                    ->label('Filter subjects by program')
                    ->options(fn (): array => $this->programFilterOptions())
                    ->default('all')
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->dehydrated(false)
                    ->live()
                    ->visible(fn (Get $get): bool => filled($get('faculty_user_id')))
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                        $this->repartitionSelectedCourseIdsForFilter($get, $set, $state);
                    }),
                CheckboxList::make('course_ids')
                    ->label('Qualified subjects')
                    ->options(fn (Get $get): array => $this->filteredCatalogSubjectOptions($get('program_filter')))
                    ->descriptions(fn (Get $get): array => $this->filteredCatalogSubjectDescriptions($get('program_filter')))
                    ->searchable()
                    ->searchPrompt('Search by subject code or title...')
                    ->bulkToggleable()
                    ->columns(['default' => 1, 'md' => 2])
                    ->gridDirection(GridDirection::Row)
                    ->extraAttributes(['class' => 'tala-card-picker'])
                    ->visible(fn (Get $get): bool => filled($get('faculty_user_id')))
                    ->helperText(fn (Get $get): string => $this->subjectSelectionHelperText($get('retained_course_ids'))),
                Textarea::make('notes')
                    ->label('Notes (optional)')
                    ->rows(2)
                    ->maxLength(1000)
                    ->visible(fn (Get $get): bool => filled($get('faculty_user_id'))),
            ])
            ->action(function (array $data, Schema $schema): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);
                abort_if($this->isTermClosed(), 403);

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

                try {
                    $this->syncFacultySubjects($faculty, $selectedCourseIds, $actor, $data['notes'] ?? null);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(
                        collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => [$schema->getStatePath().'.'.$field => $messages])->all(),
                    );
                }

                Notification::make()
                    ->title('Qualified subjects updated')
                    ->body($faculty->name.' is now qualified for '.count($selectedCourseIds).' '.str('subject')->plural(count($selectedCourseIds)).'.')
                    ->success()
                    ->send();
            });
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function enrichFacultyRow(array $row): array
    {
        $declaration = $row['declaration'] ?? null;
        $tz = (string) config('app.display_timezone', 'Asia/Manila');

        $cues = [];
        if (! empty($row['has_official_impact'])) {
            $cues[] = 'Official impact';
        }
        if (! empty($row['needs_grid_review'])) {
            $cues[] = 'Review needed';
        }

        $reminderDate = $row['reminder_at']
            ? CarbonImmutable::parse($row['reminder_at'])->timezone($tz)->format('M j, Y · g:i A')
            : null;

        $evidence = $declaration instanceof FacultyAvailabilityDeclaration
            ? 'Responded · '.$declaration->declared_at?->timezone($tz)->format('M j, Y')
            : $row['reminder_status'].($reminderDate ? ' · '.$reminderDate : '');

        return [
            ...$row,
            'cues' => $cues,
            'reminder_date' => $reminderDate,
            'evidence' => $evidence,
            'availability' => $this->formatAvailabilityDescription($row),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function formatAvailabilityDescription(array $row): ?string
    {
        if (! empty($row['registrar_not_teaching'])) {
            $reason = trim((string) ($row['registrar_not_teaching_reason'] ?? ''));

            return $reason !== ''
                ? 'Excluded for this term (0 units) · Reason: '.$reason
                : 'Excluded for this term by Registrar (0 teaching units)';
        }

        $mode = $row['effective_mode'] ?? null;

        if ($mode === null) {
            return null;
        }

        $dayNames = SectionMeeting::dayOptions();

        return match ($mode) {
            FacultyAvailabilityDeclaration::ModeWindows => $this->formatTeachingWindows($row['teaching_windows'] ?? [], $dayNames),
            FacultyAvailabilityDeclaration::ModeAnyApproved => 'Available during any approved teaching hour',
            FacultyAvailabilityDeclaration::ModeCannotTeach => 'Will not be scheduled this term',
            FacultyAvailabilityDeclaration::ModeLegacyUnavailability => $this->formatLegacyUnavailability($row['hard_unavailability'] ?? [], $dayNames),
            default => null,
        };
    }

    /**
     * @param  list<array{day_of_week:int,start_time:string,end_time:string}>  $windows
     * @param  array<int, string>  $dayNames
     */
    private function formatTeachingWindows(array $windows, array $dayNames): string
    {
        if ($windows === []) {
            return 'No teaching windows recorded';
        }

        return collect($windows)
            ->groupBy('day_of_week')
            ->map(function ($dayWindows, int $day) use ($dayNames): string {
                $shortDay = substr($dayNames[$day] ?? 'Day', 0, 3);
                $ranges = collect($dayWindows)
                    ->map(fn (array $w): string => CarbonImmutable::parse($w['start_time'])->format('g:i A').'–'.CarbonImmutable::parse($w['end_time'])->format('g:i A'))
                    ->implode(', ');

                return $shortDay.' '.$ranges;
            })
            ->implode('; ');
    }

    /**
     * @param  list<array{day_of_week:int,starts_at:string,ends_at:string}>  $intervals
     * @param  array<int, string>  $dayNames
     */
    private function formatLegacyUnavailability(array $intervals, array $dayNames): string
    {
        if ($intervals === []) {
            return 'No weekly unavailable times';
        }

        return 'Unavailable: '.collect($intervals)
            ->map(fn (array $interval): string => substr($dayNames[(int) $interval['day_of_week']] ?? 'Day', 0, 3).' '.CarbonImmutable::parse($interval['starts_at'])->format('g:i A').'–'.CarbonImmutable::parse($interval['ends_at'])->format('g:i A'))
            ->implode('; ');
    }

    public function table(Table $table): Table
    {
        if ($this->resourceView === 'rooms') {
            return $this->roomsTable($table);
        }

        return $this->facultyTable($table);
    }

    private function facultyTable(Table $table): Table
    {
        $publishedMeetingFacultyIds = $this->termId !== null
            ? SectionMeeting::query()
                ->activeOfficial()
                ->whereHas('scheduleRun', fn (Builder $query): Builder => $query->where('term_id', $this->termId))
                ->distinct()
                ->pluck('faculty_user_id')
                ->filter()
                ->map(fn (mixed $id): int => (int) $id)
                ->flip()
                ->all()
            : [];

        return $table->records(function (?string $search, ?string $sortColumn, ?string $sortDirection, int $page, int $recordsPerPage, ?array $filters): LengthAwarePaginator {
            $data = $this->getViewData();
            $records = [];
            $scope = $filters['faculty_scope']['value'] ?? 'all';
            $statusFilter = $filters['availability_status']['value'] ?? 'all';

            foreach ($data['facultyPlanningRows'] as $row) {
                if ($scope !== 'all' && ! $row['in_term']) {
                    continue;
                }

                $matchesStatus = match ($statusFilter) {
                    'replied' => $row['declaration'] !== null,
                    'no_response' => $row['declaration'] === null && empty($row['registrar_not_teaching']),
                    'cannot_teach' => ! empty($row['registrar_not_teaching'])
                        || $row['effective_mode'] === FacultyAvailabilityDeclaration::ModeCannotTeach,
                    'needs_review' => ! empty($row['needs_grid_review']) || ! empty($row['has_official_impact']),
                    default => true,
                };

                if ($matchesStatus) {
                    $records['faculty-'.$row['faculty_id']] = $row;
                }
            }

            return PlanningTableRecords::paginate($records, ['name', 'detail', 'evidence', 'status', 'availability', 'reminder_status'], $search, $sortColumn === 'eligible_courses' ? 'detail' : $sortColumn, $sortDirection, $page, $recordsPerPage);
        })->columns([
            TextColumn::make('name')->label('Faculty')->weight('bold')->sortable()->wrap()
                ->description(fn (array $record): string => $record['term_course_count'] > 0
                    ? $record['term_course_count'].' '.str('eligible subject')->plural($record['term_course_count']).' this term'
                    : $record['eligible_course_count'].' '.str('eligible subject')->plural($record['eligible_course_count']).' in catalog'),
            TextColumn::make('eligible_courses')->label('Eligible subjects')->state(fn (array $record): string => $record['detail'])->sortable()->wrap()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('load')->label('Teaching load')
                ->description(fn (array $record): ?string => isset($record['load_source']) ? $record['course_count'].' · '.$record['load_source'] : null)
                ->wrap()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('status')->label('Availability')
                ->description(fn (array $record): ?string => $record['availability'] ?? null)
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'No response' => 'gray',
                    'Cannot teach this Term', 'Cannot teach this term', 'Not teaching (Registrar)' => 'danger',
                    default => 'success',
                })
                ->wrap(),
            TextColumn::make('cues')->label('Review cues')
                ->state(fn (array $record): array => $record['cues'] ?? [])
                ->badge()
                ->color(fn (string $state): string => $state === 'Official impact' ? 'danger' : 'warning')
                ->placeholder('—')
                ->wrap(),
            TextColumn::make('reminder_status')->label('Reminder')
                ->state(fn (array $record): string => (string) ($record['reminder_status'] ?? 'Not sent'))
                ->description(fn (array $record): ?string => $record['reminder_date'] ?? null)
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'Reminder failed' => 'danger',
                    'Reminder sent' => 'info',
                    default => 'gray',
                })
                ->wrap(),
            TextColumn::make('evidence')->label('Source evidence')->wrap()->toggleable(isToggledHiddenByDefault: true),
        ])->columnManager(false)->filters([
            SelectFilter::make('faculty_scope')
                ->label('Show faculty')
                ->options(['term' => 'This term', 'all' => 'All active faculty'])
                ->default('term')
                ->selectablePlaceholder(false)
                ->indicateUsing(fn (array $data): array => ($data['value'] ?? 'term') === 'all'
                    ? ['Show faculty: All active faculty']
                    : []),
            SelectFilter::make('availability_status')
                ->label('Response status')
                ->options([
                    'all' => 'All statuses',
                    'replied' => 'Replied',
                    'no_response' => 'No response',
                    'cannot_teach' => 'Cannot teach this term',
                    'needs_review' => 'Review needed',
                ])
                ->default('all')
                ->selectablePlaceholder(false)
                ->indicateUsing(function (array $data): array {
                    $value = $data['value'] ?? 'all';
                    if ($value === 'all' || blank($value)) {
                        return [];
                    }

                    $labels = [
                        'replied' => 'Replied',
                        'no_response' => 'No response',
                        'cannot_teach' => 'Cannot teach this term',
                        'needs_review' => 'Review needed',
                    ];

                    return isset($labels[$value]) ? ['Response status: '.$labels[$value]] : [];
                }),
        ])->filtersTriggerAction(fn (Action $action): Action => $action->label('Filter faculty')->button()->color('gray')->size('sm'))
            ->searchable()->defaultSort('name')
            ->toolbarActions([
                $this->assignSubjectsAction(),
            ])
            ->recordActions([
                Action::make('editFacultySubjects')
                    ->label('Subjects')
                    ->icon(Heroicon::OutlinedAcademicCap)
                    ->size('sm')
                    ->button()
                    ->color('gray')
                    ->extraAttributes(['class' => 'whitespace-nowrap'])
                    ->modalHeading(fn (array $record): string => 'Subjects for '.$record['name'])
                    ->modalDescription('School-wide qualification shared across all terms · Check every catalog subject this teacher is approved to teach.')
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalSubmitActionLabel('Save subjects')
                    ->modalCancelActionLabel('Cancel')
                    ->visible(fn (): bool => ! ($this->getViewData()['readOnly'] ?? false) && ! $this->isTermClosed())
                    ->fillForm(function (array $record): array {
                        $activeIds = array_map(fn (int $id): string => (string) $id, $record['qualified_course_ids'] ?? []);
                        [$visibleSelected, $retainedSelected] = $this->partitionSelectedCourseIds($activeIds, 'all');

                        return [
                            'program_filter' => 'all',
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
                            ->native(false)
                            ->dehydrated(false)
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                $this->repartitionSelectedCourseIdsForFilter($get, $set, $state);
                            }),
                        CheckboxList::make('course_ids')
                            ->label('Qualified subjects')
                            ->options(fn (Get $get): array => $this->filteredCatalogSubjectOptions($get('program_filter')))
                            ->descriptions(fn (Get $get): array => $this->filteredCatalogSubjectDescriptions($get('program_filter')))
                            ->searchable()
                            ->searchPrompt('Search by subject code or title...')
                            ->bulkToggleable()
                            ->columns(['default' => 1, 'md' => 2])
                            ->gridDirection(GridDirection::Row)
                            ->extraAttributes(['class' => 'tala-card-picker'])
                            ->helperText(fn (Get $get): string => $this->subjectSelectionHelperText($get('retained_course_ids'))),
                        Textarea::make('notes')
                            ->label('Notes (optional)')
                            ->rows(2)
                            ->maxLength(1000),
                    ])
                    ->action(function (array $record, array $data, Schema $schema): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);
                        abort_if($this->isTermClosed(), 403);

                        $faculty = User::query()->findOrFail((int) $record['faculty_user_id']);
                        $selectedCourseIds = $this->mergeSelectedCourseIds(
                            $data['course_ids'] ?? [],
                            $data['retained_course_ids'] ?? [],
                        );

                        try {
                            $this->syncFacultySubjects($faculty, $selectedCourseIds, $actor, $data['notes'] ?? null);
                        } catch (ValidationException $exception) {
                            throw ValidationException::withMessages(
                                collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => [$schema->getStatePath().'.'.$field => $messages])->all(),
                            );
                        }

                        Notification::make()
                            ->title('Subjects updated for '.$faculty->name)
                            ->body(count($selectedCourseIds).' qualified '.str('subject')->plural(count($selectedCourseIds)).' active.')
                            ->success()
                            ->send();
                    }),
                Action::make('resendReminder')
                    ->label('Resend reminder')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->size('sm')
                    ->button()
                    ->color('warning')
                    ->extraAttributes(['class' => 'whitespace-nowrap'])
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => 'Resend availability reminder to '.$record['name'].'?')
                    ->modalDescription('Queues a new delivery attempt for the failed availability reminder email.')
                    ->modalSubmitActionLabel('Resend reminder')
                    ->visible(fn (array $record): bool => ! ($this->getViewData()['readOnly'] ?? false)
                        && ! $this->isTermClosed()
                        && ! $this->hasPublishedTimetable()
                        && ($record['reminder_status'] ?? null) === 'Reminder failed'
                        && ($record['reminder_event'] ?? null) instanceof OperationalEvent)
                    ->action(function (array $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);
                        abort_if($this->isTermClosed() || $this->hasPublishedTimetable(), 403);

                        $event = $record['reminder_event'] ?? null;
                        if (! $event instanceof OperationalEvent) {
                            return;
                        }

                        try {
                            app(FacultyAvailabilityRequestService::class)->resend($event, $actor);
                        } catch (ValidationException $exception) {
                            $message = collect($exception->errors())->flatten()->first();
                            Notification::make()
                                ->title('Cannot resend reminder')
                                ->body(is_string($message) ? $message : 'The failed reminder could not be resent.')
                                ->danger()
                                ->persistent()
                                ->send();

                            return;
                        }

                        $this->resetResourceCaches();

                        Notification::make()
                            ->title('Reminder resent to '.$record['name'])
                            ->body('A new availability reminder email has been queued.')
                            ->success()
                            ->send();
                    }),
                ActionGroup::make([
                    Action::make('editLoadOverride')
                        ->label('Load override')
                        ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                        ->modalHeading(fn (array $record): string => 'Teaching load override · '.$record['name'])
                        ->modalDescription('Approve additional teaching units above this term’s default limit, or disable an existing override.')
                        ->modalWidth(Width::Large)
                        ->stickyModalHeader()
                        ->stickyModalFooter()
                        ->modalSubmitActionLabel('Save override')
                        ->modalCancelActionLabel('Cancel')
                        ->visible(fn (): bool => ! $this->isTermClosed())
                        ->fillForm(function (array $record): array {
                            $term = $this->selectedTerm();
                            $override = FacultyTermLoadOverride::query()
                                ->where('term_id', $term->id)
                                ->where('faculty_user_id', (int) $record['faculty_user_id'])
                                ->first();

                            return [
                                'default_max_units_snapshot' => $override instanceof FacultyTermLoadOverride && (float) $override->default_max_units_snapshot > 0
                                    ? (float) $override->default_max_units_snapshot
                                    : (float) ($term->default_max_units ?? 24),
                                'approved_overload_units' => $override instanceof FacultyTermLoadOverride ? (float) $override->approved_overload_units : 0,
                                'authority' => $override?->authority ?? '',
                                'reason' => $override?->reason ?? '',
                                'is_active' => $override instanceof FacultyTermLoadOverride ? (bool) $override->is_active : true,
                            ];
                        })
                        ->schema(function (): array {
                            $defaultLimit = (float) ($this->selectedTerm()->default_max_units ?? 24);
                            $formattedDefault = rtrim(rtrim(number_format($defaultLimit, 2, '.', ''), '0'), '.');

                            return [
                                Placeholder::make('base_limit_note')
                                    ->label('Term base limit')
                                    ->content("{$formattedDefault} units per teacher"),
                                Grid::make(['default' => 1, 'md' => 2])->schema([
                                    TextInput::make('default_max_units_snapshot')
                                        ->label('Term base limit (units)')
                                        ->helperText('Override only if this teacher has a custom or reduced base load; use Approved additional units for overload.')
                                        ->numeric()
                                        ->minValue(0)
                                        ->step(0.25)
                                        ->required(),
                                    TextInput::make('approved_overload_units')
                                        ->label('Approved additional units')
                                        ->helperText('Extra units approved above the term base limit.')
                                        ->numeric()
                                        ->minValue(0)
                                        ->step(0.25)
                                        ->default(0)
                                        ->live()
                                        ->required(),
                                ]),
                                TextInput::make('authority')
                                    ->label('Overload approval memo or reference')
                                    ->placeholder('e.g., Memo 2026-014 or Dean approval')
                                    ->helperText('Required when approving overload units above the base limit.')
                                    ->maxLength(255)
                                    ->visible(fn (Get $get): bool => (float) ($get('approved_overload_units') ?? 0) > 0)
                                    ->required(fn (Get $get): bool => (float) ($get('approved_overload_units') ?? 0) > 0),
                                Textarea::make('reason')
                                    ->label('Reason')
                                    ->rows(3)
                                    ->maxLength(2000)
                                    ->required(),
                                Toggle::make('is_active')
                                    ->label('Active override')
                                    ->default(true),
                            ];
                        })
                        ->action(function (array $record, array $data, Schema $schema): void {
                            $actor = auth()->user();
                            abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);
                            abort_if($this->isTermClosed(), 403);

                            $term = $this->selectedTerm();
                            $facultyId = (int) $record['faculty_user_id'];
                            $baseUnits = (float) ($data['default_max_units_snapshot'] ?? 0);
                            $overloadUnits = (float) ($data['approved_overload_units'] ?? 0);
                            $isActive = (bool) ($data['is_active'] ?? true);
                            $effectiveAllowed = $isActive
                                ? ($baseUnits + $overloadUnits)
                                : (float) ($term->default_max_units ?? 24);
                            $publishedLoadUnits = $this->publishedOfficialLoadUnits($term, $facultyId);

                            if ($publishedLoadUnits > 0.0 && ($effectiveAllowed + 0.0001) < $publishedLoadUnits) {
                                $formattedPublished = rtrim(rtrim(number_format($publishedLoadUnits, 2, '.', ''), '0'), '.');
                                $formattedAllowed = rtrim(rtrim(number_format($effectiveAllowed, 2, '.', ''), '0'), '.');
                                $message = "{$record['name']} currently has {$formattedPublished} assigned units on the published official timetable. Cannot lower their allowed limit to {$formattedAllowed} units—reassign classes via Timetable → Revise official schedule first.";

                                Notification::make()
                                    ->title('Cannot lower teaching load below published load')
                                    ->body($message)
                                    ->danger()
                                    ->persistent()
                                    ->send();

                                throw ValidationException::withMessages([
                                    $schema->getStatePath().'.default_max_units_snapshot' => $message,
                                ]);
                            }

                            $authority = $overloadUnits > 0.0 && filled($data['authority'] ?? null)
                                ? trim((string) $data['authority'])
                                : null;

                            DB::transaction(function () use ($term, $facultyId, $actor, $data, $baseUnits, $overloadUnits, $authority, $isActive): void {
                                $override = FacultyTermLoadOverride::query()->updateOrCreate(
                                    [
                                        'faculty_user_id' => $facultyId,
                                        'term_id' => $term->id,
                                    ],
                                    [
                                        'default_max_units_snapshot' => $baseUnits,
                                        'approved_overload_units' => $overloadUnits,
                                        'authority' => $authority,
                                        'reason' => trim((string) $data['reason']),
                                        'is_active' => $isActive,
                                    ],
                                );

                                activity()
                                    ->performedOn($override)
                                    ->causedBy($actor)
                                    ->event('faculty_load_override_saved')
                                    ->withProperties([
                                        'term_id' => $term->id,
                                        'faculty_user_id' => $facultyId,
                                        'allowed_units' => $override->allowedLoadUnits(),
                                        'is_active' => $override->is_active,
                                    ])
                                    ->log('Saved faculty teaching load override');
                            });

                            $this->refreshSchedulingAfterAvailabilityChange($actor, $term);
                            $this->resetResourceCaches();

                            Notification::make()
                                ->title('Load override saved for '.$record['name'])
                                ->success()
                                ->send();
                        }),
                    Action::make('markNotTeaching')
                        ->label('Not teaching')
                        ->icon(Heroicon::OutlinedNoSymbol)
                        ->color('danger')
                        ->visible(fn (array $record): bool => ! $this->isTermClosed()
                            && empty($record['registrar_not_teaching'])
                            && ($record['effective_mode'] ?? null) !== FacultyAvailabilityDeclaration::ModeCannotTeach)
                        ->disabled(fn (array $record): bool => isset($publishedMeetingFacultyIds[(int) $record['faculty_user_id']]))
                        ->tooltip(fn (array $record): ?string => isset($publishedMeetingFacultyIds[(int) $record['faculty_user_id']])
                            ? 'Reassign their published classes on the Timetable first.'
                            : null)
                        ->modalHeading(fn (array $record): string => 'Mark '.$record['name'].' as not teaching this term')
                        ->modalDescription('Sets this teacher’s teaching load to 0 units for the selected term so automatic scheduling will not assign them classes. Their own availability history stays preserved.')
                        ->modalWidth(Width::Large)
                        ->stickyModalHeader()
                        ->stickyModalFooter()
                        ->modalSubmitActionLabel('Exclude for this term')
                        ->modalCancelActionLabel('Cancel')
                        ->schema([
                            Textarea::make('reason')
                                ->label('Reason')
                                ->placeholder('e.g., On study leave, sabbatical, or assigned purely administrative duties this term')
                                ->required()
                                ->rows(3)
                                ->maxLength(500),
                        ])
                        ->action(function (array $record, array $data): void {
                            $actor = auth()->user();
                            abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);
                            abort_if($this->isTermClosed(), 403);

                            $term = $this->selectedTerm();
                            $facultyId = (int) $record['faculty_user_id'];
                            $reason = trim((string) $data['reason']);

                            $hasPublishedMeetings = SectionMeeting::query()
                                ->activeOfficial()
                                ->where('faculty_user_id', $facultyId)
                                ->whereHas('scheduleRun', fn (Builder $query): Builder => $query->where('term_id', $term->id))
                                ->exists();

                            if ($hasPublishedMeetings) {
                                Notification::make()
                                    ->title($record['name'].' still has published classes this term')
                                    ->body('Reassign their published classes on the Timetable first, then mark them as not teaching.')
                                    ->warning()
                                    ->persistent()
                                    ->send();

                                return;
                            }

                            DB::transaction(function () use ($term, $facultyId, $actor, $reason): void {
                                $override = FacultyTermLoadOverride::query()->updateOrCreate(
                                    [
                                        'faculty_user_id' => $facultyId,
                                        'term_id' => $term->id,
                                    ],
                                    [
                                        'default_max_units_snapshot' => 0,
                                        'approved_overload_units' => 0,
                                        'authority' => null,
                                        'reason' => $reason,
                                        'is_active' => true,
                                    ],
                                );

                                activity()
                                    ->performedOn($override)
                                    ->causedBy($actor)
                                    ->event('faculty_marked_not_teaching_for_term')
                                    ->withProperties([
                                        'term_id' => $term->id,
                                        'faculty_user_id' => $facultyId,
                                        'reason' => $reason,
                                    ])
                                    ->log('Marked faculty as not teaching for term');
                            });

                            $facultyUser = User::query()->find($facultyId);
                            if ($facultyUser instanceof User) {
                                FacultyAvailabilityRequestService::notifyTeachingStatusChanged($facultyUser, $term, true, $reason);
                            }

                            $this->refreshSchedulingAfterAvailabilityChange($actor, $term);
                            $this->resetResourceCaches();

                            Notification::make()
                                ->title($record['name'].' excluded for this term')
                                ->body('Teaching load set to 0 units. They will not be assigned classes in this term.')
                                ->success()
                                ->send();
                        }),
                    Action::make('restoreTeaching')
                        ->label('Restore teaching')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->visible(fn (array $record): bool => ! $this->isTermClosed() && ! empty($record['registrar_not_teaching']))
                        ->requiresConfirmation()
                        ->modalHeading(fn (array $record): string => 'Restore '.$record['name'].' for this term?')
                        ->modalDescription('Removes the Registrar 0-unit exclusion so this teacher returns to the term’s default teaching limit.')
                        ->modalSubmitActionLabel('Restore teaching')
                        ->action(function (array $record): void {
                            $actor = auth()->user();
                            abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);
                            abort_if($this->isTermClosed(), 403);

                            $term = $this->selectedTerm();
                            $facultyId = (int) $record['faculty_user_id'];

                            DB::transaction(function () use ($term, $facultyId, $actor): void {
                                $overrides = FacultyTermLoadOverride::query()
                                    ->where('term_id', $term->id)
                                    ->where('faculty_user_id', $facultyId)
                                    ->where('is_active', true)
                                    ->get();

                                foreach ($overrides as $override) {
                                    if ($override->allowedLoadUnits() <= 0.0) {
                                        $override->update(['is_active' => false]);
                                        activity()
                                            ->performedOn($override)
                                            ->causedBy($actor)
                                            ->event('faculty_restored_for_term')
                                            ->withProperties([
                                                'term_id' => $term->id,
                                                'faculty_user_id' => $facultyId,
                                            ])
                                            ->log('Restored faculty teaching eligibility for term');
                                    }
                                }
                            });

                            $facultyUser = User::query()->find($facultyId);
                            if ($facultyUser instanceof User) {
                                FacultyAvailabilityRequestService::notifyTeachingStatusChanged($facultyUser, $term, false);
                            }

                            $this->refreshSchedulingAfterAvailabilityChange($actor, $term);
                            $this->resetResourceCaches();

                            Notification::make()
                                ->title($record['name'].' restored for this term')
                                ->success()
                                ->send();
                        }),
                ])
                    ->label('Actions')
                    ->icon(Heroicon::OutlinedEllipsisVertical)
                    ->size('sm')
                    ->color('gray')
                    ->visible(fn (): bool => ! ($this->getViewData()['readOnly'] ?? false) && ! $this->isTermClosed()),
            ])
            ->emptyState(view('filament.partials.collection-empty-state'))
            ->emptyStateHeading(fn (): string => filled($this->getTableSearch())
                ? 'No matching faculty'
                : (($this->getTableFilterState('faculty_scope')['value'] ?? 'all') === 'term' ? 'No faculty linked to this term' : 'No active faculty recorded'))
            ->emptyStateDescription(fn (): string => filled($this->getTableSearch())
                ? 'Try another name or clear the search.'
                : (auth()->user()?->hasRole(User::StaffRoleAcademicHead)
                    ? 'The Registrar adds classes and assigns qualified teachers. All active faculty remain available in the filter.'
                    : 'Add classes first and assign qualified teachers using Assign subjects. Use the filter to view all active faculty.'))
            ->emptyStateActions([
                Action::make('reviewClasses')
                    ->label('View classes')
                    ->color('gray')
                    ->icon(Heroicon::OutlinedRectangleGroup)
                    ->url(fn (): string => TermClasses::getUrl(['termId' => $this->termId]))
                    ->visible(fn (): bool => blank($this->getTableSearch())),
            ])
            ->stackedOnMobile();
    }

    private function roomsTable(Table $table): Table
    {
        return $table
            ->query(Room::query()->where('is_active', true)->with('features'))
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
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => Room::typeOptions()[$state] ?? $state)
                    ->sortable(),
                TextColumn::make('capacity')
                    ->label('Capacity')
                    ->suffix(' seats')
                    ->sortable(),
                TextColumn::make('building')
                    ->label('Building')
                    ->placeholder('Not recorded')
                    ->sortable()
                    ->wrap(),
                TextColumn::make('term_closures')
                    ->label('Term closures')
                    ->badge()
                    ->state(function (Room $record): string {
                        $count = (int) (($this->getViewData()['roomClosureCountByRoom'] ?? [])[$record->id] ?? 0);

                        return $count > 0 ? $count.' '.str('closure')->plural($count) : 'Available';
                    })
                    ->color(function (Room $record): string {
                        $count = (int) (($this->getViewData()['roomClosureCountByRoom'] ?? [])[$record->id] ?? 0);

                        return $count > 0 ? 'warning' : 'success';
                    }),
            ])
            ->defaultSort('code')
            ->columnManager(false)
            ->filters([
                SelectFilter::make('room_type')
                    ->label('Room type')
                    ->options(Room::typeOptions()),
            ])
            ->filtersTriggerAction(fn (Action $action): Action => $action->label('Filter rooms')->button()->color('gray')->size('sm'))
            ->recordActions([
                Action::make('roomClosures')
                    ->label('Room closures')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->size('sm')
                    ->button()
                    ->color('gray')
                    ->extraAttributes(['class' => 'whitespace-nowrap'])
                    ->modalHeading(fn (Room $record): string => 'Room closures · '.$record->code.' · '.$record->name)
                    ->modalDescription('Recurring weekly maintenance closures for this room during the selected term only. Time zone: Asia/Manila.')
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalSubmitActionLabel('Save closures')
                    ->modalCancelActionLabel('Cancel')
                    ->visible(fn (): bool => ! ($this->getViewData()['readOnly'] ?? false)
                        && ! $this->isTermClosed()
                        && ! $this->hasPublishedTimetable())
                    ->fillForm(function (Room $record): array {
                        $closures = CalendarEvent::query()
                            ->recurringSchedulingBlocks()
                            ->where('term_id', $this->termId ?? 0)
                            ->where('scope_type', CalendarEvent::ScopeRoom)
                            ->where('event_type', CalendarEvent::TypeUnavailable)
                            ->where('room_id', $record->id)
                            ->orderBy('day_of_week')
                            ->orderBy('starts_at')
                            ->get();

                        return [
                            'closures' => $closures->map(fn (CalendarEvent $event): array => [
                                'id' => $event->id,
                                'day_of_week' => (string) $event->day_of_week,
                                'starts_at' => $event->starts_at?->format('H:i'),
                                'ends_at' => $event->ends_at?->format('H:i'),
                                'is_active' => $event->state === CalendarEvent::StateActive,
                            ])->values()->all(),
                        ];
                    })
                    ->schema([
                        Repeater::make('closures')
                            ->label('Weekly maintenance closures')
                            ->addActionLabel('Add closure window')
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->itemLabel(function (array $state): ?string {
                                if (blank($state['day_of_week'] ?? null)) {
                                    return 'New closure window';
                                }

                                $day = CalendarEvent::dayOptions()[(int) $state['day_of_week']] ?? 'Day';
                                $start = filled($state['starts_at'] ?? null)
                                    ? CarbonImmutable::parse((string) $state['starts_at'])->format('g:i A')
                                    : '—';
                                $end = filled($state['ends_at'] ?? null)
                                    ? CarbonImmutable::parse((string) $state['ends_at'])->format('g:i A')
                                    : '—';
                                $status = ($state['is_active'] ?? true) ? 'Active' : 'Inactive';

                                return "{$day} · {$start}–{$end} ({$status})";
                            })
                            ->schema([
                                Grid::make(['default' => 1, 'sm' => 2, 'lg' => 4])->schema([
                                    Select::make('day_of_week')
                                        ->label('Day')
                                        ->options(CalendarEvent::dayOptions())
                                        ->required(),
                                    TimePicker::make('starts_at')
                                        ->label('From')
                                        ->native(false)
                                        ->displayFormat('g:i A')
                                        ->timezone((string) config('app.timezone'))
                                        ->seconds(false)
                                        ->required(),
                                    TimePicker::make('ends_at')
                                        ->label('To')
                                        ->native(false)
                                        ->displayFormat('g:i A')
                                        ->timezone((string) config('app.timezone'))
                                        ->seconds(false)
                                        ->after('starts_at')
                                        ->required(),
                                    Toggle::make('is_active')
                                        ->label('Active')
                                        ->default(true)
                                        ->inline(false),
                                ]),
                            ]),
                    ])
                    ->action(function (Room $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);
                        abort_if($this->isTermClosed() || $this->hasPublishedTimetable(), 403);

                        $term = $this->selectedTerm();
                        $submitted = collect($data['closures'] ?? []);

                        DB::transaction(function () use ($term, $record, $actor, $submitted): void {
                            $existing = CalendarEvent::query()
                                ->recurringSchedulingBlocks()
                                ->where('term_id', $term->id)
                                ->where('scope_type', CalendarEvent::ScopeRoom)
                                ->where('event_type', CalendarEvent::TypeUnavailable)
                                ->where('room_id', $record->id)
                                ->get()
                                ->keyBy('id');

                            $keptIds = [];
                            $authority = $actor->name.' ('.($actor->getRoleNames()->first() ?? 'authorized staff').')';

                            foreach ($submitted as $row) {
                                $eventId = isset($row['id']) ? (int) $row['id'] : null;
                                $payload = [
                                    'term_id' => $term->id,
                                    'event_type' => CalendarEvent::TypeUnavailable,
                                    'scope_type' => CalendarEvent::ScopeRoom,
                                    'faculty_user_id' => null,
                                    'room_id' => $record->id,
                                    'process_key' => CalendarEvent::ProcessMasterSchedule,
                                    'start_at' => null,
                                    'end_at' => null,
                                    'day_of_week' => (int) $row['day_of_week'],
                                    'starts_at' => $row['starts_at'],
                                    'ends_at' => $row['ends_at'],
                                    'blocks_scheduling' => true,
                                    'state' => ($row['is_active'] ?? true) ? CalendarEvent::StateActive : CalendarEvent::StateInactive,
                                    'authority' => $authority,
                                ];

                                if ($eventId !== null && $existing->has($eventId)) {
                                    $existing->get($eventId)?->update($payload);
                                    $keptIds[] = $eventId;
                                } else {
                                    $created = CalendarEvent::query()->create($payload);
                                    $keptIds[] = (int) $created->id;
                                }
                            }

                            $removedIds = $existing->keys()->diff($keptIds)->all();
                            if ($removedIds !== []) {
                                CalendarEvent::query()->whereIn('id', $removedIds)->delete();
                            }
                        });

                        $this->refreshSchedulingAfterAvailabilityChange($actor, $term);
                        $this->resetResourceCaches();

                        Notification::make()
                            ->title('Room closures updated for '.$record->code)
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
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalDescription(fn (): string => collect([$this->selectedTerm()->academicYear?->label, $this->selectedTerm()->label])->filter()->unique()->implode(' · ').' · Shared room catalog')
                    ->schema(fn (Schema $schema, Room $record): Schema => RoomInfolist::configure($schema->record($record), compact: true))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->unsavedChangesAlert(false),
            ])
            ->emptyState(view('filament.partials.collection-empty-state'))
            ->emptyStateHeading(fn (): string => filled($this->getTableSearch()) ? 'No matching rooms' : 'No active rooms in catalog')
            ->emptyStateDescription(fn (): string => filled($this->getTableSearch())
                ? 'Try another code, name, or building, or clear the search.'
                : (($this->getViewData()['readOnly'] ?? false)
                    ? 'The Registrar maintains active teaching rooms in School Resources.'
                    : 'Add or activate teaching rooms in School Resources so the timetable generator can assign face-to-face classes.'))
            ->emptyStateActions([
                Action::make('openSchoolRooms')
                    ->label('Manage room catalog')
                    ->color('gray')
                    ->icon(Heroicon::OutlinedBuildingOffice2)
                    ->url(fn (): string => SchoolResources::getUrl([
                        'tab' => 'rooms',
                        ...($this->termId !== null ? ['termId' => $this->termId] : []),
                    ]))
                    ->visible(fn (): bool => blank($this->getTableSearch())),
            ])
            ->stackedOnMobile();
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
            'all' => 'All catalog subjects',
        ];

        if ($payload['needed_ids'] !== []) {
            $options['needed'] = 'Needed in this term';
        }

        $options['shared'] = 'General Education / Shared across programs';

        foreach ($payload['programs'] as $programId => $label) {
            $options['program:'.$programId] = $label;
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private function filteredCatalogSubjectOptions(?string $filter): array
    {
        $payload = $this->catalogSubjectsPayload();
        $allowedIds = $this->filteredCourseIdsForFilter($filter, $payload);

        if ($allowedIds === null) {
            return $payload['options'];
        }

        return array_intersect_key($payload['options'], array_flip($allowedIds));
    }

    /**
     * @return array<int, string>
     */
    private function filteredCatalogSubjectDescriptions(?string $filter): array
    {
        $payload = $this->catalogSubjectsPayload();
        $allowedIds = $this->filteredCourseIdsForFilter($filter, $payload);

        if ($allowedIds === null) {
            return $payload['descriptions'];
        }

        return array_intersect_key($payload['descriptions'], array_flip($allowedIds));
    }

    /**
     * @param  array{
     *     options: array<int, string>,
     *     descriptions: array<int, string>,
     *     needed_ids: list<int>,
     *     shared_ids: list<int>,
     *     program_course_ids: array<int, list<int>>,
     *     programs: array<int, string>
     * }  $payload
     * @return list<int>|null
     */
    private function filteredCourseIdsForFilter(?string $filter, array $payload): ?array
    {
        $normalized = filled($filter) ? (string) $filter : 'all';

        if ($normalized === 'all') {
            return null;
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

        return null;
    }

    /**
     * Split a complete list of selected course IDs into [visibleIds, retainedHiddenIds] for the active filter.
     *
     * @param  list<mixed>  $allSelected
     * @return array{0: list<string>, 1: list<string>}
     */
    private function partitionSelectedCourseIds(array $allSelected, ?string $filter): array
    {
        $normalizedSelected = collect($allSelected)
            ->map(fn (mixed $id): string => (string) (int) $id)
            ->filter(fn (string $id): bool => (int) $id > 0)
            ->unique()
            ->values()
            ->all();

        $visibleKeys = array_map(
            'strval',
            array_keys($this->filteredCatalogSubjectOptions($filter)),
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

        [$visible, $retained] = $this->partitionSelectedCourseIds($allSelected, $filter);

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

    private function subjectSelectionHelperText(mixed $retainedIds): string
    {
        $retainedCount = collect(is_array($retainedIds) ? $retainedIds : [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->count();

        if ($retainedCount > 0) {
            return $retainedCount.' additional selected '.str('subject')->plural($retainedCount).' outside this filter will be kept when you save. Qualifications apply school-wide across all terms.';
        }

        return 'School-wide qualification shared across all terms. Check every subject this teacher can be scheduled to teach.';
    }

    /**
     * @return array{
     *     options: array<int, string>,
     *     descriptions: array<int, string>,
     *     needed_ids: list<int>,
     *     shared_ids: list<int>,
     *     program_course_ids: array<int, list<int>>,
     *     programs: array<int, string>
     * }
     */
    private function catalogSubjectsPayload(): array
    {
        if ($this->catalogSubjectsCache !== null) {
            return $this->catalogSubjectsCache;
        }

        $offeredCourseIds = $this->termId !== null
            ? TermOffering::query()
                ->where('term_id', $this->termId)
                ->where('state', '!=', TermOffering::StateCancelled)
                ->with('curriculumEntry.courseSpecification')
                ->get()
                ->map(fn (TermOffering $offering): ?int => $offering->curriculumEntry?->courseSpecification?->course_id)
                ->filter()
                ->unique()
                ->flip()
                ->all()
            : [];

        $activePrograms = Program::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $programById = $activePrograms->keyBy('id');
        $programs = [];
        $programCourseIds = [];

        foreach ($activePrograms as $program) {
            $programs[(int) $program->id] = "{$program->code} — {$program->name}";
            $programCourseIds[(int) $program->id] = [];
        }

        $courseProgramIds = [];
        if ($activePrograms->isNotEmpty()) {
            $courseProgramRows = DB::table('curriculum_entries')
                ->join('curriculum_versions', 'curriculum_versions.id', '=', 'curriculum_entries.curriculum_version_id')
                ->join('course_specifications', 'course_specifications.id', '=', 'curriculum_entries.course_specification_id')
                ->whereIn('curriculum_versions.state', [
                    CurriculumVersion::StateActive,
                    CurriculumVersion::StateDraft,
                ])
                ->whereIn('curriculum_versions.program_id', $activePrograms->pluck('id'))
                ->select('course_specifications.course_id', 'curriculum_versions.program_id')
                ->distinct()
                ->get();

            foreach ($courseProgramRows as $row) {
                $courseId = (int) $row->course_id;
                $programId = (int) $row->program_id;
                $courseProgramIds[$courseId][$programId] = $programId;
                $programCourseIds[$programId][$courseId] = $courseId;
            }
        }

        foreach ($programCourseIds as $programId => $ids) {
            $programCourseIds[$programId] = array_values($ids);
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
            $options[$courseId] = $title !== '' ? "{$course->code} — {$title}" : $course->code;

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
        ];
    }

    /**
     * @param  list<int>  $selectedCourseIds
     */
    private function syncFacultySubjects(User $faculty, array $selectedCourseIds, User $actor, ?string $notes = null): void
    {
        $currentCourseIds = FacultyQualification::query()
            ->where('faculty_user_id', $faculty->id)
            ->where('is_active', true)
            ->pluck('course_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $removedCourseIds = array_values(array_diff($currentCourseIds, $selectedCourseIds));

        if ($removedCourseIds !== []) {
            $activeOfficialMeetings = SectionMeeting::query()
                ->activeOfficial()
                ->where('faculty_user_id', $faculty->id)
                ->whereHas('scheduleRun.term', fn (Builder $query): Builder => $query->where('state', '!=', Term::StateClosed))
                ->with([
                    'scheduleRun.term',
                    'schedulingDemand.courseComponent.courseSpecification.course',
                    'schedulingDemand.termOffering.curriculumEntry.courseSpecification.course',
                ])
                ->get();

            $conflicts = [];

            foreach ($activeOfficialMeetings as $meeting) {
                $course = $meeting->schedulingDemand?->courseComponent?->courseSpecification?->course
                    ?? $meeting->schedulingDemand?->termOffering?->curriculumEntry?->courseSpecification?->course;

                if (! $course instanceof Course || ! in_array((int) $course->id, $removedCourseIds, true)) {
                    continue;
                }

                $termLabel = $meeting->scheduleRun?->term?->label ?: ($meeting->scheduleRun?->term?->typeLabel() ?? 'Active term');
                $conflicts[$course->id.'|'.$termLabel] = "{$course->code} ({$termLabel})";
            }

            if ($conflicts !== []) {
                $message = $faculty->name.' is currently assigned to published official meetings for '.implode(', ', array_values($conflicts)).'. Reassign those classes via Timetable → Revise official schedule first.';

                Notification::make()
                    ->title('Cannot remove qualified subject')
                    ->body($message)
                    ->danger()
                    ->persistent()
                    ->send();

                throw ValidationException::withMessages([
                    'course_ids' => $message,
                ]);
            }
        }

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

        $openTerms = Term::query()
            ->where('state', '!=', Term::StateClosed)
            ->get();

        foreach ($openTerms as $openTerm) {
            $this->refreshSchedulingAfterAvailabilityChange($actor, $openTerm);
        }

        $this->resetResourceCaches();
    }

    private function publishedOfficialLoadUnits(Term $term, int $facultyId): float
    {
        $meetings = SectionMeeting::query()
            ->activeOfficial()
            ->where('faculty_user_id', $facultyId)
            ->whereHas('scheduleRun', fn (Builder $query): Builder => $query->where('term_id', $term->id))
            ->with([
                'schedulingDemand.sectionDeliveryGroup.section.courseRevision',
                'schedulingDemand.termOffering.curriculumEntry.courseSpecification',
                'schedulingDemand.courseComponent.courseSpecification',
            ])
            ->get();

        $loadGroups = [];

        foreach ($meetings as $meeting) {
            $demand = $meeting->schedulingDemand;
            if ($demand === null) {
                continue;
            }

            $key = ((int) $demand->term_offering_id).':'.((int) $demand->section_delivery_group_id);
            $spec = $demand->sectionDeliveryGroup?->section?->courseRevision
                ?? $demand->courseComponent?->courseSpecification
                ?? $demand->termOffering?->curriculumEntry?->courseSpecification;
            $units = (float) ($spec?->credit_units ?? 0.0);

            $loadGroups[$key] = max((float) ($loadGroups[$key] ?? 0.0), $units);
        }

        return round(array_sum($loadGroups), 2);
    }

    private function resetResourceCaches(): void
    {
        $this->resourceViewData = null;
        $this->flushCachedTableRecords();
    }

    /**
     * Rebuilds scheduling demand from the changed Faculty availability and
     * marks unreviewed candidate runs stale so planners regenerate them.
     */
    private function refreshSchedulingAfterAvailabilityChange(User $actor, Term $term): void
    {
        app(GenerateSchedulingDemand::class)->forTerm($actor, $term);

        ScheduleGenerationRun::query()
            ->where('term_id', $term->id)
            ->whereIn('status', [
                ScheduleGenerationRun::StatusQueued,
                ScheduleGenerationRun::StatusDispatching,
                ScheduleGenerationRun::StatusUnderReview,
            ])
            ->update(['candidate_state' => 'Stale', 'updated_at' => now()]);
    }

    /** @return Model|array<string, mixed>|null */
    protected function resolveTableRecord(?string $key): Model|array|null
    {
        if ($key === null) {
            return null;
        }

        if ($this->resourceView === 'rooms') {
            return Room::query()->with('features')->find($key);
        }

        if (str_starts_with($key, 'faculty-')) {
            $data = $this->getViewData();
            $facultyId = (int) substr($key, strlen('faculty-'));
            $row = collect($data['facultyPlanningRows'] ?? [])->first(
                fn (array $item): bool => (int) ($item['faculty_id'] ?? 0) === $facultyId,
            );

            return $row === null ? null : [...$row, '__key' => $key];
        }

        return null;
    }
}
