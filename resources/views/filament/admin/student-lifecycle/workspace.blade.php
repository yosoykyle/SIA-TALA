@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
@php
    $records = $this->getTableRecords();
    $selected = $this->selectedChange();
    $filters = $this->getTableFiltersForm();
@endphp
<section class="tala-lifecycle-collection" aria-label="Student changes">
    <div class="tala-lifecycle-query tala-task-surface" x-data="{ filtersOpen: false }" x-bind:data-filters-open="filtersOpen">
        <div class="tala-lifecycle-query-primary">
            <x-filament-tables::search-field label="Search students" :label-hidden="false" placeholder="Student name or number" />
            <x-filament::button class="tala-lifecycle-filter-toggle" color="gray" icon="heroicon-m-funnel" x-on:click="filtersOpen = ! filtersOpen" x-bind:aria-expanded="filtersOpen">Filters</x-filament::button>
        </div>
        <div class="tala-lifecycle-primary-filter">{{ $filters->getComponent('term') }}</div>
        <div class="tala-lifecycle-primary-filter">{{ $filters->getComponent('type') }}</div>
        <x-filament::dropdown class="tala-lifecycle-secondary-filter" :menu="false" placement="bottom-end" width="sm" shift>
            <x-slot:trigger><x-filament::button color="gray" icon="heroicon-m-funnel">More filters</x-filament::button></x-slot:trigger>
            <div class="tala-lifecycle-more-filters">
                {{ $filters->getComponent('state') }}
                {{ $filters->getComponent('effective_date') }}
            </div>
        </x-filament::dropdown>
        @if ($this->getTable()->isFiltered() || $this->hasTableSearch())
            <div class="tala-lifecycle-active-filters">
                <p>{{ $records->total() }} matching {{ \Illuminate\Support\Str::plural('change', $records->total()) }}@if ($state = $this->getTableFilterState('state')['value'] ?? null) · Status: {{ $this->getTable()->getFilter('state')->getOptions()[$state] ?? $state }}@endif@if ($from = $this->getTableFilterState('effective_date')['from'] ?? null) · From {{ $from }}@endif@if ($until = $this->getTableFilterState('effective_date')['until'] ?? null) · Until {{ $until }}@endif</p>
                <x-filament::button color="gray" size="sm" wire:click="clearLifecycleFilters">Clear search and filters</x-filament::button>
            </div>
        @endif
    </div>
    @if ($records->isNotEmpty())
        <x-record-workspace wire:key="lifecycle-populated-workspace" collection-label="student changes" selection-parameter="change" selection-actions="selectChange" loading-label="Loading selected change" class="tala-lifecycle-workspace">
            <x-slot:browser aria-label="Recorded student changes">
                <header class="tala-record-browser-header"><h2>{{ $records->total() }} {{ \Illuminate\Support\Str::plural('change', $records->total()) }}</h2></header>
                <ul class="tala-record-list">
                    @foreach ($records as $change)
                        @php($profile = $change->studentProfile)
                        <li wire:key="{{ $this->getId() }}.table.records.{{ $change->getKey() }}">
                            <x-record-option :selected="$selected?->id === $change->id" wire:click="selectChange({{ $change->id }})" aria-controls="lifecycle-inspector" class="tala-lifecycle-option">
                                <strong>{{ $profile ? collect([$profile->first_name, $profile->middle_name, $profile->last_name])->filter()->implode(' ') : 'Student record unavailable' }}</strong>
                                <small>{{ $profile?->student_number }}</small>
                                <x-slot:meta><time class="tala-lifecycle-option-meta" datetime="{{ $change->effective_on->toDateString() }}">{{ $change->effective_on->format('M j, Y') }}</time></x-slot:meta>
                                <x-slot:context><strong>{{ \App\Models\StudentLifecycleChange::typeOptions()[$change->type] ?? str($change->type)->headline() }}</strong><small>{{ collect([$change->term?->label, $change->term?->academicYear?->label])->filter()->implode(' · ') ?: 'Term not recorded' }}</small><x-filament::badge :color="$change->statusColor()">{{ $change->statusLabel() }}</x-filament::badge></x-slot:context>
                            </x-record-option>
                        </li>
                    @endforeach
                </ul>
            </x-slot:browser>
            <x-slot:collectionFooter><x-record-collection-footer :paginator="$records" label="changes" :page-options="$this->getTable()->getPaginationPageOptions()" /></x-slot:collectionFooter>
            <x-slot:inspector id="lifecycle-inspector" aria-label="Selected student change">
                @if ($selected)
                    @include('filament.admin.student-lifecycle.inspector', ['change' => $selected])
                @else
                    <x-filament::empty-state icon="heroicon-o-arrow-path-rounded-square" compact>
                        <x-slot:heading>Select a change from these results</x-slot:heading>
                        <x-slot:description>The previously selected change is outside the current page or filters.</x-slot:description>
                    </x-filament::empty-state>
                @endif
            </x-slot:inspector>
        </x-record-workspace>
    @else
        <x-servitech-empty-state wire:key="lifecycle-empty-collection" :illustrated="! $this->hasTableSearch() && ! $this->getTable()->isFiltered()" :heading="$this->getTable()->getEmptyStateHeading()" :description="$this->getTable()->getEmptyStateDescription()" icon="heroicon-o-magnifying-glass" />
    @endif
    <x-filament-actions::modals />
</section>
