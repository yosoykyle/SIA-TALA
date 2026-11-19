<?php

namespace App\Filament\Resources\FeePlans\Pages;

use App\Actions\Finance\AccountingDisplay;
use App\Actions\Finance\CreateFeePlanDraft;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\FeePlans\FeePlanResource;
use App\Filament\Resources\FeePlans\Schemas\FeePlanForm;
use App\Models\FeePlan;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

class ListFeePlans extends ListRecords
{
    protected static string $resource = FeePlanResource::class;

    #[Url(as: 'scope')]
    public ?string $scope = null;

    protected bool $feePlanWorkspaceWasOpen = false;

    private ?bool $multipleTermsCache = null;

    private bool $activeTermResolved = false;

    private ?Term $activeTermCache = null;

    private ?int $draftFeePlanCountCache = null;

    private ?int $outsideDraftFeePlanCountCache = null;

    private function flushFeePlanScopeCache(): void
    {
        $this->draftFeePlanCountCache = null;
        $this->outsideDraftFeePlanCountCache = null;
    }

    public function updatingTableFilters(): void
    {
        $this->feePlanWorkspaceWasOpen = ! $this->feePlanGateActive();
    }

    public function updatedTableFilters(): void
    {
        parent::updatedTableFilters();

        if (filled(data_get($this->tableFilters, 'term.value'))) {
            $this->scope = null;
        } elseif ($this->feePlanWorkspaceWasOpen && $this->hasMultipleTerms() && ! $this->hasFeePlanScopeOrSearch()) {
            $this->scope = 'all';
        }

        $this->flushFeePlanScopeCache();
        $this->flushCachedTableRecords();
    }

    public function updatedTableSearch(): void
    {
        parent::updatedTableSearch();

        $this->flushFeePlanScopeCache();
        $this->flushCachedTableRecords();
    }

    public function getTitle(): string
    {
        return 'Fee plans';
    }

    public function getSubheading(): ?string
    {
        return 'Create and publish approved charges and payment schedules for each program and term. Published plans supply standard student assessments.';
    }

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [
            EnrollmentResource::getUrl('index') => 'Overview',
            'Fee plans',
        ];
    }

    public function hasMultipleTerms(): bool
    {
        return $this->multipleTermsCache ??= Term::query()->count() > 1;
    }

    public function hasActiveFilterValue(string $name): bool
    {
        $state = $this->tableFilters[$name] ?? null;

        if (! is_array($state)) {
            return filled($state);
        }

        return collect(Arr::dot($state))->contains(fn (mixed $value): bool => filled($value));
    }

    public function hasFeePlanScopeOrSearch(): bool
    {
        if ($this->scope === 'all') {
            return true;
        }

        if ($this->hasTableSearch()) {
            return true;
        }

        return $this->hasActiveFilterValue('term')
            || $this->hasActiveFilterValue('program')
            || $this->hasActiveFilterValue('state');
    }

    public function feePlanGateActive(): bool
    {
        return $this->hasMultipleTerms() && ! $this->hasFeePlanScopeOrSearch();
    }

    public function activeFeePlanTerm(): ?Term
    {
        if (! $this->activeTermResolved) {
            $this->activeTermResolved = true;
            $this->activeTermCache = Term::query()
                ->where('state', Term::StateActive)
                ->latest('starts_on')
                ->first();
        }

        return $this->activeTermCache;
    }

    public function draftFeePlanCount(): int
    {
        return $this->draftFeePlanCountCache ??= FeePlan::query()
            ->where('state', FeePlan::StateDraft)
            ->count();
    }

    public function outsideDraftFeePlanCount(): int
    {
        if ($this->outsideDraftFeePlanCountCache !== null) {
            return $this->outsideDraftFeePlanCountCache;
        }

        $totalDrafts = $this->draftFeePlanCount();

        if ($totalDrafts === 0) {
            return $this->outsideDraftFeePlanCountCache = 0;
        }

        $termId = filled($this->tableFilters['term']['value'] ?? null) ? (int) $this->tableFilters['term']['value'] : null;
        $programId = filled($this->tableFilters['program']['value'] ?? null) ? (int) $this->tableFilters['program']['value'] : null;
        $state = filled($this->tableFilters['state']['value'] ?? null) ? (string) $this->tableFilters['state']['value'] : null;

        if ($state !== null && $state !== FeePlan::StateDraft) {
            return $this->outsideDraftFeePlanCountCache = $totalDrafts;
        }

        if ($termId === null && $programId === null) {
            return $this->outsideDraftFeePlanCountCache = 0;
        }

        $inScopeDrafts = FeePlan::query()
            ->where('state', FeePlan::StateDraft)
            ->when($termId !== null, fn ($query) => $query->where('term_id', $termId))
            ->when($programId !== null, fn ($query) => $query->where('program_id', $programId))
            ->count();

        return $this->outsideDraftFeePlanCountCache = max(0, $totalDrafts - $inScopeDrafts);
    }

    /**
     * @return array{title: ?string, meta: ?string, icon: string}
     */
    public function feePlanScopeDetails(): array
    {
        $termId = filled($this->tableFilters['term']['value'] ?? null) ? (int) $this->tableFilters['term']['value'] : null;
        $programId = filled($this->tableFilters['program']['value'] ?? null) ? (int) $this->tableFilters['program']['value'] : null;
        $state = filled($this->tableFilters['state']['value'] ?? null) ? (string) $this->tableFilters['state']['value'] : null;

        $term = $termId !== null ? Term::query()->with('academicYear')->find($termId) : null;
        $programLabel = $programId !== null ? Program::query()->whereKey($programId)->value('name') : null;
        $stateLabel = $state !== null ? AccountingDisplay::feePlanStatusPluralLabel($state) : null;

        if ($term !== null) {
            $metaParts = array_values(array_filter([
                $term->academicYear?->label,
                $programLabel,
                $stateLabel,
            ], filled(...)));

            return [
                'title' => $term->label,
                'meta' => $metaParts !== [] ? implode(' · ', $metaParts) : null,
                'icon' => 'heroicon-o-calendar-days',
            ];
        }

        $metaParts = array_values(array_filter([$programLabel, $stateLabel], filled(...)));

        return [
            'title' => $this->hasMultipleTerms() ? 'All terms' : 'All fee plans',
            'meta' => $metaParts !== [] ? implode(' · ', $metaParts) : null,
            'icon' => 'heroicon-o-funnel',
        ];
    }

    public function selectFeePlanTerm(int $termId): void
    {
        if (! Term::query()->whereKey($termId)->exists()) {
            return;
        }

        $this->scope = null;
        $filters = $this->tableFilters ?? [];
        data_set($filters, 'term.value', (string) $termId);
        $this->getTableFiltersForm()->fill($filters);
        $this->handleTableFilterUpdates();
        $this->flushFeePlanScopeCache();
        $this->flushCachedTableRecords();
    }

    public function showAllTerms(): void
    {
        $this->scope = 'all';
        $this->tableFilters = [
            'term' => ['value' => null],
            'program' => ['value' => filled($this->tableFilters['program']['value'] ?? null) ? (string) $this->tableFilters['program']['value'] : null],
            'state' => ['value' => filled($this->tableFilters['state']['value'] ?? null) ? (string) $this->tableFilters['state']['value'] : null],
        ];
        $this->getTableFiltersForm()->fill($this->tableFilters);
        $this->handleTableFilterUpdates();
        $this->flushFeePlanScopeCache();
        $this->flushCachedTableRecords();
    }

    public function showDraftFeePlans(): void
    {
        $this->scope = 'all';
        $this->tableSearch = '';
        $this->tableFilters = [
            'term' => ['value' => null],
            'program' => ['value' => null],
            'state' => ['value' => FeePlan::StateDraft],
        ];
        $this->getTableFiltersForm()->fill($this->tableFilters);
        $this->handleTableFilterUpdates();
        $this->flushFeePlanScopeCache();
        $this->flushCachedTableRecords();
    }

    public function changeFeePlanTerm(): void
    {
        $this->scope = null;
        $this->resetTableFiltersForm();
        $this->resetTableSearch();
        $this->resetPage();
        $this->flushFeePlanScopeCache();
        $this->flushCachedTableRecords();
    }

    public function clearFeePlanFilters(): void
    {
        $this->scope = $this->hasMultipleTerms() ? 'all' : null;
        $this->resetTableFiltersForm();
        $this->resetTableSearch();
        $this->resetPage();
        $this->flushFeePlanScopeCache();
        $this->flushCachedTableRecords();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            SchemaView::make('filament.admin.fee-plans.styles'),
            $this->getTabsContentComponent(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
            SchemaView::make('filament.admin.fee-plans.term-gate')
                ->key('fee-plans-term-gate')
                ->visible(fn (): bool => $this->feePlanGateActive()),
            SchemaView::make('filament.admin.fee-plans.collection-controls')
                ->key('fee-plans-scope-bar')
                ->visible(fn (): bool => ! $this->feePlanGateActive() && $this->outsideDraftFeePlanCount() > 0),
            EmbeddedTable::make()
                ->key('fee-plans-embedded-table')
                ->visible(fn (): bool => ! $this->feePlanGateActive()),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('showAllTerms')
                ->label('All terms')
                ->color('gray')
                ->visible(fn (): bool => ! $this->feePlanGateActive() && $this->hasActiveFilterValue('term'))
                ->action(fn () => $this->showAllTerms()),
            Action::make('changeTerm')
                ->label('Change term')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->visible(fn (): bool => ! $this->feePlanGateActive() && $this->hasMultipleTerms())
                ->action(fn () => $this->changeFeePlanTerm()),
            Action::make('createDraft')
                ->label('New draft')
                ->icon(Heroicon::Plus)
                ->modalHeading('New fee plan draft')->modalDescription('Set the charges and when they must be paid. Approval is recorded before publication.')
                ->modalWidth(Width::FiveExtraLarge)->stickyModalHeader()->stickyModalFooter()->modalFooterActionsAlignment(Alignment::End)->modalSubmitActionLabel('Save draft')
                ->schema(FeePlanForm::components(includeScope: true))
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    $rows = FeePlanForm::rows($data);
                    try {
                        $plan = app(CreateFeePlanDraft::class)->execute(
                            Program::query()->findOrFail($data['program_id']),
                            Term::query()->findOrFail($data['term_id']),
                            $rows['charges'],
                            $actor,
                            $rows['obligations'],
                        );
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Draft was not saved')->body(collect($exception->errors())->flatten()->first())->danger()->send();
                        throw $exception;
                    }
                    $this->redirect(FeePlanResource::getUrl('view', ['record' => $plan]));
                }),
        ];
    }
}
