@props(['table', 'form'])
@php($activeFilterCount = $table->getActiveFiltersCount())
<x-filament::dropdown
    placement="bottom-end"
    shift
    :width="$table->getFiltersFormWidth() ?? \Filament\Support\Enums\Width::ExtraSmall"
    {{ $attributes->class(['fi-ta-filters-dropdown', 'tala-record-collection-filters']) }}
>
    <x-slot:trigger>{{ $table->getFiltersTriggerAction()->labeledFrom(null)->badge($activeFilterCount ?: null) }}</x-slot:trigger>
    <x-filament-tables::filters
        :apply-action="$table->getFiltersApplyAction()"
        :form="$form"
        :reset-action="$table->getFiltersResetAction()"
        :reset-action-position="$table->getFiltersResetActionPosition()"
    />
</x-filament::dropdown>
