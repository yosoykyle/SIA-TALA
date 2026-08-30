<x-filament-panels::page>
    <x-examination-period-summary :projection="$this->examinationPeriod()" />

    @if ($this->rosterId === null)
        <x-filament::section>
            <x-slot name="heading">No assigned roster</x-slot>
            <x-slot name="description">Current designated and co-Faculty assignments appear here after the Registrar records them.</x-slot>
        </x-filament::section>
    @else
        <form wire:submit="saveDraft" class="space-y-6">
            {{ $this->form }}

            @if ($this->canEditSelectedRoster())
                <div class="tala-action-block">
                    <x-filament::button
                        type="submit"
                        color="gray"
                        icon="heroicon-m-bookmark-square"
                        wire:loading.attr="disabled"
                        wire:target="saveDraft"
                    >
                        Save draft
                    </x-filament::button>
                </div>
            @endif
        </form>
    @endif
</x-filament-panels::page>
