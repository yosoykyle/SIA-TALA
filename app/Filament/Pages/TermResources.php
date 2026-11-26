<?php

namespace App\Filament\Pages;

use App\Actions\Scheduling\FacultyAvailabilityRequestService;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\Rooms\Schemas\RoomForm;
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
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermOffering;
use App\Models\User;
use App\Support\PlanningTableRecords;
use App\Support\TermPlanningStepState;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
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
            $term = $this->resourceViewData['term'] ?? null;
            $readOnly = (bool) ($this->resourceViewData['readOnly'] ?? false);

            if (! array_key_exists('stepState', $this->resourceViewData) && $term instanceof Term && class_exists(TermPlanningStepState::class)) {
                $this->resourceViewData['stepState'] = TermPlanningStepState::evaluate($term, $readOnly);
            }

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

            $termFacultyCount = (int) ($projection['total_faculty'] ?? 0);
            $activeSchoolFacultyCount = count($rows);
            $readinessBlockers = $this->resourceViewData['readiness']['blockers'] ?? [];

            $roomReadinessBlockers = collect($readinessBlockers)
                ->filter(fn (array $blocker): bool => in_array($blocker['code'] ?? '', ['rooms_unsuitable', 'rooms_unavailable'], true))
                ->values()
                ->all();

            $facultyReadinessBlockers = collect($readinessBlockers)
                ->filter(fn (array $blocker): bool => in_array($blocker['code'] ?? '', [
                    'faculty_demand_uncovered',
                    'fixed_faculty_availability_missing',
                    'fixed_faculty_cannot_teach',
                    'fixed_faculty_no_usable_time',
                ], true))
                ->values()
                ->all();

            $totalRoomCount = Room::query()->count();
            $activeRoomCount = count($this->resourceViewData['rooms'] ?? []);
            $roomClosureCountByRoom = $activeRoomClosures
                ->groupBy('room_id')
                ->map(fn ($items): int => $items->count())
                ->all();

            $activeRoomsCollection = collect($this->resourceViewData['rooms'] ?? []);
            $activeRoomIdsWithClosures = $activeRoomsCollection
                ->filter(fn (Room $room): bool => (int) ($roomClosureCountByRoom[$room->id] ?? 0) > 0)
                ->count();
            $activeLectureRoomCount = $activeRoomsCollection
                ->where('room_type', Room::TypeLectureRoom)
                ->count();
            $activeLabRoomCount = $activeRoomsCollection
                ->whereIn('room_type', [Room::TypeLaboratory, Room::TypeComputerLaboratory])
                ->count();

            $this->resourceViewData['facultyPlanningRows'] = $rows;
            $this->resourceViewData['termFacultyCount'] = $termFacultyCount;
            $this->resourceViewData['activeSchoolFacultyCount'] = $activeSchoolFacultyCount;
            $this->resourceViewData['facultyScopeAutoFallback'] = $termFacultyCount === 0 && $activeSchoolFacultyCount > 0;
            $this->resourceViewData['facultyResponseCount'] = (int) ($projection['replied_count'] ?? 0);
            $this->resourceViewData['noResponseCount'] = (int) ($projection['no_response_count'] ?? 0);
            $this->resourceViewData['cannotTeachCount'] = (int) ($projection['cannot_teach_count'] ?? 0);
            $this->resourceViewData['needsGridReviewCount'] = (int) ($projection['needs_grid_review_count'] ?? 0);
            $this->resourceViewData['lateImpactCount'] = (int) ($projection['late_impact_count'] ?? 0);
            $this->resourceViewData['unresolvedLateImpacts'] = $projection['unresolved_late_impacts'] ?? [];
            $this->resourceViewData['uncoveredSubjects'] = $this->resolveUncoveredOfferedSubjects($this->resourceViewData);
            $this->resourceViewData['facultyReadinessBlockers'] = $facultyReadinessBlockers;
            $this->resourceViewData['roomReadinessBlockers'] = $roomReadinessBlockers;
            $this->resourceViewData['totalRoomCount'] = $totalRoomCount;
            $this->resourceViewData['inactiveRoomCount'] = max(0, $totalRoomCount - $activeRoomCount);
            $this->resourceViewData['activeRoomClosureCount'] = $activeRoomClosures->count();
            $this->resourceViewData['roomClosureCountByRoom'] = $roomClosureCountByRoom;
            $this->resourceViewData['roomTriageCounts'] = [
                'active' => $activeRoomCount,
                'has_closures' => $activeRoomIdsWithClosures,
                'lecture' => $activeLectureRoomCount,
                'laboratory' => $activeLabRoomCount,
                'inactive' => max(0, $totalRoomCount - $activeRoomCount),
            ];
        }

        return $this->resourceViewData;
    }

    /**
     * @return array{all: int, official_impact: int, no_response: int, needs_review: int, cannot_teach: int, replied: int}
     */
    public function facultyTriageCounts(): array
    {
        $rows = $this->getViewData()['facultyPlanningRows'] ?? [];
        $scope = $this->getTableFilterState('faculty_scope')['value'] ?? 'term';
        $hasTermFaculty = collect($rows)->contains(
            fn (array $row): bool => (bool) ($row['in_term'] ?? false),
        );
        $effectiveScope = ($scope === 'term' && ! $hasTermFaculty && $rows !== [])
            ? 'all'
            : $scope;

        $scopedRows = $effectiveScope === 'all'
            ? $rows
            : array_values(array_filter($rows, fn (array $row): bool => ! empty($row['in_term'])));

        $counts = [
            'all' => count($scopedRows),
            'official_impact' => 0,
            'no_response' => 0,
            'needs_review' => 0,
            'cannot_teach' => 0,
            'replied' => 0,
        ];

        foreach ($scopedRows as $row) {
            if (! empty($row['has_official_impact'])) {
                $counts['official_impact']++;
            }
            if ($row['declaration'] === null && empty($row['registrar_not_teaching'])) {
                $counts['no_response']++;
            }
            if (! empty($row['needs_grid_review'])) {
                $counts['needs_review']++;
            }
            if (! empty($row['registrar_not_teaching']) || ($row['effective_mode'] ?? null) === FacultyAvailabilityDeclaration::ModeCannotTeach) {
                $counts['cannot_teach']++;
            }
            if ($row['declaration'] !== null) {
                $counts['replied']++;
            }
        }

        return $counts;
    }

    public function applyFacultyTriageFilter(string $status): void
    {
        abort_unless(in_array($status, ['all', 'official_impact', 'no_response', 'needs_review', 'cannot_teach', 'replied'], true), 404);
        $this->tableFilters['availability_status']['value'] = $status;
        $this->updatedTableFilters();
    }

    public function applyRoomTriageFilter(string $preset): void
    {
        abort_unless(in_array($preset, ['active', 'has_closures', 'lecture', 'laboratory', 'inactive'], true), 404);

        $this->tableFilters['room_status']['value'] = $preset === 'inactive' ? 'inactive' : 'active';
        $this->tableFilters['room_closures']['value'] = $preset === 'has_closures' ? 'has_closures' : 'all';
        $this->tableFilters['room_type']['value'] = match ($preset) {
            'lecture' => Room::TypeLectureRoom,
            'laboratory' => 'laboratory_any',
            default => null,
        };
        $this->updatedTableFilters();
    }

    public function activeRoomTriagePreset(): ?string
    {
        $status = $this->getTableFilterState('room_status')['value'] ?? (Room::query()->where('is_active', true)->exists() ? 'active' : 'all');
        $closures = $this->getTableFilterState('room_closures')['value'] ?? 'all';
        $type = $this->getTableFilterState('room_type')['value'] ?? null;

        if ($status === 'inactive' && $closures === 'all' && blank($type)) {
            return 'inactive';
        }
        if ($status === 'active' && $closures === 'has_closures' && blank($type)) {
            return 'has_closures';
        }
        if ($status === 'active' && $closures === 'all' && $type === Room::TypeLectureRoom) {
            return 'lecture';
        }
        if ($status === 'active' && $closures === 'all' && in_array($type, ['laboratory_any', Room::TypeLaboratory, Room::TypeComputerLaboratory], true)) {
            return 'laboratory';
        }
        if ($status === 'active' && $closures === 'all' && blank($type)) {
            return 'active';
        }

        return null;
    }

    public function formatAvailabilityModeLabel(string $label): string
    {
        return match ($label) {
            'Choose teaching times' => 'Specific hours set',
            'Cannot teach this Term' => 'Cannot teach this term',
            default => $label,
        };
    }

    /**
     * @param  list<int>  $meetingIds
     */
    public function officialRevisionUrlForMeetings(array $meetingIds, ?string $facultyName = null): ?string
    {
        $viewData = $this->getViewData();
        $term = $viewData['term'] ?? null;
        $currentVersion = $viewData['currentVersion'] ?? null;
        $readOnly = (bool) ($viewData['readOnly'] ?? false);

        if (! $term instanceof Term) {
            return null;
        }

        $cleanIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $meetingIds),
            static fn (int $id): bool => $id > 0,
        )));

        $params = [
            'termId' => $term->id,
            'timetableState' => 'official',
        ];

        if ($currentVersion !== null) {
            $params['selectedVersionId'] = $currentVersion->id;
        }

        if (! $readOnly && ! $this->isTermClosed() && $cleanIds !== []) {
            $params['action'] = 'revisePublishedSchedule';
            $params['meetingIds'] = implode(',', $cleanIds);
        }

        if ($facultyName !== null && trim($facultyName) !== '') {
            $params['timetableView'] = 'faculty';
            $params['viewFilter'] = trim($facultyName);
        }

        return TermTimetable::getUrl($params);
    }

    /**
     * @param  array<string, mixed>  $viewData
     * @return list<array{course_id: int, code: string, title: string, label: string}>
     */
    private function resolveUncoveredOfferedSubjects(array $viewData): array
    {
        $term = $viewData['term'] ?? null;
        $offerings = $viewData['offerings'] ?? collect();

        if (! $term instanceof Term || $offerings->isEmpty()) {
            return [];
        }

        $isLiveTerm = $this->hasPublishedTimetable($term);
        $confirmedOfferings = $isLiveTerm
            ? $offerings->filter(fn (TermOffering $offering): bool => $offering->sections
                ->whereNotIn('state', [Section::StateCancelled, Section::StateClosed])
                ->whereNotNull('confirmed_at')
                ->isNotEmpty())
            : $offerings;
        $scopeOfferings = $isLiveTerm && $confirmedOfferings->isNotEmpty()
            ? $confirmedOfferings
            : $offerings;

        $offeredCourses = [];
        foreach ($scopeOfferings as $offering) {
            $spec = $offering->curriculumEntry?->courseSpecification;
            if ($spec instanceof CourseSpecification && $spec->scheduling_treatment === CourseSpecification::SchedulingExternallyArranged) {
                continue;
            }
            $course = $spec?->course;
            if (! $course instanceof Course) {
                continue;
            }

            $courseId = (int) $course->id;
            if (isset($offeredCourses[$courseId])) {
                continue;
            }

            $title = trim((string) ($spec?->title ?? ''));
            $offeredCourses[$courseId] = [
                'course_id' => $courseId,
                'code' => (string) $course->code,
                'title' => $title,
                'label' => $title !== '' ? "{$course->code} · {$title}" : (string) $course->code,
            ];
        }

        if ($offeredCourses === []) {
            return [];
        }

        $qualifiedCourseIds = FacultyQualification::query()
            ->active()
            ->whereIn('course_id', array_keys($offeredCourses))
            ->whereHas('faculty', fn (Builder $query): Builder => $query->where('status', User::StatusActive))
            ->distinct()
            ->pluck('course_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->flip()
            ->all();

        $uncovered = [];
        foreach ($offeredCourses as $courseId => $courseInfo) {
            if (! isset($qualifiedCourseIds[$courseId])) {
                $uncovered[] = $courseInfo;
            }
        }

        usort($uncovered, fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

        return $uncovered;
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
            ->color('primary')
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
                    ->default(fn (): string => $this->defaultProgramFilterForSelection([]))
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
                    ->columns(['default' => 1, 'md' => 2])
                    ->gridDirection(GridDirection::Row)
                    ->extraAttributes(['class' => 'tala-card-picker'])
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

    public function assignTeacherForSubjectAction(): Action
    {
        return Action::make('assignTeacherForSubject')
            ->label('Assign teacher')
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('primary')
            ->size('sm')
            ->modalHeading(function (array $arguments): string {
                $courseId = isset($arguments['courseId']) ? (int) $arguments['courseId'] : 0;
                $label = $courseId > 0 ? ($this->catalogSubjectOptions()[$courseId] ?? null) : null;

                return $label !== null ? 'Assign teacher · '.$label : 'Assign teacher to subject';
            })
            ->modalDescription('Shared across all terms · Select one or more active teachers approved to teach this subject.')
            ->modalWidth(Width::ThreeExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Save teacher assignment')
            ->modalCancelActionLabel('Cancel')
            ->visible(fn (): bool => $this->termId !== null
                && ! ($this->getViewData()['readOnly'] ?? false)
                && ! $this->isTermClosed())
            ->fillForm(function (array $arguments): array {
                $courseId = isset($arguments['courseId']) ? (int) $arguments['courseId'] : 0;
                if ($courseId === 0) {
                    $uncovered = $this->getViewData()['uncoveredSubjects'] ?? [];
                    $courseId = (int) ($uncovered[0]['course_id'] ?? 0);
                }

                $existingFacultyIds = $courseId > 0
                    ? FacultyQualification::query()
                        ->active()
                        ->where('course_id', $courseId)
                        ->pluck('faculty_user_id')
                        ->map(fn (mixed $id): string => (string) $id)
                        ->values()
                        ->all()
                    : [];

                return [
                    'course_id' => $courseId > 0 ? $courseId : null,
                    'faculty_user_ids' => $existingFacultyIds,
                    'notes' => null,
                ];
            })
            ->schema([
                Select::make('course_id')
                    ->label('Subject')
                    ->placeholder('Choose an offered subject')
                    ->options(fn (): array => $this->catalogSubjectOptions())
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        if (blank($state)) {
                            $set('faculty_user_ids', []);

                            return;
                        }

                        $existingFacultyIds = FacultyQualification::query()
                            ->active()
                            ->where('course_id', (int) $state)
                            ->pluck('faculty_user_id')
                            ->map(fn (mixed $id): string => (string) $id)
                            ->values()
                            ->all();

                        $set('faculty_user_ids', $existingFacultyIds);
                    }),
                CheckboxList::make('faculty_user_ids')
                    ->label('Active faculty')
                    ->options(fn (): array => User::query()
                        ->where('status', User::StatusActive)
                        ->whereHas('roles', fn (Builder $query): Builder => $query->where('name', User::StaffRoleFaculty))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->descriptions(function (): array {
                        $rowsById = collect($this->getViewData()['facultyPlanningRows'] ?? [])
                            ->keyBy('faculty_user_id');

                        return $rowsById->map(function (array $row): string {
                            $parts = [
                                $this->formatAvailabilityModeLabel((string) ($row['status'] ?? 'No response')),
                                ((int) ($row['eligible_course_count'] ?? 0)).' '.str('subject')->plural((int) ($row['eligible_course_count'] ?? 0)).' qualified',
                            ];

                            return implode(' · ', $parts);
                        })->all();
                    })
                    ->searchable()
                    ->searchPrompt('Search faculty by name...')
                    ->columns(['default' => 1, 'md' => 2])
                    ->gridDirection(GridDirection::Row)
                    ->extraAttributes(['class' => 'tala-card-picker'])
                    ->helperText('Updates school-wide qualifications for this subject. Unchecking a teacher removes their qualification unless they have live official classes in it.'),
                Textarea::make('notes')
                    ->label('Notes (optional)')
                    ->rows(2)
                    ->maxLength(1000),
            ])
            ->action(function (array $data, Schema $schema): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);
                abort_if($this->isTermClosed(), 403);

                $course = Course::query()
                    ->whereKey((int) ($data['course_id'] ?? 0))
                    ->where('state', Course::StateActive)
                    ->firstOrFail();

                $facultyIds = collect($data['faculty_user_ids'] ?? [])
                    ->map(fn (mixed $id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->unique()
                    ->values()
                    ->all();

                $facultyUsers = User::query()
                    ->whereIn('id', $facultyIds)
                    ->where('status', User::StatusActive)
                    ->whereHas('roles', fn (Builder $query): Builder => $query->where('name', User::StaffRoleFaculty))
                    ->get();

                $selectedFacultyIds = $facultyUsers->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

                $currentFacultyIds = FacultyQualification::query()
                    ->where('course_id', $course->id)
                    ->where('is_active', true)
                    ->pluck('faculty_user_id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();

                $removedFacultyIds = array_values(array_diff($currentFacultyIds, $selectedFacultyIds));

                if ($removedFacultyIds !== []) {
                    $activeOfficialMeetings = SectionMeeting::query()
                        ->activeOfficial()
                        ->whereIn('faculty_user_id', $removedFacultyIds)
                        ->whereHas('scheduleRun.term', fn (Builder $query): Builder => $query->where('state', '!=', Term::StateClosed))
                        ->with([
                            'faculty',
                            'scheduleRun.term',
                            'schedulingDemand.courseComponent.courseSpecification.course',
                            'schedulingDemand.termOffering.curriculumEntry.courseSpecification.course',
                        ])
                        ->get();

                    $conflicts = [];

                    foreach ($activeOfficialMeetings as $meeting) {
                        $meetingCourse = $meeting->schedulingDemand?->courseComponent?->courseSpecification?->course
                            ?? $meeting->schedulingDemand?->termOffering?->curriculumEntry?->courseSpecification?->course;

                        if (! $meetingCourse instanceof Course || (int) $meetingCourse->id !== (int) $course->id) {
                            continue;
                        }

                        $facultyName = (string) ($meeting->faculty?->name ?? 'Assigned teacher');
                        $termLabel = $meeting->scheduleRun?->term?->label ?: ($meeting->scheduleRun?->term?->typeLabel() ?? 'Active term');
                        $conflicts[$meeting->faculty_user_id.'|'.$termLabel] = "{$facultyName} ({$termLabel})";
                    }

                    if ($conflicts !== []) {
                        $message = 'Cannot remove qualification for '.$course->code.' because '.implode(', ', array_values($conflicts)).' currently teaches live official classes. Reassign those classes via Timetable -> Revise official schedule first.';

                        Notification::make()
                            ->title('Cannot remove qualified teacher')
                            ->body($message)
                            ->danger()
                            ->persistent()
                            ->send();

                        throw ValidationException::withMessages([
                            $schema->getStatePath().'.faculty_user_ids' => $message,
                        ]);
                    }
                }

                $trimmedNotes = filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null;

                DB::transaction(function () use ($facultyUsers, $removedFacultyIds, $course, $actor, $trimmedNotes): void {
                    $now = now();

                    if ($removedFacultyIds !== []) {
                        FacultyQualification::query()
                            ->where('course_id', $course->id)
                            ->where('is_active', true)
                            ->whereIn('faculty_user_id', $removedFacultyIds)
                            ->update(['is_active' => false]);

                        $removedUsers = User::query()->whereIn('id', $removedFacultyIds)->get();
                        foreach ($removedUsers as $removedFaculty) {
                            activity()
                                ->performedOn($removedFaculty)
                                ->causedBy($actor)
                                ->event('faculty_qualifications_synced')
                                ->withProperties([
                                    'faculty_user_id' => $removedFaculty->id,
                                    'course_id' => $course->id,
                                    'action' => 'removed',
                                ])
                                ->log('Removed qualified subject from faculty');
                        }
                    }

                    foreach ($facultyUsers as $faculty) {
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
                                'course_id' => $course->id,
                            ],
                            $attributes,
                        );

                        activity()
                            ->performedOn($faculty)
                            ->causedBy($actor)
                            ->event('faculty_qualifications_synced')
                            ->withProperties([
                                'faculty_user_id' => $faculty->id,
                                'course_id' => $course->id,
                            ])
                            ->log('Assigned qualified subject to faculty');
                    }
                });

                $this->refreshActiveTermScheduling($actor);
                $this->resetResourceCaches();

                Notification::make()
                    ->title('Qualified teachers updated for '.$course->code)
                    ->body($facultyUsers->count().' '.str('teacher')->plural($facultyUsers->count()).' now qualified for '.$course->code.'.')
                    ->success()
                    ->send();
            });
    }

    public function addRoomAction(): Action
    {
        return CreateAction::make('addRoom')
            ->label('Add room')
            ->icon(Heroicon::OutlinedPlus)
            ->color('primary')
            ->size('sm')
            ->model(Room::class)
            ->modalHeading('Add room')
            ->modalDescription('Shared across all terms · Adds a teaching room to the shared campus room catalog.')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Add room')
            ->createAnother(false)
            ->schema(fn (Schema $schema): Schema => RoomForm::configure($schema))
            ->authorize(fn (): bool => ! ($this->getViewData()['readOnly'] ?? false)
                && ! $this->isTermClosed()
                && RoomResource::canCreate())
            ->visible(fn (): bool => ! ($this->getViewData()['readOnly'] ?? false)
                && ! $this->isTermClosed()
                && RoomResource::canCreate())
            ->after(function (): void {
                $actor = auth()->user();
                if ($actor instanceof User) {
                    $this->refreshActiveTermScheduling($actor);
                }
                $this->resetResourceCaches();
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

        $affectedMeetings = is_array($row['affected_meetings'] ?? null)
            ? array_values($row['affected_meetings'])
            : [];
        $affectedMeetingIds = array_values(array_unique(array_filter(array_map(
            static fn (array $meeting): int => (int) ($meeting['section_meeting_id'] ?? 0),
            $affectedMeetings,
        ))));
        $affectedMeetingsSummary = collect($affectedMeetings)
            ->map(static fn (array $meeting): string => trim(
                ($meeting['course_code'] ?? '').' '
                .($meeting['section_code'] ?? '')
                .(filled($meeting['day_label'] ?? null) || filled($meeting['time_label'] ?? null)
                    ? ' ('.trim(($meeting['day_label'] ?? '').' '.($meeting['time_label'] ?? '')).')'
                    : '')
            ))
            ->filter()
            ->implode('; ');

        $cues = [];
        if (! empty($row['has_official_impact'])) {
            $affectedCount = count($affectedMeetings);
            $cues[] = $affectedCount > 0
                ? $affectedCount.' live '.str('class')->plural($affectedCount).' affected'
                : 'Conflicts with live schedule';
        }
        if (! empty($row['needs_grid_review'])) {
            $cues[] = 'Review needed';
        }
        if (empty($row['in_term'])) {
            $cues[] = (int) ($row['eligible_course_count'] ?? 0) === 0
                ? 'Needs subject assignment'
                : 'Not yet assigned to this term';
        }

        $courseLabels = is_array($row['course_labels'] ?? null)
            ? array_values(array_filter($row['course_labels']))
            : [];
        $courseCodes = array_values(array_unique(array_filter(array_map(
            static function (string $label): string {
                if (preg_match('/\(([^()]+)\)\s*$/', $label, $matches) === 1) {
                    return trim($matches[1]);
                }

                return trim($label);
            },
            $courseLabels,
        ))));
        if ($courseCodes === []) {
            $eligibleCoursesSummary = 'None assigned';
        } elseif (count($courseCodes) <= 3) {
            $eligibleCoursesSummary = implode(', ', $courseCodes);
        } else {
            $eligibleCoursesSummary = implode(', ', array_slice($courseCodes, 0, 3)).' (+'.(count($courseCodes) - 3).' more)';
        }
        $eligibleCoursesTooltip = $courseLabels !== [] ? implode("\n", $courseLabels) : null;

        $formatUnits = static fn (float $value): string => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
        $assignedUnits = (float) ($row['load_units'] ?? 0.0);
        $limitUnits = $row['load_limit'] ?? null;
        $loadDisplay = $limitUnits !== null
            ? $formatUnits($assignedUnits).' / '.$formatUnits((float) $limitUnits).' units'
            : $formatUnits($assignedUnits).' units';

        $assignedClassCount = 0;
        if (preg_match('/^(\d+)\s+courses?\b/i', (string) ($row['course_count'] ?? ''), $classMatches) === 1) {
            $assignedClassCount = (int) $classMatches[1];
        }
        $loadPolicyLabel = match (true) {
            ! empty($row['registrar_not_teaching']) => 'Excluded',
            ! empty($row['load_override_id']) || ($row['load_source'] ?? null) === 'Faculty-specific approved load' => 'Override',
            default => 'Default',
        };
        $loadDetail = $assignedClassCount.' '.str('class')->plural($assignedClassCount).' · '.$loadPolicyLabel;

        $reminderDate = $row['reminder_at']
            ? CarbonImmutable::parse($row['reminder_at'])->timezone($tz)->format('M j, Y · g:i A')
            : null;

        $evidence = $declaration instanceof FacultyAvailabilityDeclaration
            ? 'Responded · '.$declaration->declared_at?->timezone($tz)->format('M j, Y')
            : $row['reminder_status'].($reminderDate ? ' · '.$reminderDate : '');

        return [
            ...$row,
            'cues' => $cues,
            'affected_meeting_ids' => $affectedMeetingIds,
            'affected_meetings_summary' => $affectedMeetingsSummary !== '' ? $affectedMeetingsSummary : null,
            'eligible_courses_summary' => $eligibleCoursesSummary,
            'eligible_courses_tooltip' => $eligibleCoursesTooltip,
            'load_display' => $loadDisplay,
            'load_detail' => $loadDetail,
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
                ? 'Excluded · '.$reason
                : 'Excluded by Registrar';
        }

        $mode = $row['effective_mode'] ?? null;

        if ($mode === null) {
            return null;
        }

        $dayNames = SectionMeeting::dayOptions();

        return match ($mode) {
            FacultyAvailabilityDeclaration::ModeWindows => $this->formatTeachingWindows($row['teaching_windows'] ?? [], $dayNames),
            FacultyAvailabilityDeclaration::ModeAnyApproved => 'All approved hours',
            FacultyAvailabilityDeclaration::ModeCannotTeach => 'Not scheduled this term',
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

    private function hasAnyFacultyReviewCue(): bool
    {
        $data = $this->getViewData();
        $rows = $data['facultyPlanningRows'] ?? [];
        $scope = $this->getTableFilterState('faculty_scope')['value'] ?? 'term';
        $hasTermFaculty = collect($rows)->contains(
            fn (array $row): bool => (bool) ($row['in_term'] ?? false),
        );
        $effectiveScope = ($scope === 'term' && ! $hasTermFaculty && $rows !== [])
            ? 'all'
            : $scope;

        foreach ($rows as $row) {
            if ($effectiveScope !== 'all' && empty($row['in_term'])) {
                continue;
            }

            if (! empty($row['cues'])) {
                return true;
            }
        }

        return false;
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
            $hasTermFaculty = collect($data['facultyPlanningRows'])->contains(
                fn (array $row): bool => (bool) ($row['in_term'] ?? false),
            );
            $effectiveScope = ($scope === 'term' && ! $hasTermFaculty && $data['facultyPlanningRows'] !== [])
                ? 'all'
                : $scope;

            foreach ($data['facultyPlanningRows'] as $row) {
                if ($effectiveScope !== 'all' && ! $row['in_term']) {
                    continue;
                }

                $matchesStatus = match ($statusFilter) {
                    'official_impact' => ! empty($row['has_official_impact']),
                    'replied' => $row['declaration'] !== null,
                    'no_response' => $row['declaration'] === null && empty($row['registrar_not_teaching']),
                    'cannot_teach' => ! empty($row['registrar_not_teaching'])
                        || $row['effective_mode'] === FacultyAvailabilityDeclaration::ModeCannotTeach,
                    'needs_review' => ! empty($row['needs_grid_review']),
                    default => true,
                };

                if ($matchesStatus) {
                    $records['faculty-'.$row['faculty_id']] = $row;
                }
            }

            return PlanningTableRecords::paginate($records, ['name', 'detail', 'eligible_courses_summary', 'evidence', 'status', 'availability', 'reminder_status'], $search, $sortColumn === 'eligible_courses' ? 'detail' : $sortColumn, $sortDirection, $page, $recordsPerPage);
        })->columns([
            TextColumn::make('name')->label('Faculty')->weight('bold')->sortable()
                ->extraCellAttributes(['class' => 'whitespace-nowrap'])
                ->description(function (array $record): string {
                    $termCount = (int) ($record['term_course_count'] ?? 0);
                    if ($termCount > 0) {
                        return $termCount.' '.str('subject')->plural($termCount);
                    }

                    $catalogCount = (int) ($record['eligible_course_count'] ?? 0);
                    if ($catalogCount > 0) {
                        return $catalogCount.' '.str('subject')->plural($catalogCount).' · Not in term';
                    }

                    return '0 subjects';
                }),
            TextColumn::make('eligible_courses')
                ->label('Eligible subjects')
                ->state(fn (array $record): string => (string) ($record['eligible_courses_summary'] ?? 'None assigned'))
                ->tooltip(fn (array $record): ?string => $record['eligible_courses_tooltip'] ?? null)
                ->extraCellAttributes(['class' => 'tala-faculty-subjects-cell'])
                ->sortable()
                ->wrap(),
            TextColumn::make('load')
                ->label('Teaching load')
                ->state(fn (array $record): string => (string) ($record['load_display'] ?? $record['load'] ?? '0 units'))
                ->description(fn (array $record): ?string => $record['load_detail'] ?? null)
                ->extraCellAttributes(['class' => 'whitespace-nowrap tabular-nums']),
            TextColumn::make('status')->label('Availability')
                ->formatStateUsing(fn (string $state): string => $this->formatAvailabilityModeLabel($state))
                ->description(fn (array $record): ?string => $record['availability'] ?? null)
                ->tooltip(fn (array $record): ?string => filled($record['availability'] ?? null) ? (string) $record['availability'] : null)
                ->extraCellAttributes(['class' => 'tala-faculty-availability-cell'])
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'No response' => 'gray',
                    'Cannot teach this Term', 'Cannot teach this term', 'Not teaching (Registrar)' => 'danger',
                    default => 'success',
                }),
            TextColumn::make('cues')->label('Action needed')
                ->state(fn (array $record): array => $record['cues'] ?? [])
                ->description(fn (array $record): ?string => filled($record['affected_meetings_summary'] ?? null) ? (string) $record['affected_meetings_summary'] : null)
                ->badge()
                ->color(fn (string $state): string => match (true) {
                    $state === 'Conflicts with live schedule' || str_contains($state, 'live class') => 'danger',
                    in_array($state, ['Review needed', 'Needs subject assignment'], true) => 'warning',
                    default => 'gray',
                })
                ->placeholder('-')
                ->extraCellAttributes(['class' => 'tala-faculty-action-needed-cell'])
                ->visible(fn (): bool => $this->hasAnyFacultyReviewCue()),
            TextColumn::make('reminder_status')->label('Reminder')
                ->state(fn (array $record): string => (string) ($record['reminder_status'] ?? 'Not sent'))
                ->description(fn (array $record): ?string => $record['reminder_date'] ?? null)
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'Reminder failed' => 'danger',
                    'Reminder sent' => 'info',
                    default => 'gray',
                })
                ->extraCellAttributes(['class' => 'whitespace-nowrap']),
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
                    'official_impact' => 'Affects live schedule',
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
                        'official_impact' => 'Affects live schedule',
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
                Action::make('reviseAffectedSchedule')
                    ->label('Revise')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->size('sm')
                    ->button()
                    ->color('danger')
                    ->extraAttributes(['class' => 'whitespace-nowrap'])
                    ->visible(fn (array $record): bool => ! ($this->getViewData()['readOnly'] ?? false)
                        && ! $this->isTermClosed()
                        && ! empty($record['has_official_impact'])
                        && ! empty($record['affected_meeting_ids'])
                        && ($this->getViewData()['currentVersion'] ?? null) !== null)
                    ->url(fn (array $record): string => $this->officialRevisionUrlForMeetings(
                        $record['affected_meeting_ids'] ?? [],
                        (string) ($record['name'] ?? ''),
                    ) ?? '#'),
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
                            ->columns(['default' => 1, 'md' => 2])
                            ->gridDirection(GridDirection::Row)
                            ->extraAttributes(['class' => 'tala-card-picker'])
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
                                $message = "{$record['name']} currently has {$formattedPublished} assigned units on the published official timetable. Cannot lower their allowed limit to {$formattedAllowed} units: reassign classes via Timetable → Revise official schedule first.";

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
                        ->extraAttributes(fn (array $record): array => isset($publishedMeetingFacultyIds[(int) $record['faculty_user_id']])
                            ? ['aria-disabled' => 'true', 'class' => 'tala-action-disabled']
                            : [])
                        ->modalHeading(fn (array $record): string => 'Mark '.$record['name'].' as not teaching this term')
                        ->modalDescription(fn (array $record): string => isset($publishedMeetingFacultyIds[(int) $record['faculty_user_id']])
                            ? 'Cannot exclude '.$record['name'].' yet because they still have published classes on the official timetable. Reassign their published classes via Timetable → Revise official schedule first.'
                            : 'Sets this teacher\'s teaching load to 0 units for the selected term so automatic scheduling will not assign them classes. Their own availability history stays preserved.')
                        ->modalWidth(Width::Large)
                        ->stickyModalHeader()
                        ->stickyModalFooter()
                        ->modalSubmitActionLabel('Exclude for this term')
                        ->modalSubmitAction(fn (Action $action, array $record): Action|false => isset($publishedMeetingFacultyIds[(int) $record['faculty_user_id']]) ? false : $action)
                        ->modalCancelActionLabel('Cancel')
                        ->schema([
                            Textarea::make('reason')
                                ->label('Reason')
                                ->placeholder('e.g., On study leave, sabbatical, or assigned purely administrative duties this term')
                                ->required()
                                ->rows(3)
                                ->maxLength(500)
                                ->visible(fn (array $record): bool => ! isset($publishedMeetingFacultyIds[(int) $record['faculty_user_id']])),
                        ])
                        ->action(function (array $record, array $data): void {
                            $actor = auth()->user();
                            abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);
                            abort_if($this->isTermClosed(), 403);

                            $term = $this->selectedTerm();
                            $facultyId = (int) $record['faculty_user_id'];
                            $reason = trim((string) ($data['reason'] ?? ''));

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
                : 'No active faculty recorded')
            ->emptyStateDescription(fn (): string => filled($this->getTableSearch())
                ? 'Try another name or clear the search.'
                : (auth()->user()?->hasRole(User::StaffRoleAcademicHead)
                    ? 'The Registrar maintains active faculty accounts and qualified subjects.'
                    : 'Add active faculty accounts or adjust the response status filter above.'))
            ->emptyStateActions([])
            ->stackedOnMobile();
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
                    ->color('gray')
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
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('term_closures')
                    ->label('Term closures')
                    ->badge()
                    ->state(function (Room $record): string {
                        if (! $record->is_active) {
                            return 'Inactive';
                        }

                        $count = (int) (($this->getViewData()['roomClosureCountByRoom'] ?? [])[$record->id] ?? 0);

                        return $count > 0 ? $count.' '.str('closure')->plural($count) : 'Available';
                    })
                    ->color(function (Room $record): string {
                        if (! $record->is_active) {
                            return 'gray';
                        }

                        $count = (int) (($this->getViewData()['roomClosureCountByRoom'] ?? [])[$record->id] ?? 0);

                        return $count > 0 ? 'warning' : 'success';
                    }),
            ])
            ->defaultSort('code')
            ->columnManager(false)
            ->filters([
                SelectFilter::make('room_status')
                    ->label('Status')
                    ->options([
                        'active' => 'Active rooms',
                        'all' => 'All rooms',
                        'inactive' => 'Inactive rooms',
                    ])
                    ->default(fn (): string => Room::query()->where('is_active', true)->exists() ? 'active' : 'all')
                    ->selectablePlaceholder(false)
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? 'active') {
                            'active' => $query->where('is_active', true),
                            'inactive' => $query->where('is_active', false),
                            default => $query,
                        };
                    })
                    ->indicateUsing(function (array $data): array {
                        return match ($data['value'] ?? 'active') {
                            'all' => ['Status: All rooms'],
                            'inactive' => ['Status: Inactive rooms'],
                            default => [],
                        };
                    }),
                SelectFilter::make('room_closures')
                    ->label('Term closures')
                    ->options([
                        'all' => 'All availability',
                        'has_closures' => 'Has term closures',
                    ])
                    ->default('all')
                    ->selectablePlaceholder(false)
                    ->query(function (Builder $query, array $data): Builder {
                        if (($data['value'] ?? 'all') !== 'has_closures') {
                            return $query;
                        }

                        $closedRoomIds = array_keys($this->getViewData()['roomClosureCountByRoom'] ?? []);

                        if ($closedRoomIds === []) {
                            return $query->whereRaw('1 = 0');
                        }

                        return $query->whereIn('id', $closedRoomIds);
                    })
                    ->indicateUsing(fn (array $data): array => ($data['value'] ?? 'all') === 'has_closures'
                        ? ['Term closures: Has term closures']
                        : []),
                SelectFilter::make('room_type')
                    ->label('Room type')
                    ->options([
                        'laboratory_any' => 'All laboratories',
                        ...Room::typeOptions(),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (blank($value)) {
                            return $query;
                        }

                        if ($value === 'laboratory_any') {
                            return $query->whereIn('room_type', [
                                Room::TypeLaboratory,
                                Room::TypeComputerLaboratory,
                            ]);
                        }

                        return $query->where('room_type', $value);
                    }),
            ])
            ->filtersTriggerAction(fn (Action $action): Action => $action->label('Filter rooms')->button()->color('gray')->size('sm'))
            ->toolbarActions([
                $this->addRoomAction(),
            ])
            ->recordActions([
                EditAction::make('editRoom')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->button()
                    ->color('gray')
                    ->size('sm')
                    ->extraAttributes(['class' => 'whitespace-nowrap'])
                    ->modalHeading(fn (Room $record): string => 'Edit '.$record->code.' · '.$record->name)
                    ->modalDescription('Shared across all terms · Updates this room in the shared campus catalog.')
                    ->modalWidth(Width::TwoExtraLarge)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalSubmitActionLabel('Save room')
                    ->schema(fn (Schema $schema): Schema => RoomForm::configure($schema))
                    ->visible(fn (Room $record): bool => ! ($this->getViewData()['readOnly'] ?? false)
                        && ! $this->isTermClosed()
                        && RoomResource::canEdit($record))
                    ->before(function (Room $record, array $data, Action $action): void {
                        SchoolResources::validateRoomEdit($record, $data, $this->getMountedActionSchema(mountedAction: $action));
                    })
                    ->after(function (): void {
                        $actor = auth()->user();
                        if ($actor instanceof User) {
                            $this->refreshActiveTermScheduling($actor);
                        }
                        $this->resetResourceCaches();
                    }),
                Action::make('activateRoom')
                    ->label('Activate')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->button()
                    ->color('primary')
                    ->size('sm')
                    ->extraAttributes(['class' => 'whitespace-nowrap'])
                    ->requiresConfirmation()
                    ->modalHeading(fn (Room $record): string => 'Activate '.$record->code.'?')
                    ->modalDescription(fn (Room $record): string => "Shared across all terms · Reactivating {$record->code} makes it available again for scheduling across all non-closed terms.")
                    ->modalSubmitActionLabel('Activate room')
                    ->visible(fn (Room $record): bool => ! ($this->getViewData()['readOnly'] ?? false)
                        && ! $this->isTermClosed()
                        && RoomResource::canEdit($record)
                        && ! $record->is_active)
                    ->action(function (Room $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar) && RoomResource::canEdit($record), 403);
                        abort_if($this->isTermClosed(), 403);

                        $record->update(['is_active' => true]);
                        $this->refreshActiveTermScheduling($actor);
                        $this->resetResourceCaches();

                        Notification::make()
                            ->title($record->code.' activated')
                            ->success()
                            ->send();
                    }),
                Action::make('roomClosures')
                    ->label('Room closures')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->size('sm')
                    ->button()
                    ->color('gray')
                    ->extraAttributes(['class' => 'whitespace-nowrap'])
                    ->modalHeading(fn (Room $record): string => 'Room closures · '.$record->code.' · '.$record->name)
                    ->modalDescription('Recurring weekly maintenance closures for this room during the selected term only.')
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalSubmitActionLabel('Save closures')
                    ->modalCancelActionLabel('Cancel')
                    ->visible(fn (Room $record): bool => $record->is_active
                        && ! ($this->getViewData()['readOnly'] ?? false)
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
                                    : '-';
                                $end = filled($state['ends_at'] ?? null)
                                    ? CarbonImmutable::parse((string) $state['ends_at'])->format('g:i A')
                                    : '-';
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
                                        ->timezone((string) config('app.timezone'))
                                        ->seconds(false)
                                        ->required(),
                                    TimePicker::make('ends_at')
                                        ->label('To')
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
            ->emptyStateHeading(fn (): string => filled($this->getTableSearch()) ? 'No matching rooms' : 'No rooms in this view')
            ->emptyStateDescription(fn (): string => filled($this->getTableSearch())
                ? 'Try another code, name, or building, or clear the search.'
                : (($this->getViewData()['readOnly'] ?? false)
                    ? 'The Registrar maintains teaching rooms for scheduling.'
                    : 'Add a teaching room below or adjust the status filter to reactivate an inactive room.'))
            ->emptyStateActions([])
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
            'assigned' => 'Currently assigned',
        ];

        if ($payload['needed_ids'] !== []) {
            $options['needed'] = 'Needed this term';
        }

        $options['all'] = 'All catalog subjects';
        $options['shared'] = 'General Education / Shared across programs';

        foreach ($payload['programs'] as $programId => $label) {
            $options['program:'.$programId] = $label;
        }

        return $options;
    }

    /**
     * @param  list<mixed>  $selectedIds
     */
    private function defaultProgramFilterForSelection(array $selectedIds): string
    {
        $payload = $this->catalogSubjectsPayload();
        $missingCount = (int) ($this->getViewData()['missingQualifications'] ?? 0);

        if ($missingCount > 0 && $payload['needed_ids'] !== []) {
            return 'needed';
        }

        if ($selectedIds !== []) {
            return 'assigned';
        }

        return $payload['needed_ids'] !== [] ? 'needed' : 'all';
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
            return $retainedCount.' additional selected '.str('subject')->plural($retainedCount).' outside this filter will be kept when you save. Qualifications apply school-wide across all terms.';
        }

        if ($filter === 'assigned' && (! is_array($visibleIds) || $visibleIds === [])) {
            return 'No subjects selected yet. Switch the filter above to add subjects from the catalog. Qualifications apply school-wide across all terms.';
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
            $programs[(int) $program->id] = "{$program->code} · {$program->name}";
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
        $this->catalogSubjectsCache = null;
        $this->flushCachedTableRecords();
    }

    private function refreshActiveTermScheduling(User $actor): void
    {
        $activeTerms = Term::query()
            ->where('state', '!=', Term::StateClosed)
            ->whereHas('calendarPackages', fn (Builder $query): Builder => $query->where('state', TermCalendarPackage::StateActive))
            ->orderBy('starts_on')
            ->get();

        foreach ($activeTerms as $targetTerm) {
            $this->refreshSchedulingAfterAvailabilityChange($actor, $targetTerm);
        }
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
            ->where(function (Builder $query): void {
                $query->whereNull('candidate_state')
                    ->orWhereNotIn('candidate_state', ['Superseded', 'Rejected']);
            })
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
