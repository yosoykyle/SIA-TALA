<x-filament::button
    type="submit"
    color="success"
    icon="heroicon-m-paper-airplane"
    wire:loading.attr="disabled"
    wire:target="saveDraft,submitApplication"
>
    Submit Application
</x-filament::button>
