<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\Rooms\Schemas\RoomInfolist;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\Room;
use App\Models\SectionMeeting;
use App\Models\User;
use App\Support\PlanningTableRecords;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
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
        abort_unless(in_array($view, ['faculty', 'rooms'], true), 404);
        $this->resourceView = $view;
        $this->tableColumns = [];
        $this->cachedDefaultTableColumnState = null;
        $this->resetTableSearch();
        $this->resetTableColumnSearches();
        $this->resetTable();
    }

    public function getTableColumnsSessionKey(): string
    {
        return $this->nativeTableColumnsSessionKey().'_'.$this->resourceView;
    }

    private ?array $resourceViewData = null;

    protected static ?string $cluster = TermPlanningCluster::class;

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

            $this->resourceViewData['facultyPlanningRows'] = $rows;
            $this->resourceViewData['termFacultyCount'] = (int) ($projection['total_faculty'] ?? 0);
            $this->resourceViewData['facultyResponseCount'] = (int) ($projection['replied_count'] ?? 0);
            $this->resourceViewData['noResponseCount'] = (int) ($projection['no_response_count'] ?? 0);
            $this->resourceViewData['cannotTeachCount'] = (int) ($projection['cannot_teach_count'] ?? 0);
            $this->resourceViewData['needsGridReviewCount'] = (int) ($projection['needs_grid_review_count'] ?? 0);
            $this->resourceViewData['lateImpactCount'] = (int) ($projection['late_impact_count'] ?? 0);
            $this->resourceViewData['unresolvedLateImpacts'] = $projection['unresolved_late_impacts'] ?? [];
        }

        return $this->resourceViewData;
    }

    public function addRoomAction(): Action
    {
        return Action::make('addRoom')
            ->label('Add room')
            ->icon('heroicon-o-plus')
            ->color('gray')
            ->url(fn (): string => RoomResource::getUrl('create', ['termId' => $this->termId]))
            ->visible(fn (): bool => ! (auth()->user()?->hasRole(User::StaffRoleAcademicHead) ?? true)
                && $this->termId !== null
                && RoomResource::canCreate());
    }

    public function manageTeachingQualificationsAction(): Action
    {
        return Action::make('manageTeachingQualifications')
            ->label('Manage teaching qualifications')
            ->icon('heroicon-o-academic-cap')
            ->color('gray')
            ->url(fn (): string => FacultyQualificationResource::getUrl('index', ['termId' => $this->termId]))
            ->visible(fn (): bool => ! (auth()->user()?->hasRole(User::StaffRoleAcademicHead) ?? true)
                && $this->termId !== null
                && FacultyQualificationResource::canAccess());
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function enrichFacultyRow(array $row): array
    {
        $facultyId = (int) $row['faculty_user_id'];
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
            'url' => FacultyQualificationResource::getUrl('index', [
                'termId' => $this->termId,
                'filters' => ['faculty_user_id' => ['value' => $facultyId]],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function formatAvailabilityDescription(array $row): ?string
    {
        $mode = $row['effective_mode'] ?? null;

        if ($mode === null) {
            return null;
        }

        $dayNames = SectionMeeting::dayOptions();

        return match ($mode) {
            FacultyAvailabilityDeclaration::ModeWindows => $this->formatTeachingWindows($row['teaching_windows'] ?? [], $dayNames),
            FacultyAvailabilityDeclaration::ModeAnyApproved => 'Available during any approved teaching hour',
            FacultyAvailabilityDeclaration::ModeCannotTeach => 'Will not be scheduled this Term',
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
                    'no_response' => $row['declaration'] === null,
                    'cannot_teach' => $row['effective_mode'] === FacultyAvailabilityDeclaration::ModeCannotTeach,
                    'needs_review' => ! empty($row['needs_grid_review']) || ! empty($row['has_official_impact']),
                    default => true,
                };

                if ($matchesStatus) {
                    $records['faculty-'.$row['faculty_id']] = $row;
                }
            }
            foreach ($data['rooms'] as $room) {
                $records['room-'.$room->id] = [
                    'room_id' => $room->id, 'kind' => 'Rooms', 'name' => $room->code.' · '.$room->name,
                    'detail' => (Room::typeOptions()[$room->room_type] ?? $room->room_type).' · '.$room->capacity.' seats',
                    'evidence' => $room->building ?: 'Building not recorded',
                    'status' => 'Active room',
                    'url' => RoomResource::getUrl('view', ['record' => $room, 'termId' => $this->termId]),
                ];
            }

            return PlanningTableRecords::paginate(array_filter($records, fn (array $row): bool => $row['kind'] === ($this->resourceView === 'rooms' ? 'Rooms' : 'Faculty')), ['name', 'detail', 'evidence', 'status', 'availability', 'reminder_status'], $search, in_array($sortColumn, ['room_capacity', 'eligible_courses'], true) ? 'detail' : $sortColumn, $sortDirection, $page, $recordsPerPage);
        })->columns([
            TextColumn::make('name')->label(fn (): string => $this->resourceView === 'rooms' ? 'Room' : 'Faculty')->weight('bold')->sortable()->wrap()
                ->description(fn (array $record): ?string => $record['kind'] === 'Faculty' ? ($record['term_course_count'] > 0 ? $record['term_course_count'].' '.str('eligible course')->plural($record['term_course_count']).' this Term' : $record['eligible_course_count'].' '.str('eligible course')->plural($record['eligible_course_count']).' in catalog') : null),
            TextColumn::make('room_capacity')->label('Room capacity')->state(fn (array $record): string => $record['detail'])->visible(fn (): bool => $this->resourceView === 'rooms')->sortable()->wrap(),
            TextColumn::make('eligible_courses')->label('Eligible courses')->state(fn (array $record): string => $record['detail'])->visible(fn (): bool => $this->resourceView === 'faculty')->sortable()->wrap()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('load')->label('Teaching load')->description(fn (array $record): ?string => isset($record['load_source']) ? $record['course_count'].' · '.$record['load_source'] : null)->visible(fn (): bool => $this->resourceView === 'faculty')->wrap()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('status')->label(fn (): string => $this->resourceView === 'rooms' ? 'Catalog state' : 'Availability')
                ->description(fn (array $record): ?string => $record['availability'] ?? null)
                ->badge()
                ->color(fn (array $record, string $state): string => match ($state) {
                    'No response', 'Active room' => 'gray',
                    'Cannot teach this Term' => 'danger',
                    default => 'success',
                })
                ->wrap(),
            TextColumn::make('cues')->label('Review cues')
                ->state(fn (array $record): array => $record['cues'] ?? [])
                ->badge()
                ->color(fn (string $state): string => $state === 'Official impact' ? 'danger' : 'warning')
                ->placeholder('—')
                ->visible(fn (): bool => $this->resourceView === 'faculty')
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
                ->visible(fn (): bool => $this->resourceView === 'faculty')
                ->wrap(),
            TextColumn::make('evidence')->label('Source evidence')->wrap()->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('faculty_scope')->label('Show faculty')->options(['term' => 'This Term', 'all' => 'All active faculty'])->default('term')->selectablePlaceholder(false)->visible(fn (): bool => $this->resourceView === 'faculty'),
            SelectFilter::make('availability_status')->label('Response status')->options([
                'all' => 'All statuses',
                'replied' => 'Replied',
                'no_response' => 'No response',
                'cannot_teach' => 'Cannot teach this Term',
                'needs_review' => 'Review needed',
            ])->default('all')->selectablePlaceholder(false)->visible(fn (): bool => $this->resourceView === 'faculty'),
        ])->searchable()->defaultSort('name')
            ->headerActions([
                Action::make('rooms')->label(fn (): string => auth()->user()?->hasRole(User::StaffRoleAcademicHead) ? 'View room catalog' : 'Manage rooms')->icon('heroicon-o-building-office')->url(fn (): string => RoomResource::getUrl('index', ['termId' => $this->termId]))->color('gray')->visible(fn (): bool => $this->resourceView === 'rooms'),
            ])->recordActions([
                Action::make('source')->label('Qualifications')->icon('heroicon-o-academic-cap')->button()->color('gray')->visible(fn (array $record): bool => $record['kind'] === 'Faculty')->url(fn (array $record): string => $record['url']),
                Action::make('inspectRoom')->label('Room details')->icon('heroicon-o-eye')->button()->color('gray')->visible(fn (array $record): bool => $record['kind'] === 'Rooms')
                    ->authorize(fn (array $record): bool => RoomResource::canView($this->inspectionRoom($record)))
                    ->modalHeading(fn (array $record): string => ($room = $this->inspectionRoom($record))->code.' · '.$room->name)
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalDescription(fn (): string => collect([$this->selectedTerm()->academicYear?->label, $this->selectedTerm()->label])->filter()->unique()->implode(' · ').' · Shared room catalog')
                    ->schema(fn (Schema $schema, array $record): Schema => RoomInfolist::configure($schema->record($this->inspectionRoom($record)), compact: true))
                    ->extraModalFooterActions(fn (array $record): array => [
                        Action::make('manageRoom')->label('Manage room')->color('gray')->icon('heroicon-o-pencil-square')
                            ->url(RoomResource::getUrl('edit', ['record' => $record['room_id'], 'termId' => $this->termId, 'returnToResources' => true]))
                            ->visible(RoomResource::canEdit($this->inspectionRoom($record))),
                    ])
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')->unsavedChangesAlert(false),
            ])->emptyState(view('filament.partials.collection-empty-state'))
            ->emptyStateHeading(fn (): string => filled($this->getTableSearch())
                ? ($this->resourceView === 'rooms' ? 'No matching rooms' : 'No matching faculty')
                : ($this->resourceView === 'rooms' ? 'No active rooms recorded' : (($this->getTableFilterState('faculty_scope')['value'] ?? 'all') === 'term' ? 'No faculty linked to this Term' : 'No active faculty recorded')))
            ->emptyStateDescription(fn (): string => filled($this->getTableSearch())
                ? 'Try another name or clear the search.'
                : ($this->resourceView === 'rooms'
                    ? (auth()->user()?->hasRole(User::StaffRoleAcademicHead) ? 'The Registrar maintains active rooms in the shared room catalog.' : 'Review the room catalog and record the rooms available for teaching.')
                    : (auth()->user()?->hasRole(User::StaffRoleAcademicHead) ? 'The Registrar prepares courses and records qualified teachers. All active faculty remain available in the filter.' : 'Prepare the courses that will run and record their qualified teachers. Use the filter to inspect all active faculty.')))
            ->emptyStateActions([
                Action::make('reviewClasses')->label('View classes')->color('gray')->icon('heroicon-o-book-open')->url(fn (): string => TermClasses::getUrl(['termId' => $this->termId]))->visible(fn (): bool => $this->resourceView === 'faculty' && blank($this->getTableSearch())),
            ])->stackedOnMobile();
    }

    /** @param array<string, mixed> $record */
    private function inspectionRoom(array $record): Room
    {
        abort_unless(($record['kind'] ?? null) === 'Rooms', 404);
        $this->selectedTerm();

        return RoomResource::getEloquentQuery()->where('is_active', true)->findOrFail($record['room_id'] ?? null);
    }
}
