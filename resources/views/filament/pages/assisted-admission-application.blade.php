<x-filament-panels::page>
    <x-filament::callout color="info" icon="heroicon-m-user-group">
        <x-slot name="heading">Prepare the Applicant's Draft; do not submit for them</x-slot>
        <x-slot name="description">
            This uses the same Application record, validation, effective Requirement Set, private evidence service, and retained history as Applicant self-service. The Applicant must review the unchecked declarations and perform first submission.
        </x-slot>
    </x-filament::callout>

    <div
        class="rounded-lg border border-gray-200 bg-white/70 px-4 py-3 text-sm shadow-sm dark:border-white/10 dark:bg-white/5"
        role="status"
        aria-live="polite"
        aria-atomic="true"
    >
        <span wire:dirty wire:target="data">Unsaved assisted-entry changes.</span>
        <span wire:loading wire:target="saveDraft">Saving the Applicant-owned Draft to TALA.</span>
        <span wire:loading.remove wire:target="saveDraft">{{ $this->saveStatusMessage }}</span>
    </div>

    <form wire:submit.prevent="saveDraft" class="space-y-6" novalidate>
        {{ $this->form }}

        <div class="tala-action-block">
            <x-filament::button
                type="submit"
                icon="heroicon-m-bookmark-square"
                wire:loading.attr="disabled"
                wire:target="saveDraft"
            >
                Save Applicant Draft
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
