<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\Rooms\Schemas\RoomInfolist;
use App\Models\CourseSpecification;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\OperationalEvent;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\SectionMeeting;
use App\Models\TermCalendarPackage;
use App\Models\User;
use App\Support\PlanningTableRecords;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
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
            $this->resourceViewData['facultyPlanningRows'] = $this->facultyPlanningRows($this->resourceViewData['offerings']->map(fn ($offering): ?int => $offering->course()?->id)->filter()->unique()->all());
            $rows = collect($this->resourceViewData['facultyPlanningRows'])->where('in_term', true);
            $this->resourceViewData['termFacultyCount'] = $rows->count();
            $this->resourceViewData['facultyResponseCount'] = $rows->whereIn('status', ['Can teach this Term', 'Cannot teach this Term'])->count();
            $this->resourceViewData['awaitingRequestedResponses'] = $rows->where('status', 'Awaiting response')->count();
            $this->resourceViewData['notRequestedResponses'] = $rows->where('status', 'Not requested')->count();
        }

        return $this->resourceViewData;
    }

    /**
     * @param  list<int>  $courseIds
     * @return list<array<string, mixed>>
     */
    private function facultyPlanningRows(array $courseIds): array
    {
        $qualifications = FacultyQualification::query()->active()->with(['course.specifications' => fn ($query) => $query->where('state', CourseSpecification::StateActive)->latest('id')])->get()->groupBy('faculty_user_id');
        $overrides = FacultyTermLoadOverride::query()->where('term_id', $this->termId)->where('is_active', true)->get()->keyBy('faculty_user_id');
        $declarations = FacultyAvailabilityDeclaration::query()->where('term_id', $this->termId)->latest('version')->get()->unique('faculty_user_id')->keyBy('faculty_user_id');
        $packageIds = $this->activeCalendarPackage()?->compatibleCoreSourceIds() ?? [];
        $requests = OperationalEvent::query()->where('event_type', OperationalEvent::TypeFacultyAvailabilityRequestedEmail)
            ->where('related_record_type', TermCalendarPackage::class)->whereIn('related_record_id', $packageIds)->latest('id')->get()->unique('user_id')->keyBy('user_id');
        $official = PublishedTimetableVersion::query()->where('term_id', $this->termId)->where('state', PublishedTimetableVersion::StatePublished)->with('meetings.classOffering.courseRevision')->first();
        $assignments = $official?->meetings->groupBy('faculty_user_id') ?? collect();
        $term = $this->selectedTerm();

        return User::query()->where('status', User::StatusActive)->role(User::StaffRoleFaculty)->orderBy('name')->get()->map(function (User $faculty) use ($qualifications, $overrides, $declarations, $requests, $assignments, $official, $term, $courseIds): array {
            $declaration = $declarations->get($faculty->id);
            $request = $requests->get($faculty->id);
            $eligibleCourses = $qualifications->get($faculty->id, collect())->unique('course_id');
            $termCourseCount = $eligibleCourses->whereIn('course_id', $courseIds)->count();
            $classes = $assignments->get($faculty->id, collect())->pluck('classOffering')->filter()->unique('id');
            $limit = $overrides->get($faculty->id)?->allowedLoadUnits() ?? $term->default_max_units;
            $load = $classes->sum(fn ($class): float => (float) $class->courseRevision?->credit_units);
            $status = $declaration ? ($declaration->declaration === FacultyAvailabilityDeclaration::DeclarationUnavailable ? 'Cannot teach this Term' : 'Can teach this Term')
                : match ($request?->status) {
                    OperationalEvent::StatusProcessed => $request->sent_at ? 'Awaiting response' : 'Request recorded',
                    OperationalEvent::StatusPending => 'Request queued',
                    OperationalEvent::StatusFailed => 'Request failed',
                    default => 'Not requested',
                };

            return [
                'kind' => 'Faculty', 'faculty_id' => $faculty->id, 'name' => $faculty->name,
                'eligible_course_count' => $eligibleCourses->count(),
                'term_course_count' => $termCourseCount,
                'in_term' => $termCourseCount > 0 || $declaration !== null || $request !== null || $classes->isNotEmpty(),
                'detail' => $eligibleCourses->map(fn ($qualification): string => ($qualification->course->specifications->first()?->title ?? $qualification->course->code).' ('.$qualification->course->code.')')->implode(', ') ?: 'No eligible courses recorded',
                'status' => $status,
                'load' => $official ? $load.' / '.($limit ?? 'not recorded').' units' : 'Unassigned · limit '.($limit ?? 'not recorded').' units',
                'load_source' => $overrides->has($faculty->id) ? 'Faculty-specific approved load' : 'Term teaching-load default',
                'course_count' => $official ? $classes->pluck('courseRevision.course_id')->unique()->count().' courses · official v'.$official->version : 'No official assignments yet',
                'evidence' => $declaration ? 'Declaration v'.$declaration->version.' · '.$declaration->declared_at?->format('M j, Y') : ($request ? 'Request '.strtolower($request->status).' · '.$request->occurred_at?->format('M j, Y') : 'No request sent for this calendar'),
                'availability' => $declaration === null ? null : ($declaration->declaration === FacultyAvailabilityDeclaration::DeclarationUnavailable ? 'Unavailable for the whole Term' : (empty($declaration->hard_unavailability) ? 'No weekly unavailable times' : collect($declaration->hard_unavailability)->map(fn (array $interval): string => (SectionMeeting::dayOptions()[(int) $interval['day_of_week']] ?? 'Unknown day').' '.CarbonImmutable::parse($interval['starts_at'])->format('g:i A').'–'.CarbonImmutable::parse($interval['ends_at'])->format('g:i A'))->implode('; '))),
                'url' => FacultyQualificationResource::getUrl('index', ['termId' => $this->termId, 'filters' => ['faculty_user_id' => ['value' => $faculty->id]]]),
            ];
        })->all();
    }

    public function table(Table $table): Table
    {
        return $table->records(function (?string $search, ?string $sortColumn, ?string $sortDirection, int $page, int $recordsPerPage, array $filters): LengthAwarePaginator {
            $data = $this->getViewData();
            $records = [];
            foreach ($data['facultyPlanningRows'] as $row) {
                if (($filters['faculty_scope']['value'] ?? 'all') === 'all' || $row['in_term']) {
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

            return PlanningTableRecords::paginate(array_filter($records, fn (array $row): bool => $row['kind'] === ($this->resourceView === 'rooms' ? 'Rooms' : 'Faculty')), ['name', 'detail', 'evidence', 'status'], $search, in_array($sortColumn, ['room_capacity', 'eligible_courses'], true) ? 'detail' : $sortColumn, $sortDirection, $page, $recordsPerPage);
        })->columns([
            TextColumn::make('name')->label(fn (): string => $this->resourceView === 'rooms' ? 'Room' : 'Faculty')->weight('bold')->sortable()->wrap()
                ->description(fn (array $record): ?string => $record['kind'] === 'Faculty' ? ($record['term_course_count'] > 0 ? $record['term_course_count'].' '.str('eligible course')->plural($record['term_course_count']).' this Term' : $record['eligible_course_count'].' '.str('eligible course')->plural($record['eligible_course_count']).' in catalog') : null),
            TextColumn::make('room_capacity')->label('Room capacity')->state(fn (array $record): string => $record['detail'])->visible(fn (): bool => $this->resourceView === 'rooms')->sortable()->wrap(),
            TextColumn::make('eligible_courses')->label('Eligible courses')->state(fn (array $record): string => $record['detail'])->visible(fn (): bool => $this->resourceView === 'faculty')->sortable()->wrap()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('load')->label('Teaching load')->description(fn (array $record): ?string => isset($record['load_source']) ? $record['course_count'].' · '.$record['load_source'] : null)->visible(fn (): bool => $this->resourceView === 'faculty')->wrap()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('status')->label(fn (): string => $this->resourceView === 'rooms' ? 'Catalog state' : 'Recorded availability')->description(fn (array $record): ?string => $record['availability'] ?? null)->badge()->color('gray')->wrap(),
            TextColumn::make('evidence')->label('Source evidence')->wrap()->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('faculty_scope')->label('Show faculty')->options(['term' => 'This Term', 'all' => 'All active faculty'])->default('term')->selectablePlaceholder(false)->visible(fn (): bool => $this->resourceView === 'faculty'),
        ])->searchable()->paginationPageOptions([10, 25, 50])->defaultPaginationPageOption(10)->defaultSort('name')
            ->headerActions([
                Action::make('rooms')->label(fn (): string => auth()->user()?->hasRole(User::StaffRoleAcademicHead) ? 'View room catalog' : 'Manage rooms')->icon('heroicon-o-building-office')->url(fn (): string => RoomResource::getUrl('index', ['termId' => $this->termId]))->color('gray')->visible(fn (): bool => $this->resourceView === 'rooms'),
            ])->recordActions([
                Action::make('source')->label('Qualifications')->icon('heroicon-o-academic-cap')->button()->color('gray')->visible(fn (array $record): bool => $record['kind'] === 'Faculty')->url(fn (array $record): string => $record['url']),
                Action::make('inspectRoom')->label('Room details')->icon('heroicon-o-eye')->button()->color('gray')->visible(fn (array $record): bool => $record['kind'] === 'Rooms')
                    ->authorize(fn (array $record): bool => RoomResource::canView($this->inspectionRoom($record)))
                    ->modalHeading(fn (array $record): string => ($room = $this->inspectionRoom($record))->code.' · '.$room->name)
                    ->modalDescription(fn (): string => collect([$this->selectedTerm()->academicYear?->label, $this->selectedTerm()->label])->filter()->unique()->implode(' · ').' · Shared room catalog')
                    ->schema(fn (Schema $schema, array $record): Schema => RoomInfolist::configure($schema->record($this->inspectionRoom($record)), compact: true))
                    ->extraModalFooterActions(fn (array $record): array => [
                        Action::make('manageRoom')->label('Manage room')->color('gray')->icon('heroicon-o-pencil-square')
                            ->url(RoomResource::getUrl('edit', ['record' => $record['room_id'], 'termId' => $this->termId, 'returnToResources' => true]))
                            ->visible(RoomResource::canEdit($this->inspectionRoom($record))),
                    ])

                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')->unsavedChangesAlert(false),
            ])->emptyStateHeading(fn (): string => filled($this->getTableSearch())
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
