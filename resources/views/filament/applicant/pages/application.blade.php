<x-filament-panels::page
    x-data="{
        changed: false,
        failed: false,
        unsubscribe: null,
        init() {
            this.unsubscribe = window.Livewire.interceptRequest(({ request, onError, onFailure }) => {
                if (! Array.from(request.messages).some(message => message.component.id === this.$wire.$id)) return;
                onError(({ preventDefault }) => { preventDefault(); this.failed = true; this.changed = true; });
                onFailure(() => { this.failed = true; this.changed = true; });
            });
        },
        destroy() { this.unsubscribe?.(); }
    }"
    x-on:draft-saved.window="changed = false; failed = false"
    x-on:sync-action-modals.window="if ($event.detail.id === $wire.$id && $event.detail.newActionNestingIndex === null) $nextTick(() => requestAnimationFrame(() => requestAnimationFrame(() => requestAnimationFrame(() => { if (document.activeElement === document.body) document.querySelector('[data-application-options]')?.focus({ preventScroll: true }); }))))"
    x-on:beforeunload.window="if (changed) { $event.preventDefault(); $event.returnValue = ''; }"
>
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
        @php($activeCorrection = $this->activeCorrectionRequest())
        @if ($activeCorrection)
            <x-filament::callout :color="$activeCorrection->isOverdue() ? 'danger' : 'warning'" icon="heroicon-m-exclamation-triangle">
                <x-slot name="heading">
                    {{ $activeCorrection->isOverdue() ? 'Correction overdue' : 'Corrections requested' }} —
                    {{ $activeCorrection->due_at->timezone(config('app.display_timezone'))->format('F j, Y, g:i A') }} (Asia/Manila)
                </x-slot>
                <x-slot name="description">
                    {{ $activeCorrection->applicant_instruction }} The due time does not lock or reject this Application; submit only the named corrections.
                </x-slot>
            </x-filament::callout>
        @elseif (! $this->admissionsAreOpen())
            <x-filament::callout color="warning" icon="heroicon-m-lock-closed">
                <x-slot name="heading">First submission is closed</x-slot>
                <x-slot name="description">
                    Inspect or discard your saved Draft. Saving, uploading, and first submission are unavailable because this Draft's Admission Cycle is closed or canceled. Contact the Registrar for Cycle guidance.
                </x-slot>
            </x-filament::callout>
        @endif

        <form wire:submit="submitApplication" class="space-y-6" novalidate x-on:input="changed = true" x-on:change="changed = true">
            {{ $this->form }}
        <div x-cloak x-show="failed" role="alert">
            <x-filament::callout color="warning" icon="heroicon-m-exclamation-triangle">
                <x-slot name="heading">Progress could not be confirmed</x-slot>
                <x-slot name="description">Keep this page open to preserve your entered work. When the connection returns, check Home before repeating a save or submission. TALA will not repeat the action automatically.</x-slot>
            </x-filament::callout>
        </div>
            <div class="tala-draft-status" role="status" aria-live="polite" aria-atomic="true" x-show="! failed">
                <span wire:loading class="tala-draft-status-message">
                    <x-filament::icon icon="heroicon-o-arrow-path" class="size-5 shrink-0" />
                    Saving or checking your work…
                </span>
                <div wire:loading.remove>
                    @if ($this->saveStatus === 'failed')
                        <x-filament::callout color="warning" icon="heroicon-o-exclamation-triangle">
                            <x-slot name="heading">Save incomplete</x-slot>
                            <x-slot name="description">{{ $this->saveStatusMessage }}</x-slot>
                        </x-filament::callout>
                    @else
                        <span x-show="changed" data-draft-dirty class="tala-draft-status-message">
                            <x-filament::icon icon="heroicon-o-pencil-square" class="size-5 shrink-0" />
                            Unsaved changes. Save and continue, or use Application options to save and exit.
                        </span>
                        <span x-show="! changed" class="tala-draft-status-message">
                            <x-filament::icon :icon="$this->saveStatus === 'saved' ? 'heroicon-o-check-circle' : 'heroicon-o-clock'" class="size-5 shrink-0" />
                            {{ $this->saveStatusMessage }}
                        </span>
                    @endif
                </div>
            </div>
        </form>
    @endif
</x-filament-panels::page>
