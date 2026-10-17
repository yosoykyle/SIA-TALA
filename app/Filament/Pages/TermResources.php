<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Filament\Resources\FacultyTermLoadOverrides\FacultyTermLoadOverrideResource;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\Terms\TermResource;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use UnitEnum;

class TermResources extends TermPlanningWorkbench implements HasTable
{
    use InteractsWithTable;

    #[Url]
    public string $resourceView = 'faculty';

    public function chooseResourceView(string $view): void
    {
        abort_unless(in_array($view, ['faculty', 'rooms'], true), 404);
        $this->resourceView = $view;
        $this->resetTableSearch();
        $this->resetTableColumnSearches();
        $this->resetTable();
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
            $rows = collect($this->facultyPlanningRows());
            $this->resourceViewData['awaitingRequestedResponses'] = $rows->where('status', 'Awaiting response')->count();
            $this->resourceViewData['notRequestedResponses'] = $rows->where('status', 'Not requested')->count();
        }

        return $this->resourceViewData;
    }

    /** @return list<array<string, mixed>> */
    private function facultyPlanningRows(): array
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

        return User::query()->where('status', User::StatusActive)->role(User::StaffRoleFaculty)->orderBy('name')->get()->map(function (User $faculty) use ($qualifications, $overrides, $declarations, $requests, $assignments, $official, $term): array {
            $declaration = $declarations->get($faculty->id);
            $request = $requests->get($faculty->id);
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
                'detail' => $qualifications->get($faculty->id, collect())->map(fn ($qualification): string => ($qualification->course->specifications->first()?->title ?? $qualification->course->code).' ('.$qualification->course->code.')')->implode(', ') ?: 'No eligible courses recorded',
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
        return $table->records(function (?string $search, ?string $sortColumn, ?string $sortDirection, int $page, int $recordsPerPage): LengthAwarePaginator {
            $data = $this->getViewData();
            $records = [];
            foreach ($this->facultyPlanningRows() as $row) {
                $records['faculty-'.$row['faculty_id']] = $row;
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

            return PlanningTableRecords::paginate(array_filter($records, fn (array $row): bool => $row['kind'] === ($this->resourceView === 'rooms' ? 'Rooms' : 'Faculty')), ['name', 'detail', 'evidence', 'status'], $search, $sortColumn, $sortDirection, $page, $recordsPerPage);
        })->columns([
            TextColumn::make('name')->label(fn (): string => $this->resourceView === 'rooms' ? 'Room' : 'Faculty')->weight('bold')->sortable()->wrap(),
            TextColumn::make('detail')->label(fn (): string => $this->resourceView === 'rooms' ? 'Room capacity' : 'Eligible courses')->sortable()->wrap(),
            TextColumn::make('load')->label('Teaching load')->description(fn (array $record): ?string => isset($record['load_source']) ? $record['course_count'].' · '.$record['load_source'] : null)->visible(fn (): bool => $this->resourceView === 'faculty')->wrap(),
            TextColumn::make('status')->label(fn (): string => $this->resourceView === 'rooms' ? 'Catalog state' : 'Recorded availability')->description(fn (array $record): string => implode(' · ', array_filter([$record['availability'] ?? null, $record['evidence'] ?? null])))->badge()->color('gray')->wrap(),
            TextColumn::make('evidence')->label('Source evidence')->wrap()->toggleable(isToggledHiddenByDefault: true),
        ])->searchable()->paginationPageOptions([10, 25, 50])->defaultPaginationPageOption(10)->defaultSort('name')
            ->headerActions([
                Action::make('qualifications')->label('Faculty qualifications')->url(fn (): string => FacultyQualificationResource::getUrl('index', ['termId' => $this->termId]))->color('gray')->visible(fn (): bool => $this->resourceView === 'faculty'),
                Action::make('loads')->label('Term load exceptions')->url(fn (): string => FacultyTermLoadOverrideResource::getUrl('index', ['termId' => $this->termId]))->color('gray')->visible(fn (): bool => $this->resourceView === 'faculty'),
                Action::make('rooms')->label('Manage rooms')->url(fn (): string => RoomResource::getUrl('index', ['termId' => $this->termId]))->color('gray')->visible(fn (): bool => $this->resourceView === 'rooms'),
                Action::make('teachingLoad')->label('Faculty teaching-load default')->url(fn (): string => TermResource::getUrl('edit', ['record' => $this->termId, 'viewTab' => 'resources', 'resourceView' => $this->resourceView]))->color('gray')->visible(fn (): bool => $this->resourceView === 'faculty' && (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar)),
                Action::make('blocks')->label('Weekly unavailable times')->url(fn (): string => CalendarEventResource::getUrl('index', ['termId' => $this->termId, 'resourceView' => $this->resourceView]))->color('gray'),
            ])->recordActions([
                Action::make('source')->label('Qualifications')->button()->color('gray')->visible(fn (array $record): bool => $record['kind'] === 'Faculty')->url(fn (array $record): string => $record['url']),
                Action::make('inspectRoom')->label('Room details')->button()->color('gray')->visible(fn (array $record): bool => $record['kind'] === 'Rooms')
                    ->url(fn (array $record): string => RoomResource::getUrl('view', ['record' => $record['room_id'], 'termId' => $this->termId, 'returnToResources' => true])),
            ])->emptyStateHeading('No teaching resources recorded')->emptyStateDescription('Record faculty qualifications for the offered courses and maintain the shared room catalog.')->stackedOnMobile();
    }
}
