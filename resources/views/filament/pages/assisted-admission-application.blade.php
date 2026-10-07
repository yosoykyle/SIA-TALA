<x-filament-panels::page>
    @if ($ineligibility = $this->ineligibilityReason())
        <x-filament::section>
            <x-slot name="heading">Assisted entry unavailable for this applicant</x-slot>
            <x-slot name="description">{{ $ineligibility }}</x-slot>
            <div class="mt-4">
                <x-filament::button
                    tag="a"
                    :href="\App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource::getUrl()"
                    color="gray"
                    icon="heroicon-m-arrow-left"
                >
                    Return to Admissions
                </x-filament::button>
            </div>
        </x-filament::section>
    @else
        <x-filament::callout color="info" icon="heroicon-m-user-group">
            <x-slot name="heading">Prepare the Applicant's Draft; do not submit for them</x-slot>
            <x-slot name="description">
                The Applicant remains the owner. The Registrar may prepare or discard an unsubmitted Draft only; the Applicant must review declarations and submit it from their own workspace.
            </x-slot>
        </x-filament::callout>

        <div
            class="rounded-lg border border-gray-200 bg-white/70 px-4 py-3 text-sm shadow-sm dark:border-white/10 dark:bg-white/5"
            role="status"
            aria-live="polite"
            aria-atomic="true"
        >
            <span wire:dirty wire:target="data">Unsaved assisted-entry changes.</span>
            <span wire:loading wire:target="saveDraft, saveAndExit">Saving the Applicant-owned Draft to TALA.</span>
            <span wire:loading.remove wire:target="saveDraft, saveAndExit">{{ $this->saveStatusMessage }}</span>
        </div>

        <form wire:submit.prevent="saveDraft" class="space-y-6" novalidate>
            {{ $this->form }}

            <div class="tala-action-block flex flex-wrap items-center gap-3">
                <x-filament::button
                    type="submit"
                    icon="heroicon-m-bookmark-square"
                    wire:loading.attr="disabled"
                    wire:target="saveDraft, saveAndExit"
                    :disabled="! $this->draftWorkIsAvailable()"
                >
                    Save Applicant Draft
                </x-filament::button>

                <x-filament::button
                    type="button"
                    wire:click="saveAndExit"
                    color="gray"
                    icon="heroicon-m-arrow-right-on-rectangle"
                    wire:loading.attr="disabled"
                    wire:target="saveDraft, saveAndExit"
                    :disabled="! $this->draftWorkIsAvailable()"
                >
                    Save and exit
                </x-filament::button>

                <x-filament::button
                    tag="a"
                    :href="\App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource::getUrl()"
                    color="gray"
                    outlined
                >
                    Cancel
                </x-filament::button>
            </div>
        </form>
    @endif
</x-filament-panels::page>
