<x-filament-panels::page>
    @if (! $this->admissionsAreOpen() && ! $this->hasExistingDraft())
        <x-filament::section>
            <x-slot name="heading">Applications are currently closed</x-slot>
            <x-slot name="description">
                No published Admission Cycle is accepting a first submission. Applicant sign-in and retained history remain available.
            </x-slot>

            <x-filament::callout color="info" icon="heroicon-m-information-circle">
                <x-slot name="heading">Safe next action</x-slot>
                <x-slot name="description">
                    Return to Applicant Home or the public TALA gateway for current Cycle guidance and official support.
                </x-slot>
            </x-filament::callout>
        </x-filament::section>
    @else
        @if ($this->hasExistingDraft())
            <x-filament::callout color="info" icon="heroicon-m-bookmark-square">
                <x-slot name="heading">Continuing the same application</x-slot>
                <x-slot name="description">
                    Save partial work at any step. Submission creates an immutable version; only a later scoped correction can reopen named facts or evidence.
                </x-slot>
            </x-filament::callout>
        @endif

        @if (! $this->admissionsAreOpen())
            <x-filament::callout color="warning" icon="heroicon-m-lock-closed">
                <x-slot name="heading">First submission is closed</x-slot>
                <x-slot name="description">
                    Your safe draft remains available, but the server will reject submission until an authorized extension or reopening.
                </x-slot>
            </x-filament::callout>
        @endif

        <form wire:submit="submitApplication" class="space-y-6" novalidate>
            {{ $this->form }}

            <div class="tala-action-block">
                <x-filament::button
                    type="button"
                    color="gray"
                    icon="heroicon-m-bookmark-square"
                    wire:click="saveDraft"
                    wire:loading.attr="disabled"
                    wire:target="saveDraft,submitApplication"
                >
                    Save draft
                </x-filament::button>
            </div>
        </form>
    @endif
</x-filament-panels::page>
