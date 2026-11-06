@php($records = $this->getTableRecords())
<section class="tala-class-worklist" aria-label="Class grade rosters">
    @if ($records->isNotEmpty() || $this->hasClassQueueFilters())
    <div class="tala-class-query tala-task-surface">
        {{ $this->getTableFiltersForm() }}
    </div>
    @if ($records->isNotEmpty() && $this->hasClassQueueFilters())
    <div class="tala-class-scope">
        <x-filament::button color="gray" size="sm" wire:click="clearClassQueue" wire:loading.attr="disabled">Clear search and filters</x-filament::button>
    </div>
    @endif
    @endif
    <div class="tala-content-region" wire:loading.attr="aria-busy" wire:target="tableSearch,tableFilters,tableRecordsPerPage,gotoPage,previousPage,nextPage,clearClassQueue">
        <x-content-progress label="Loading class rosters" wire:loading.delay.short.block wire:target="tableSearch,tableFilters,tableRecordsPerPage,gotoPage,previousPage,nextPage,clearClassQueue" />
        <div>
            @if ($records->isNotEmpty())
                <ul class="tala-class-grid" aria-label="Class rosters">
                    @foreach ($records as $roster)
                        <li wire:key="{{ $this->getId() }}.table.records.{{ $roster->getKey() }}">
                            <x-class-roster-card :roster="$roster" :href="$this->getTable()->getRecordUrl($roster)" :show-term="blank($this->getTableFilterState('term')['value'] ?? null)" />
                        </li>
                    @endforeach
                </ul>
            @else
                <x-servitech-empty-state :illustrated="! $this->hasClassQueueFilters()" :heading="$this->getTable()->getEmptyStateHeading()" :description="$this->getTable()->getEmptyStateDescription()" icon="heroicon-o-magnifying-glass">
                    @foreach ($this->getTable()->getEmptyStateActions() as $action){{ $action }}@endforeach
                </x-servitech-empty-state>
            @endif
        </div>
    </div>
    @if ($records->total() > 0)
        <x-record-collection-footer :paginator="$records" label="classes" :page-options="$this->getTable()->getPaginationPageOptions()" class="tala-class-pagination" />
    @endif
    <x-filament-actions::modals />
</section>
