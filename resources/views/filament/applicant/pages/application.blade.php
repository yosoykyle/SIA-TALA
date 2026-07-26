<x-filament-panels::page>
    @if (! $this->admissionsAreOpen() && ! $this->hasExistingDraft())
        <x-filament::section>
            <x-slot name="heading">Applications are currently closed</x-slot>
            <x-slot name="description">
                The school is not accepting new online applications for an active admission term.
            </x-slot>

            <x-filament::callout color="info" icon="heroicon-m-information-circle">
                <x-slot name="heading">What you can do</x-slot>
                <x-slot name="description">
                    Check the public TALA page for the next admission period or contact the Registrar for assistance.
                </x-slot>
            </x-filament::callout>
        </x-filament::section>
    @else
        @if ($this->hasExistingDraft())
            @php($currentDraft = $this->currentDraft())
            <x-filament::callout color="info" icon="heroicon-m-bookmark-square">
                <x-slot name="heading">Continuing your saved draft</x-slot>
                <x-slot name="description">
                    Your saved information and document selections are loaded below.
                    @if ($currentDraft?->updated_at)
                        Last saved {{ \App\Support\DisplayDateTime::format($currentDraft->updated_at, 'F j, Y \a\t g:i A') }}.
                    @endif
                    Review the details, make any needed changes, then save again or submit when ready.
                </x-slot>
            </x-filament::callout>
        @endif

        @if (! $this->canSubmitApplication())
            <x-filament::callout color="warning" icon="heroicon-m-clock">
                <x-slot name="heading">Final submission is currently closed</x-slot>
                <x-slot name="description">
                    You may continue saving this existing draft. Submit it after the Registrar opens Admissions for the selected term.
                </x-slot>
            </x-filament::callout>
        @endif

        <form wire:submit="submitApplication" class="space-y-6">
            {{ $this->form }}

            <div class="tala-action-block">
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
    @endif
</x-filament-panels::page>
