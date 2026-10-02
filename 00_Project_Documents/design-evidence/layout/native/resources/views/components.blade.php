<x-filament-panels::page>
    <p class="tala-notice">Design reference only. This isolated preview has no database connection. Layout and behavior come from Filament.</p>
    <form wire:submit="save" class="tala-form">
        {{ $this->form }}
        <div class="tala-actions">
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="save">Validate example</x-filament::button>
            {{ $this->confirmationAction }}
            <x-filament::button color="gray" disabled>Disabled action</x-filament::button>
        </div>
    </form>
    {{ $this->table }}
</x-filament-panels::page>
