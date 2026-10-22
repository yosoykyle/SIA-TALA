@if (filament()->getGlobalSearchProvider() && filament()->getGlobalSearchPosition() === \Filament\Enums\GlobalSearchPosition::Sidebar)
<div class="tala-workspace-search-shortcut"
    x-data
    x-on:keydown.window="if (($event.ctrlKey || $event.metaKey) && $event.key.toLowerCase() === 'k') {
        $event.preventDefault();
        $store.sidebar.open();
        $nextTick(() => document.querySelector('.fi-sidebar .fi-global-search-field input')?.focus());
    }"
>
    {{-- Page-only panels need the same native finder even without searchable resources. --}}
    @unless (filament()->isGlobalSearchEnabled())
        <div x-cloak x-show="$store.sidebar.isOpen">
            @livewire(\Filament\Livewire\GlobalSearch::class)
        </div>
    @endunless

    <x-filament::icon-button
        class="tala-collapsed-nav-search"
        icon="heroicon-o-magnifying-glass"
        color="gray"
        label="Find a workspace page"
        x-cloak
        x-show="! $store.sidebar.isOpen"
        x-on:click="$store.sidebar.open(); $nextTick(() => document.querySelector('.fi-sidebar .fi-global-search-field input')?.focus())"
    />
</div>

@endif
