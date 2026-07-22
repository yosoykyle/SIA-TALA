<x-filament-panels::page>
    <form wire:submit="submitApplication" class="space-y-6">
        {{ $this->form }}

        <div class="flex flex-wrap gap-3">
            <x-filament::button
                type="button"
                icon="heroicon-m-bookmark-square"
                wire:click="saveDraft"
                wire:loading.attr="disabled"
                wire:target="saveDraft,submitApplication"
            >
                Save Draft
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
