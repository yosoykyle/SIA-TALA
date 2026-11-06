@php
    $table = $this->getTable();
    $filtered = $this->hasTableSearch() || $table->isFiltered();
@endphp
<x-servitech-empty-state :heading="$table->getEmptyStateHeading()" :description="$table->getEmptyStateDescription()" :illustrated="! $filtered" :icon="$filtered ? 'heroicon-o-magnifying-glass' : 'heroicon-o-document-text'">
    @if ($this->hasTableSearch())
        <x-filament::button color="gray" wire:click="resetTableSearch" icon="heroicon-m-x-mark">Clear search</x-filament::button>
    @endif
    @if ($table->isFiltered())
        <x-filament::button color="gray" wire:click="resetTableFiltersForm" icon="heroicon-m-arrow-path">Clear filters</x-filament::button>
    @endif
    @foreach ($table->getEmptyStateActions() as $action){{ $action }}@endforeach
</x-servitech-empty-state>
