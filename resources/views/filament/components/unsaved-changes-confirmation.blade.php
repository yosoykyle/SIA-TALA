<div
    x-data="{ pending: null }"
    x-on:tala-confirm-leave.window="pending = $event.detail; $dispatch('open-modal', { id: 'tala-unsaved-changes' })"
    x-on:modal-closed.window="if ($event.detail.id === 'tala-unsaved-changes') pending = null"
>
    <x-filament::modal
        id="tala-unsaved-changes"
        width="md"
        heading="Leave without saving?"
        description="Your unsaved changes will be discarded. Stay on this page to keep working."
        icon="heroicon-o-exclamation-triangle"
        icon-color="warning"
        footer-actions-alignment="end"
    >
        <x-slot name="footerActions">
            <x-filament::button color="gray" type="button" autofocus
                x-on:click="$dispatch('close-modal', { id: 'tala-unsaved-changes' })">
                Keep editing
            </x-filament::button>
            <x-filament::button color="danger" type="button"
                x-on:click="const decision = pending; $dispatch('close-modal', { id: 'tala-unsaved-changes' }); decision?.leave()">
                Leave page
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</div>
