@php
    $table = $this->getTable();
    $hasSearch = $this->hasTableSearch();
    $hasActiveFilterIndicators = collect($table->getFilterIndicators())
        ->reject(fn (\Filament\Tables\Filters\Indicator $indicator): bool => $indicator->getRemoveLivewireClickHandler() === 'resetTableSearch')
        ->contains(fn (\Filament\Tables\Filters\Indicator $indicator): bool => filled($indicator->getLabel()));
    $hasActiveFilterValues = collect($table->getFilters())->contains(function (\Filament\Tables\Filters\BaseFilter $filter): bool {
        $indicators = collect($filter->getIndicators())
            ->filter(fn (\Filament\Tables\Filters\Indicator $indicator): bool => filled($indicator->getLabel()));

        if ($indicators->isEmpty()) {
            return false;
        }

        $state = $this->getTableFilterState($filter->getName());

        if (method_exists($filter, 'canSelectPlaceholder') && ! $filter->canSelectPlaceholder() && (($state['value'] ?? null) === $filter->getDefaultState())) {
            return false;
        }

        if (! is_array($state)) {
            return filled($state);
        }

        return collect(\Illuminate\Support\Arr::dot($state))
            ->contains(fn (mixed $value): bool => filled($value) && $value !== false);
    });
    $hasFilters = $table->isFiltered() && $hasActiveFilterIndicators && $hasActiveFilterValues;
    $filtered = $hasSearch || $hasFilters;
@endphp
<x-servitech-empty-state :heading="$table->getEmptyStateHeading()" :description="$table->getEmptyStateDescription()" :illustrated="! $filtered" :icon="$filtered ? 'heroicon-o-magnifying-glass' : 'heroicon-o-document-text'">
    @if ($hasSearch)
        <x-filament::button color="gray" wire:click="resetTableSearch" icon="heroicon-m-x-mark">Clear search</x-filament::button>
    @endif
    @if ($hasFilters)
        <x-filament::button color="gray" wire:click="resetTableFiltersForm" icon="heroicon-m-arrow-path">Clear filters</x-filament::button>
    @endif
    @foreach ($table->getEmptyStateActions() as $action)
        @if ($action->isVisible())
            {{ $action }}
        @endif
    @endforeach
</x-servitech-empty-state>
