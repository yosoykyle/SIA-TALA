<div class="tala-drawer-heading">
    <span>Workspace navigation</span>
    <x-filament::icon-button
        :icon="\Filament\Support\Icons\Heroicon::OutlinedXMark"
        label="Close workspace navigation"
        color="gray"
        x-on:click="$store.sidebar.close()"
    />
</div>
