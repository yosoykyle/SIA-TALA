<div class="tala-record-page-header">
    <div class="tala-record-page-header__layout">
        <x-filament-panels::header :breadcrumbs="$this->getBreadcrumbs()" :heading="$this->getHeading()" :subheading="$this->getSubheading()" />
        @php
            $work = ($this->getTableFilterState('result_scope')['value'] ?? 'inc') === 'inc' ? 'INC follow-up' : 'Grade correction';
            $termId = $this->getTableFilterState('term')['value'] ?? null;
            $term = filled($termId) ? ($this->getTable()->getFilter('term')->getOptions()[$termId] ?? 'Selected term') : 'All terms';
        @endphp
        <div class="tala-record-query" aria-label="Find course results">
            <x-filament-tables::search-field label="Find a course result" placeholder="Student, course or class" debounce="350ms" />
            <x-filament::dropdown :menu="false" placement="bottom-end" width="sm" shift>
                <x-slot:trigger><x-filament::button color="gray" icon="heroicon-m-funnel">Filters</x-filament::button></x-slot:trigger>
                <x-filament-tables::filters :form="$this->getTableFiltersForm()" :apply-action="$this->getTable()->getFiltersApplyAction()" :reset-action="$this->getTable()->getFiltersResetAction()" />
            </x-filament::dropdown>
            <p class="tala-record-query-summary" aria-live="polite">{{ $work }} · {{ $term }}</p>
        </div>
    </div>
</div>
