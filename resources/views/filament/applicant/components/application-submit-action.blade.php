<x-filament::button
    type="submit"
    :icon="$formSubmitAction === 'saveDraft' ? 'heroicon-m-bookmark-square' : 'heroicon-m-paper-airplane'"
    wire:loading.attr="disabled"
    wire:target="submitApplication,saveDraft"
>
    {{ $formSubmitAction === 'saveDraft' ? 'Save draft' : ($this->isCorrectionMode() ? 'Resubmit corrections' : ($this->isSubmittedEdit() ? 'Submit application changes' : 'Submit application')) }}
</x-filament::button>
