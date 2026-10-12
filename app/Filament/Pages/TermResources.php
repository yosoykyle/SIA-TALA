<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Filament\Resources\FacultyTermLoadOverrides\FacultyTermLoadOverrideResource;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\Rooms\Schemas\RoomInfolist;
use App\Filament\Resources\Terms\TermResource;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\Room;
use App\Models\SectionMeeting;
use App\Models\User;
use App\Support\PlanningTableRecords;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
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
        return $this->resourceViewData ??= parent::getViewData();
    }

    public function table(Table $table): Table
    {
        return $table->records(function (?string $search, ?string $sortColumn, ?string $sortDirection, int $page, int $recordsPerPage): LengthAwarePaginator {
            $data = $this->getViewData();
            $records = [];
            foreach ($data['facultyRecords'] as $row) {
                $declaration = $row['declaration'];
                $records['faculty-'.$row['faculty']->id] = [
                    'kind' => 'Faculty', 'name' => $row['faculty']->name,
                    'detail' => implode(', ', $row['course_labels']),
                    'evidence' => $declaration ? 'Declaration v'.$declaration->version.' · '.$declaration->declared_at?->format('M j, Y') : 'No declaration recorded for this Term',
                    'status' => $declaration === null ? 'Awaiting response' : ($declaration->declaration === FacultyAvailabilityDeclaration::DeclarationUnavailable ? 'Cannot teach this Term' : 'Can teach this Term'),
                    'availability' => $declaration === null ? null : ($declaration->declaration === FacultyAvailabilityDeclaration::DeclarationUnavailable ? 'Unavailable for the whole Term' : (empty($declaration->hard_unavailability) ? 'No weekly unavailable times' : collect($declaration->hard_unavailability)->map(fn (array $interval): string => (SectionMeeting::dayOptions()[(int) $interval['day_of_week']] ?? 'Unknown day').' '.CarbonImmutable::parse($interval['starts_at'])->format('g:i A').'–'.CarbonImmutable::parse($interval['ends_at'])->format('g:i A'))->implode('; '))),
                    'url' => FacultyQualificationResource::getUrl('index', ['termId' => $this->termId, 'filters' => ['faculty_user_id' => ['value' => $row['faculty']->id]]]),
                ];
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
            TextColumn::make('status')->label(fn (): string => $this->resourceView === 'rooms' ? 'Catalog state' : 'Recorded availability')->description(fn (array $record): ?string => $record['availability'] ?? null)->badge()->color(fn (string $state): string => $state === 'Awaiting response' ? 'warning' : 'gray')->wrap(),
            TextColumn::make('evidence')->label('Source evidence')->wrap(),
        ])->searchable()->paginationPageOptions([10, 25, 50])->defaultPaginationPageOption(10)->defaultSort('name')
            ->headerActions([
                Action::make('qualifications')->label('Faculty qualifications')->url(fn (): string => FacultyQualificationResource::getUrl('index', ['termId' => $this->termId]))->color('gray')->visible(fn (): bool => $this->resourceView === 'faculty'),
                Action::make('loads')->label('Term load exceptions')->url(fn (): string => FacultyTermLoadOverrideResource::getUrl('index', ['termId' => $this->termId]))->color('gray')->visible(fn (): bool => $this->resourceView === 'faculty'),
                Action::make('rooms')->label('Manage rooms')->url(fn (): string => RoomResource::getUrl('index', ['termId' => $this->termId]))->color('gray')->visible(fn (): bool => $this->resourceView === 'rooms'),
                Action::make('teachingLoad')->label('Faculty teaching-load default')->url(fn (): string => TermResource::getUrl('edit', ['record' => $this->termId, 'viewTab' => 'resources', 'resourceView' => $this->resourceView]))->color('gray')->visible(fn (): bool => $this->resourceView === 'faculty' && (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar)),
                Action::make('blocks')->label('Weekly unavailable times')->url(fn (): string => CalendarEventResource::getUrl('index', ['termId' => $this->termId, 'resourceView' => $this->resourceView]))->color('gray'),
            ])->recordActions([
                Action::make('source')->label('Qualifications')->visible(fn (array $record): bool => $record['kind'] === 'Faculty')->url(fn (array $record): string => $record['url']),
                Action::make('inspectRoom')->label('Room details')->visible(fn (array $record): bool => $record['kind'] === 'Rooms')
                    ->modalHeading(fn (array $record): string => $record['name'])
                    ->schema(fn (array $record, Schema $schema): Schema => RoomInfolist::configure($schema)->record(Room::query()->with('features')->findOrFail($record['room_id'])))
                    ->modalSubmitAction(false)->unsavedChangesAlert(false)->modalCancelActionLabel('Close')
                    ->extraModalFooterActions(fn (array $record): array => auth()->user()?->can('update', Room::findOrFail($record['room_id'])) ? [Action::make('editRoom')->label('Edit room')->url(RoomResource::getUrl('edit', ['record' => $record['room_id'], 'termId' => $this->termId, 'returnToResources' => true]))] : []),
            ])->emptyStateHeading('No teaching resources recorded')->emptyStateDescription('Record faculty qualifications for the offered courses and maintain the shared room catalog.')->stackedOnMobile();
    }
}
