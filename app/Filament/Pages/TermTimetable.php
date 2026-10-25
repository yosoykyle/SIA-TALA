<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Models\PublishedTimetableVersion;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\User;
use App\Support\PlanningTableRecords;
use App\Support\TimetablePresentation;
use Filament\Actions\Action;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use UnitEnum;

class TermTimetable extends TermPlanningWorkbench implements HasTable
{
    use InteractsWithTable;

    private const SearchableMeetingFields = ['courseTitle', 'courseCode', 'class', 'dayLabel', 'time', 'faculty', 'room', 'component', 'status'];

    protected static ?string $cluster = TermPlanningCluster::class;

    protected static ?string $navigationLabel = 'Timetable';

    protected static ?string $title = 'Timetable';

    protected static ?string $slug = 'timetable';

    protected static ?int $navigationSort = 4;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static bool $shouldRegisterNavigation = true;

    protected static string $planningTab = 'generate';

    protected string $view = 'filament.pages.term-timetable';

    /** @var array<string, array<string, mixed>> */
    private array $meetingDetailsViewData = [];

    #[Url]
    public string $timetableState = 'draft';

    #[Url]
    public string $timetableView = 'whole';

    #[Url]
    public string $timetableFormat = 'week';

    #[Url]
    public ?string $viewFilter = null;

    #[Url]
    public ?string $programFilter = null;

    #[Url]
    public ?string $yearFilter = null;

    /** @return array<string, array<string, mixed>> */
    protected function queryString(): array
    {
        return ['tableSearch' => ['except' => '']];
    }

    public function mount(): void
    {
        parent::mount();
        abort_unless(in_array($this->timetableState, ['draft', 'official'], true), 404);
        abort_unless(in_array($this->timetableView, ['whole', 'section', 'faculty', 'room', 'list'], true), 404);
        abort_unless(in_array($this->timetableFormat, ['week', 'list'], true), 404);
        if ($this->timetableView === 'list') {
            $this->timetableView = 'whole';
            $this->timetableFormat = 'list';
        }
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
        $this->resetTableSearch();
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
        $this->resetTableSearch();
        $this->resetTable();
    }

    public function showTimetableView(string $view): void
    {
        abort_unless(in_array($view, ['whole', 'section', 'faculty', 'room', 'list'], true), 404);
        if ($view === 'list') {
            $this->showTimetableFormat('list');

            return;
        }
        $this->timetableView = $view;
        $this->viewFilter = null;
        $this->resetTable();
    }

    public function showTimetableFormat(string $format): void
    {
        abort_unless(in_array($format, ['week', 'list'], true), 404);
        $this->timetableFormat = $format;
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

    /** @return array<string, mixed> */
    private function timetableSourceData(): array
    {
        $term = Term::query()->with('academicYear')->find($this->termId);

        if (! $term) {
            return [...parent::getViewData(), 'showOfficial' => true];
        }

        $versions = PublishedTimetableVersion::query()
            ->where('term_id', $term->id)->latest('version')->get();
        $currentVersion = $versions->firstWhere('state', PublishedTimetableVersion::StatePublished);
        $selectedVersion = $versions->firstWhere('id', $this->selectedVersionId);

        if ($this->selectedVersionId !== null && ! $selectedVersion) {
            $this->selectedVersionId = null;
        }

        $selectedVersion ??= $currentVersion ?? $versions->first();
        $activeRun = $this->activeScheduleRun();
        $official = $this->timetableState === 'official' || ! $activeRun
            || ($activeRun->status === ScheduleGenerationRun::StatusPublished && $selectedVersion);
        $relations = [
            'faculty', 'room',
            'schedulingDemand.courseComponent.courseSpecification.course',
            'schedulingDemand.sectionDeliveryGroup.section.cohorts.program',
            'schedulingDemand.sectionDeliveryGroup.section.cohorts.curriculumVersion.entries.courseSpecification',
            'schedulingDemand.termOffering.curriculumEntry.curriculumVersion.program',
            'schedulingDemand.termOffering.curriculumEntry.courseSpecification.course',
        ];
        $presentation = null;

        if ($official && $selectedVersion) {
            $selectedVersion->loadMissing('scheduleRun');
            $meetings = $selectedVersion->meetings()->with([
                ...$relations,
                'classOffering.courseRevision.course',
                'classOffering.cohorts.program',
                'classOffering.cohorts.curriculumVersion.entries.courseSpecification',
            ])->orderBy('day_of_week')->orderBy('starts_at')->get();
            $presentation = TimetablePresentation::forPublished($selectedVersion, $meetings);
        } elseif (! $official && $activeRun) {
            $meetings = $activeRun->candidateRows()->with($relations)
                ->orderBy('day_of_week')->orderBy('starts_at')->orderBy('id')->get();
            $presentation = TimetablePresentation::forCandidate($activeRun, $meetings);
        }

        $diagnostics = is_array($activeRun?->diagnostics) ? $activeRun->diagnostics : [];

        return [
            'term' => $term,
            'activeRun' => $activeRun,
            'allRuns' => ScheduleGenerationRun::query()->where('term_id', $term->id)
                ->latest('id')->get(['id', 'term_id', 'status', 'created_at']),
            'currentVersion' => $currentVersion,
            'versions' => $versions,
            'selectedVersion' => $selectedVersion,
            'showOfficial' => $official,
            'candidatePresentation' => $official ? null : $presentation,
            'publishedPresentation' => $official ? $presentation : null,
            'readOnly' => (bool) auth()->user()?->hasRole(User::StaffRoleAcademicHead),
            'solverFailure' => $official ? [] : ($activeRun?->finalSolverFailure() ?? []),
            'solverOutcome' => $official ? [] : (array) ($diagnostics['solver_outcome'] ?? $diagnostics['solver_result'] ?? []),
        ];
    }

    protected function getViewData(): array
    {
        if (count($this->mountedActions ?? []) !== 1 || ($this->mountedActions[0]['name'] ?? null) !== 'viewMeeting') {
            return $this->buildTimetableViewData();
        }

        $scope = serialize([
            $this->termId, $this->selectedRunId, $this->selectedVersionId,
            $this->timetableState, $this->timetableView, $this->viewFilter,
            $this->programFilter, $this->yearFilter, $this->getTableSearch(),
        ]);

        return $this->meetingDetailsViewData[$scope] ??= $this->buildTimetableViewData();
    }

    /** @return array<string, mixed> */
    private function buildTimetableViewData(): array
    {
        $data = $this->timetableSourceData();
        $official = $data['showOfficial'];
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
            if (($search = $this->getTableSearch()) !== null && ! collect(self::SearchableMeetingFields)->contains(fn (string $field): bool => str_contains(mb_strtolower((string) ($meeting[$field] ?? '')), mb_strtolower($search)))) {
                return false;
            }
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

    public function timetableReviewUrl(int $runId): string
    {
        return ScheduleGenerationRunResource::getUrl('view', [
            'record' => $runId, 'termId' => $this->termId,
            'timetableContext' => $this->reviewReturnContext(),
        ]);
    }

    /** @return array<string, int|string|null> */
    private function reviewReturnContext(): array
    {
        $data = $this->getViewData();

        return [
            'timetableView' => $this->timetableView, 'timetableFormat' => $this->timetableFormat,
            'viewFilter' => $this->viewFilter, 'programFilter' => $this->programFilter,
            'yearFilter' => $this->yearFilter, 'tableSearch' => $this->getTableSearch(),
            'selectedVersionId' => $data['showOfficial'] ? $data['selectedVersion']?->id : null,
        ];
    }

    public function table(Table $table): Table
    {
        return $table->stackedOnMobile()->records(fn (?string $sortColumn, ?string $sortDirection, int $page, int $recordsPerPage): LengthAwarePaginator => PlanningTableRecords::paginate(collect($this->getViewData()['timetablePresentation']['meetings'] ?? [])->keyBy('id')->all(), self::SearchableMeetingFields, null, $sortColumn, $sortDirection, $page, $recordsPerPage))
            ->columns([
                TextColumn::make('day')->label('Day & time')->formatStateUsing(fn (array $record): string => $record['dayLabel'])->description(fn (array $record): string => $record['time'])->icon('heroicon-o-clock')->weight(FontWeight::Medium)->width('11rem')->extraAttributes(['class' => 'tala-planning-meeting-when'])->sortable(),
                TextColumn::make('courseTitle')->label('Course & student sections')->description(fn (array $record): string => collect($record['sections'] ?? [])->pluck('label')->filter()->unique()->implode(' · ') ?: $record['class'])->weight(FontWeight::SemiBold)->width('38%')->extraAttributes(['class' => 'tala-planning-meeting-course'])->sortable()->wrap(),
                TextColumn::make('faculty')->label('Teacher & room')->description(fn (array $record): string => $record['room'])->width('26%')->extraAttributes(['class' => 'tala-planning-meeting-teacher'])->sortable()->wrap(),
            ])->recordActions([Action::make('viewMeeting')->modal()->label('Meeting details')->icon('heroicon-o-eye')->button()->color('gray')->modalHeading('Class meeting')->modalWidth(Width::Large)->modalSubmitAction(false)->modalCancelActionLabel('Close')->extraModalFooterActions(fn (array $record): array => $this->meetingRevisionActions($record))->modalContent(fn (array $record): View => view('filament.components.faculty-meeting-details', ['meeting' => $record, 'showFaculty' => true]))])->paginationPageOptions([10, 25, 50])->defaultPaginationPageOption(10)->defaultSort('day')->emptyStateHeading('No meetings in this view')->emptyStateDescription('Choose a timetable version or clear the view filters.');
    }

    /** @return array<string, mixed>|null */
    protected function resolveTableRecord(?string $key): ?array
    {
        if ($key === null) {
            return null;
        }

        $meeting = collect($this->getViewData()['timetablePresentation']['meetings'] ?? [])->first(
            fn (array $meeting): bool => (string) $meeting['id'] === $key,
        );

        return $meeting === null ? null : [...$meeting, '__key' => $key];
    }

    /** @param array<string, mixed> $record
     * @return list<Action>
     */
    private function meetingRevisionActions(array $record): array
    {
        $data = $this->getViewData();
        if (! $data['showOfficial']) {
            return $this->canReviewActiveRun() ? [
                Action::make('reviewCandidateAssignments')->label('Review or change assignments')->icon('heroicon-o-pencil-square')
                    ->url($this->timetableReviewUrl($data['activeRun']->id).'#timetable-run-meetings'),
            ] : [];
        }

        if (! Gate::allows('revise', SectionMeeting::class)) {
            return [];
        }

        $version = $data['selectedVersion'] ?? null;
        if (! $data['showOfficial'] || ! $version instanceof PublishedTimetableVersion
            || $version->state !== PublishedTimetableVersion::StatePublished
            || ! $version->scheduleRun?->isPublished()) {
            return [];
        }

        $published = $version->meetings()->find($record['id']);
        if ($published === null) {
            return [];
        }
        $meeting = SectionMeeting::query()->where('schedule_run_id', $version->schedule_run_id)
            ->where('scheduling_demand_id', $published->scheduling_demand_id)
            ->where('meeting_sequence', $published->meeting_sequence)
            ->where('state', SectionMeeting::StateActive)->first();
        if ($meeting === null) {
            return [];
        }

        $target = ['record' => $version->schedule_run_id, 'termId' => $version->term_id, 'timetableContext' => $this->reviewReturnContext(),
            'actionArguments' => ['meeting' => $meeting->id]];

        return [
            Action::make('changeThisMeeting')->label('Change this meeting')->icon('heroicon-o-pencil-square')
                ->url(ScheduleGenerationRunResource::getUrl('view', [...$target, 'action' => 'revisePublishedSchedule'])),
            Action::make('cancelThisClass')->label('Cancel this class')->icon('heroicon-o-x-circle')->color('danger')
                ->url(ScheduleGenerationRunResource::getUrl('view', [...$target, 'action' => 'cancelPublishedClass'])),
        ];
    }
}
