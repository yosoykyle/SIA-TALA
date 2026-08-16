<x-filament::button
    type="submit"
    icon="heroicon-m-paper-airplane"
    wire:loading.attr="disabled"
    wire:target="submitApplication,saveDraft"
>
    Submit application
</x-filament::button>
