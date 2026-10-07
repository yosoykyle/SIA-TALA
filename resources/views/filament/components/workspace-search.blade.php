@if (filament()->getGlobalSearchProvider())
<div class="tala-workspace-search-shortcut"
    x-data
    x-on:keydown.window="if (($event.ctrlKey || $event.metaKey) && $event.key.toLowerCase() === 'k') {
        $event.preventDefault();
        $store.sidebar.open();
        $nextTick(() => document.querySelector('.fi-sidebar .fi-global-search-field input')?.focus());
    }"
>
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
