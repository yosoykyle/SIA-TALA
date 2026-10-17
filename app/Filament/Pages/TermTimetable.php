<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Support\PlanningTableRecords;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use UnitEnum;

class TermTimetable extends TermPlanningWorkbench implements HasTable
{
    use InteractsWithTable;

    protected static ?string $cluster = TermPlanningCluster::class;

    protected static ?string $navigationLabel = 'Timetable';

    protected static ?string $title = 'Timetable';

    protected static ?string $slug = 'timetable';

    protected static ?int $navigationSort = 4;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static bool $shouldRegisterNavigation = true;

    protected static string $planningTab = 'generate';

    protected string $view = 'filament.pages.term-timetable';

    #[Url]
    public string $timetableState = 'draft';

    #[Url]
    public string $timetableView = 'whole';

    #[Url]
    public ?string $viewFilter = null;

    #[Url]
    public ?string $programFilter = null;

    #[Url]
    public ?string $yearFilter = null;

    public function mount(): void
    {
        parent::mount();
        abort_unless(in_array($this->timetableState, ['draft', 'official'], true), 404);
        if ($this->selectedVersionId !== null) {
            $this->timetableState = 'official';
        } elseif ($this->selectedRunId !== null) {
            $this->timetableState = 'draft';
        }
    }

    public function clearViewFilters(): void
    {
        $this->programFilter = null;
        $this->yearFilter = null;
        $this->viewFilter = null;
        $this->resetTable();
    }

    public function expandTimetableAction(): Action
    {
        return Action::make('expandTimetable')
            ->label('Expand timetable')
            ->color('gray')
            ->modalHeading('Whole-Term timetable')
            ->modalWidth(Width::Screen)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(function (): View {
                $data = $this->getViewData();

                return view('filament.pages.partials.term-expanded-timetable', [
                    'presentation' => $data['timetablePresentation'],
                    'groups' => $data['masterGroups'],
                ]);
            });
    }

    public function chooseTimetable(string $selection): void
    {
        [$state, $id] = array_pad(explode(':', $selection, 2), 2, null);
        if ($state === 'official') {
            $this->selectPublishedVersion((int) $id);
            $this->timetableState = 'official';
        } elseif ($state === 'draft') {
            $this->selectRun((int) $id);
            $this->timetableState = 'draft';
        }
        $this->viewFilter = null;
        $this->programFilter = null;
        $this->yearFilter = null;
        $this->resetTable();
    }

    public function showTimetableView(string $view): void
    {
        abort_unless(in_array($view, ['whole', 'section', 'faculty', 'room', 'list'], true), 404);
        $this->timetableView = $view;
        $this->viewFilter = null;
        $this->resetTable();
    }

    public function updatedProgramFilter(): void
    {
        $this->resetPage();
    }

    public function updatedYearFilter(): void
    {
        $this->resetPage();
    }

    public function updatedViewFilter(): void
    {
        $this->resetPage();
    }

    protected function getViewData(): array
    {
        $data = parent::getViewData();
        $official = $this->timetableState === 'official' || ! $data['activeRun'] || ($data['activeRun']->status === 'published' && $data['selectedVersion']);
        $presentation = $official ? $data['publishedPresentation'] : $data['candidatePresentation'];
        $allMeetings = $presentation['meetings'] ?? [];
        $courseColors = collect($allMeetings)->pluck('courseCode')->unique()->sort()->values()
            ->mapWithKeys(fn (string $code, int $index): array => [$code => ($index % 4) + 1])->all();
        $groups = [];
        foreach ($allMeetings as $meeting) {
            foreach ($meeting['sections'] ?? [] as $section) {
                $key = $section['id'];
                $groups[$key] ??= [...$section, 'meetings' => []];
                $groups[$key]['meetings'][] = $meeting;
                $groups[$key]['years'] = array_merge($groups[$key]['years'] ?? [], $section['years'] ?? []);
            }
        }
        $years = collect($groups)->flatMap(fn (array $group): array => $group['years'])->unique()->sort()->values()->all();
        $programs = collect($groups)->pluck('program')->unique()->sort()->values()->all();
        $groups = collect($groups)->sortBy(fn (array $group): string => $group['program'].' '.$group['label'])
            ->filter(fn (array $group): bool => (! $this->programFilter || $group['program'] === $this->programFilter) && (! $this->yearFilter || in_array($this->yearFilter, $group['years'], true)))->values()->all();
        $options = match ($this->timetableView) {
            'section' => collect($groups)->mapWithKeys(fn (array $group): array => [$group['id'] => $group['label']])->all(),
            'faculty', 'room' => collect($allMeetings)->pluck($this->timetableView)->unique()->sort()->mapWithKeys(fn (string $value): array => [$value => $value])->all(),
            default => [],
        };
        $filtered = collect($allMeetings)->filter(function (array $meeting): bool {
            if (($this->programFilter || $this->yearFilter) && ! collect($meeting['sections'])->contains(fn (array $section): bool => (! $this->programFilter || $section['program'] === $this->programFilter)
                && (! $this->yearFilter || in_array($this->yearFilter, $section['years'] ?? [], true)))) {
                return false;
            }
            if (! $this->viewFilter) {
                return true;
            }

            return $this->timetableView === 'section'
                ? collect($meeting['sections'])->contains('id', $this->viewFilter)
                : ($meeting[$this->timetableView] ?? null) === $this->viewFilter;
        })->values()->all();
        $visibleIds = array_column($filtered, 'id');
        $groups = collect($groups)->map(function (array $group) use ($visibleIds): array {
            $group['meetings'] = array_values(array_filter($group['meetings'], fn (array $meeting): bool => in_array($meeting['id'], $visibleIds, true)));

            return $group;
        })->filter(fn (array $group): bool => $group['meetings'] !== [])->values()->all();
        if ($presentation) {
            $presentation['meetings'] = $filtered;
            $presentation['courseColors'] = $courseColors;
        }

        return [...$data, 'showOfficial' => $official, 'timetablePresentation' => $presentation,
            'masterGroups' => $groups, 'programOptions' => $programs, 'yearOptions' => $years, 'viewOptions' => $options,
            'scopeMeetingCount' => count($allMeetings)];
    }

    public function table(Table $table): Table
    {
        return $table->records(fn (?string $search, ?string $sortColumn, ?string $sortDirection, int $page, int $recordsPerPage): LengthAwarePaginator => PlanningTableRecords::paginate(collect($this->getViewData()['timetablePresentation']['meetings'] ?? [])->keyBy('id')->all(), ['courseTitle', 'courseCode', 'class', 'dayLabel', 'time', 'faculty', 'room', 'component', 'status'], $search, $sortColumn, $sortDirection, $page, $recordsPerPage))
            ->columns([
                TextColumn::make('courseTitle')->label('Course')->description(fn (array $record): string => $record['courseCode'].' · '.$record['class'])->sortable()->wrap(),
                TextColumn::make('day')->label('Day')->formatStateUsing(fn (array $record): string => $record['dayLabel'])->sortable(),
                TextColumn::make('start')->label('Time')->formatStateUsing(fn (array $record): string => $record['time'])->sortable(),
                TextColumn::make('faculty')->label('Teacher')->sortable()->wrap(),
                TextColumn::make('room')->label('Location')->sortable()->wrap(),
                TextColumn::make('component')->label('Meeting'),
                TextColumn::make('status')->label('Evidence')->wrap(),
            ])->recordActions([Action::make('viewMeeting')->label('Meeting details')->modalHeading('Class meeting')->modalWidth(Width::Large)->modalSubmitAction(false)->modalCancelActionLabel('Close')->modalContent(fn (array $record): View => view('filament.components.faculty-meeting-details', ['meeting' => $record, 'showFaculty' => true]))])->searchable()->paginationPageOptions([10, 25, 50])->defaultPaginationPageOption(10)->defaultSort('day')->emptyStateHeading('No meetings in this view')->emptyStateDescription('Choose a timetable version or clear the view filters.');
    }
}
