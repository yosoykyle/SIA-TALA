<div class="{{ ($inline ?? false) ? 'tala-drawer-close-inline' : 'tala-drawer-heading' }}">
    @unless ($inline ?? false)
        <span>Workspace navigation</span>
    @endunless
    @if ($inline ?? false)
        <x-filament::button
            :icon="\Filament\Support\Icons\Heroicon::OutlinedXMark"
            color="gray"
            outlined
            label-sr-only
            x-on:click="$store.sidebar.close()"
        >
            Close workspace navigation
        </x-filament::button>
    @else
        <x-filament::icon-button
            :icon="\Filament\Support\Icons\Heroicon::OutlinedXMark"
            label="Close workspace navigation"
            color="gray"
            x-on:click="$store.sidebar.close()"
        />
    @endif
</div>
