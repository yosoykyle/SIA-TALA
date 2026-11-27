@php
    $records = $this->getTableRecords();
    $hasSearch = filled(trim((string) $this->getTableSearch()));
    $stateValue = $this->getTableFilterState('state')['value'] ?? null;
    $termId = $this->getTableFilterState('term')['value'] ?? null;
    $hasFilters = filled($stateValue) || filled($termId);
    $hasCriteria = $hasSearch || $hasFilters;
    $activeFilterCount = (int) filled($stateValue) + (int) filled($termId);
    $statusLabel = filled($stateValue)
        ? ($this->getTable()->getFilter('state')?->getOptions()[$stateValue] ?? 'Selected status')
        : 'All statuses';
    $termLabel = filled($termId)
        ? ($this->getTable()->getFilter('term')?->getOptions()[$termId] ?? 'Selected term')
        : 'All terms';
    $showToolbar = $records->isNotEmpty() || $hasCriteria;
@endphp
<section class="tala-class-worklist" aria-label="Class grades">
    <div class="tala-class-card-shell tala-task-surface">
        @if ($showToolbar)
            <div class="tala-inc-toolbar" aria-label="Find class grades">
                <div class="tala-record-query">
                    <x-filament-tables::search-field label="Find class grades" placeholder="Course, class or faculty" debounce="350ms" />
                    <x-filament::dropdown :menu="false" placement="bottom-end" width="sm" shift>
                        <x-slot:trigger>
                            <x-filament::button color="gray" icon="heroicon-m-funnel" :badge="$activeFilterCount > 0 ? $activeFilterCount : null">Filters</x-filament::button>
                        </x-slot:trigger>
                        <x-filament-tables::filters
                            :form="$this->getTableFiltersForm()"
                            :apply-action="$this->getTable()->getFiltersApplyAction()"
                            :reset-action="$this->getTable()->getFiltersResetAction()"
                        />
                    </x-filament::dropdown>
                    <div class="tala-record-query-meta">
                        <p class="tala-record-query-summary" aria-live="polite">{{ $statusLabel }} · {{ $termLabel }}</p>
                        @if ($records->isNotEmpty() && $hasCriteria)
                            <x-filament::link tag="button" type="button" size="sm" wire:click="clearClassQueue" wire:loading.attr="disabled">Clear search and filters</x-filament::link>
                        @endif
                    </div>
                </div>
            </div>
        @endif
        <div class="tala-content-region" wire:loading.attr="aria-busy" wire:target="tableSearch,tableFilters,tableRecordsPerPage,gotoPage,previousPage,nextPage,clearClassQueue,resetTableSearch,resetTableFiltersForm">
            <x-content-progress label="Loading class grades" wire:loading.delay.short.block wire:target="tableSearch,tableFilters,tableRecordsPerPage,gotoPage,previousPage,nextPage,clearClassQueue,resetTableSearch,resetTableFiltersForm" />
            @if ($records->isNotEmpty())
                <div class="tala-class-worklist-body">
                    <ul class="tala-class-grid" aria-label="Class grades">
                        @foreach ($records as $roster)
                            <li wire:key="{{ $this->getId() }}.table.records.{{ $roster->getKey() }}">
                                <x-class-roster-card :roster="$roster" :href="$this->getTable()->getRecordUrl($roster)" :show-term="blank($termId)" />
                            </li>
                        @endforeach
                    </ul>
                </div>
                @if ($records->total() > 0)
                    <x-record-collection-footer :paginator="$records" label="classes" :page-options="$this->getTable()->getPaginationPageOptions()" class="tala-class-pagination" />
                @endif
            @else
                <div class="servitech-empty-collection">
                    <x-servitech-empty-state :illustrated="! $hasCriteria" :heading="$this->getTable()->getEmptyStateHeading()" :description="$this->getTable()->getEmptyStateDescription()" :icon="$hasCriteria ? 'heroicon-o-magnifying-glass' : 'heroicon-o-document-text'">
                        @if ($hasSearch)
                            <x-filament::button color="gray" wire:click="resetTableSearch" icon="heroicon-m-x-mark">Clear search</x-filament::button>
                        @endif
                        @if ($hasFilters)
                            <x-filament::button color="gray" wire:click="resetTableFiltersForm" icon="heroicon-m-arrow-path">Clear filters</x-filament::button>
                        @endif
                        @foreach ($this->getTable()->getEmptyStateActions() as $action){{ $action }}@endforeach
                    </x-servitech-empty-state>
                </div>
            @endif
        </div>
    </div>
    <x-filament-actions::modals />
</section>
