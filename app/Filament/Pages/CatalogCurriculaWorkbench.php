<?php

namespace App\Filament\Pages;

use App\Actions\AcademicSetup\AcademicReadinessService;
use App\Filament\Concerns\HasAcademicOversightReturn;
use App\Filament\Resources\Courses\CourseResource;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\Programs\ProgramResource;
use App\Models\Course;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use UnitEnum;

final class CatalogCurriculaWorkbench extends Page implements HasTable
{
    use HasAcademicOversightReturn;
    use InteractsWithTable;

    #[Url]
    public ?int $programId = null;

    #[Url(as: 'tab')]
    public string $activeTab = 'draft';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Academic Planning';

    protected static ?string $navigationLabel = 'Programs & Curricula';

    protected static ?string $title = 'Programs & Curricula';

    protected string $view = 'filament.pages.catalog-curricula-workbench';

    public function mount(): void
    {
        if ($this->programId !== null) {
            $this->redirect(ProgramResource::getUrl('view', ['record' => $this->programId, ...$this->oversightQueryParameters()]));

            return;
        }

        if ($this->isReadOnly() && ! \Filament\Support\original_request()->query->has('tab')) {
            $this->activeTab = 'active';
        }

        $this->activeTab = $this->currentTab();
    }

    public function switchTab(string $tab): void
    {
        if (in_array($tab, ['draft', 'active'], true)) {
            $this->activeTab = $tab;
            $this->resetTable();
        }
    }

    public function getSubheading(): ?string
    {
        if ($this->isReadOnly()) {
            return 'Read-only oversight of active and draft program study plans maintained by the Registrar.';
        }

        return 'Prepare each program’s study plan and activate it for incoming students.';
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->returnToOversightAction('Academic Oversight')
                ->visible(fn (): bool => $this->isReadOnly()),
        ];
    }

    /**
     * @return array<string|int, string>
     */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function createProgramAction(): CreateAction
    {
        return ProgramResource::createProgramAction('createProgram')
            ->visible(fn (): bool => ! $this->isReadOnly())
            ->authorize(fn (): bool => ! $this->isReadOnly() && (auth()->user()?->can('create', Program::class) ?? false));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => CurriculumVersion::query()
                ->with(['program', 'effectiveEntryTerm.academicYear', 'entries.courseSpecification.components'])
                ->withCount('entries')
                ->where('state', $this->currentTab() === 'active'
                    ? CurriculumVersion::StateActive
                    : CurriculumVersion::StateDraft))
            ->searchPlaceholder('Search curricula or programs')
            ->columns([
                TextColumn::make('program.code')
                    ->label('Program')
                    ->description(fn (CurriculumVersion $record): string => (string) ($record->program?->name ?? ''))
                    ->searchable(['program.code', 'program.name'])
                    ->sortable()
                    ->wrap(),
                TextColumn::make('name')
                    ->label('Curriculum')
                    ->weight('bold')
                    ->searchable(['name', 'version_code'])
                    ->description(fn (CurriculumVersion $record): string => $record->entries_count.' '.Str::plural('subject', (int) $record->entries_count))
                    ->wrap(),
                TextColumn::make('readiness')
                    ->label('Status')
                    ->badge()
                    ->state(fn (CurriculumVersion $record): string => $this->curriculumReadinessBadge($record)['label'])
                    ->color(fn (CurriculumVersion $record): string => $this->curriculumReadinessBadge($record)['color']),
                TextColumn::make('effectiveEntryTerm.label')
                    ->label('First student intake')
                    ->formatStateUsing(function (?string $state, CurriculumVersion $record): ?HtmlString {
                        $termLabel = trim((string) $state);
                        $ayLabel = trim((string) ($record->effectiveEntryTerm?->academicYear?->label ?? ''));

                        if ($termLabel === '' && $ayLabel === '') {
                            return null;
                        }

                        if ($termLabel !== '' && $ayLabel !== '') {
                            return new HtmlString(e($termLabel).', <span class="whitespace-nowrap">'.e($ayLabel).'</span>');
                        }

                        return new HtmlString(e($termLabel !== '' ? $termLabel : $ayLabel));
                    })
                    ->wrap()
                    ->placeholder('Not set'),
            ])
            ->header(fn (): HtmlString => $this->renderDockedCurriculaTabs())
            ->filters([
                SelectFilter::make('program_id')
                    ->label('Program')
                    ->placeholder('All programs')
                    ->relationship('program', 'code'),
            ])
            ->filtersLayout(FiltersLayout::Dropdown)
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filters')->color('gray'))
            ->deferFilters(false)
            ->extraAttributes(['class' => 'tala-data-table tala-curricula-workbench-table'])
            ->stackedOnMobile()
            ->recordUrl(fn (CurriculumVersion $record): string => $this->reviewUrl($record))
            ->recordActions([
                Action::make('openSheet')
                    ->button()
                    ->color('gray')
                    ->label('Open')
                    ->icon('heroicon-m-arrow-right')
                    ->iconPosition('after')
                    ->url(fn (CurriculumVersion $record): string => $this->reviewUrl($record)),
            ])
            ->emptyState(view('filament.partials.collection-empty-state'))
            ->emptyStateHeading(fn (HasTable $livewire): string => filled($livewire->getTableSearch()) || $livewire->getTable()->isFiltered()
                ? 'No matching curricula'
                : ($this->currentTab() === 'active' ? 'No active curricula yet' : 'No draft curricula in progress'))
            ->emptyStateDescription(fn (HasTable $livewire): string => filled($livewire->getTableSearch()) || $livewire->getTable()->isFiltered()
                ? 'Try a different program filter or search term.'
                : ($this->isReadOnly()
                    ? ($this->currentTab() === 'active'
                        ? 'Active study plans appear here once the Registrar activates a draft curriculum.'
                        : 'No draft curricula are currently in progress with the Registrar.')
                    : ($this->currentTab() === 'active'
                        ? 'Activate a completed draft study plan to assign it to incoming students.'
                        : 'Every study plan is up to date, or create a new draft curriculum for a program.')))
            ->emptyStateActions([
                CurriculumVersionResource::createDraftAction(name: 'emptyCreateCurriculum')
                    ->visible(fn (HasTable $livewire): bool => ! $this->isReadOnly()
                        && $this->currentTab() === 'draft'
                        && ! filled($livewire->getTableSearch())
                        && ! $livewire->getTable()->isFiltered()
                        && (auth()->user()?->can('create', CurriculumVersion::class) ?? false))
                    ->authorize(fn (): bool => ! $this->isReadOnly()
                        && (auth()->user()?->can('create', CurriculumVersion::class) ?? false)),
            ])
            ->toolbarActions([])
            ->defaultSort('updated_at', 'desc');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $query = $this->oversightQueryParameters();
        $readOnly = $this->isReadOnly();
        $hasPrograms = Program::query()->exists();
        $activeCurriculaCount = CurriculumVersion::query()->where('state', CurriculumVersion::StateActive)->count();
        $draftCurriculaCount = CurriculumVersion::query()->where('state', CurriculumVersion::StateDraft)->count();

        return [
            'readOnly' => $readOnly,
            'hasPrograms' => $hasPrograms,
            'activeTab' => $this->currentTab(),
            'activeCurriculaCount' => $activeCurriculaCount,
            'draftCurriculaCount' => $draftCurriculaCount,
            'coverageCards' => $this->coverageCards($query, $activeCurriculaCount, $draftCurriculaCount),
            'programsUrl' => ProgramResource::getUrl('index', $query),
            'curriculaUrl' => CurriculumVersionResource::getUrl('index', $query),
            'subjectsUrl' => CourseResource::getUrl('index', $query),
        ];
    }

    /**
     * Build the top 3 `.tala-workspace-feature` Catalog Coverage cards.
     *
     * @param  array<string, int>  $query
     * @return list<array{
     *     key: string,
     *     label: string,
     *     count: int,
     *     figure_label: string,
     *     summary: string,
     *     icon: string,
     *     url: string,
     *     action_label: string,
     *     featured: bool
     * }>
     */
    private function coverageCards(array $query, int $activeCurriculaCount, int $draftCurriculaCount): array
    {
        $activeProgramCount = Program::query()->where('is_active', true)->count();
        $totalProgramCount = Program::query()->count();
        $programsWithActiveCount = Program::query()
            ->where('is_active', true)
            ->whereHas('curriculumVersions', fn ($builder) => $builder->where('state', CurriculumVersion::StateActive))
            ->count();

        $totalCurriculaCount = $activeCurriculaCount + $draftCurriculaCount + CurriculumVersion::query()->where('state', CurriculumVersion::StateSuperseded)->count();

        $courseCount = Course::query()->count();
        $draftSubjectCount = CourseSpecification::query()->where('state', CourseSpecification::StateDraft)->count();

        $programSummary = match (true) {
            $totalProgramCount === 0 => 'Add degrees and diplomas before building study plans.',
            $activeProgramCount === 0 => 'No active programs yet.',
            $programsWithActiveCount < $activeProgramCount => ($activeProgramCount - $programsWithActiveCount).' active '.Str::plural('program', $activeProgramCount - $programsWithActiveCount).' missing an active study plan.',
            default => 'All '.$activeProgramCount.' active '.Str::plural('program', $activeProgramCount).' covered by an active plan.',
        };

        $curriculaSummary = $totalCurriculaCount === 0
            ? 'Study plans by program, including active and past study plans.'
            : $activeCurriculaCount.' active · '.$draftCurriculaCount.' in draft across programs.';

        $subjectSummary = $draftSubjectCount > 0
            ? $draftSubjectCount.' draft '.Str::plural('update', $draftSubjectCount).' pending teaching details.'
            : ($courseCount > 0 ? 'Shared subjects, units, and weekly class meetings.' : 'No subjects recorded yet.');

        $programCountValue = $activeProgramCount > 0 ? $activeProgramCount : $totalProgramCount;

        return [
            [
                'key' => 'programs',
                'label' => 'Programs',
                'count' => $programCountValue,
                'figure_label' => $activeProgramCount > 0
                    ? 'active '.Str::plural('program', $programCountValue)
                    : Str::plural('program', $programCountValue),
                'summary' => $programSummary,
                'icon' => 'heroicon-o-academic-cap',
                'url' => ProgramResource::getUrl('index', $query),
                'action_label' => 'Open programs',
                'featured' => $activeProgramCount > 0 && $programsWithActiveCount < $activeProgramCount,
            ],
            [
                'key' => 'subjects',
                'label' => 'Subjects',
                'count' => $courseCount,
                'figure_label' => Str::plural('subject', $courseCount),
                'summary' => $subjectSummary,
                'icon' => 'heroicon-o-book-open',
                'url' => CourseResource::getUrl('index', $query),
                'action_label' => 'Open subjects',
                'featured' => $draftSubjectCount > 0,
            ],
            [
                'key' => 'curricula',
                'label' => 'Curricula',
                'count' => $totalCurriculaCount,
                'figure_label' => Str::plural('study plan', $totalCurriculaCount),
                'summary' => $curriculaSummary,
                'icon' => 'heroicon-o-clipboard-document-list',
                'url' => CurriculumVersionResource::getUrl('index', $query),
                'action_label' => 'Open curricula',
                'featured' => $draftCurriculaCount > 0,
            ],
        ];
    }

    /**
     * @return array{label: string, color: string}
     */
    private function curriculumReadinessBadge(CurriculumVersion $record): array
    {
        if ($record->state === CurriculumVersion::StateActive) {
            return ['label' => 'Active', 'color' => 'success'];
        }

        $entryCount = (int) ($record->entries_count ?? $record->entries->count());

        if ($entryCount === 0) {
            return ['label' => 'Not started', 'color' => 'gray'];
        }

        $readiness = app(AcademicReadinessService::class);
        $hasIncomplete = $record->entries
            ->contains(fn (CurriculumEntry $entry): bool => $readiness->entryReadiness($entry)['status'] !== 'Specification ready');

        if ($hasIncomplete) {
            return ['label' => 'Needs teaching details', 'color' => 'warning'];
        }

        return ['label' => 'Ready to activate', 'color' => 'success'];
    }

    private function reviewUrl(CurriculumVersion $record): string
    {
        return CurriculumVersionResource::getUrl('review', array_filter([
            'record' => $record,
            'programId' => $record->program_id,
            'oversightTermId' => $this->oversightTermId,
        ], fn (mixed $value): bool => $value !== null));
    }

    private function renderDockedCurriculaTabs(): HtmlString
    {
        $activeTab = $this->currentTab();
        $draftCount = CurriculumVersion::query()->where('state', CurriculumVersion::StateDraft)->count();
        $activeCount = CurriculumVersion::query()->where('state', CurriculumVersion::StateActive)->count();

        return new HtmlString(Blade::render(<<<'BLADE'
            <x-tala-navigation
                label="Curricula view"
                compact
                role="tablist"
                class="tala-curricula-tabs-dock"
            >
                <x-filament::tabs.item
                    role="tab"
                    :active="$activeTab === 'draft'"
                    :aria-selected="$activeTab === 'draft' ? 'true' : 'false'"
                    :badge="$draftCount"
                    :badge-color="$activeTab === 'draft' ? 'primary' : 'gray'"
                    wire:click="switchTab('draft')"
                >
                    Draft curricula
                </x-filament::tabs.item>
                <x-filament::tabs.item
                    role="tab"
                    :active="$activeTab === 'active'"
                    :aria-selected="$activeTab === 'active' ? 'true' : 'false'"
                    :badge="$activeCount"
                    :badge-color="$activeTab === 'active' ? 'primary' : 'gray'"
                    wire:click="switchTab('active')"
                >
                    Active curricula
                </x-filament::tabs.item>
            </x-tala-navigation>
        BLADE, [
            'activeTab' => $activeTab,
            'draftCount' => $draftCount,
            'activeCount' => $activeCount,
        ]));
    }

    private function currentTab(): string
    {
        return in_array($this->activeTab, ['draft', 'active'], true)
            ? $this->activeTab
            : ($this->isReadOnly() ? 'active' : 'draft');
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
