<x-filament-panels::page>
    <form wire:submit="saveDraft" class="space-y-6">
        {{ $this->form }}

        <div class="flex flex-wrap gap-3">
            <x-filament::button type="submit" icon="heroicon-m-bookmark-square">
                Save Draft
            </x-filament::button>

            <x-filament::button
                type="button"
                color="success"
                icon="heroicon-m-paper-airplane"
                wire:click="submitApplication"
            >
                Submit Application
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
