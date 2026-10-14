<x-filament-panels::page
    class="tala-application-page"
    data-exit-path="{{ parse_url($exitUrl, PHP_URL_PATH) }}"
    x-data="{
        changed: false,
        failed: false,
        unsubscribe: null,
        exitPath: null,
        guardExit(event) {
            if (! this.changed || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            const link = event.target.closest('a[href]');
            if (! link || link.hasAttribute('data-application-leave-confirmed') || link.target === '_blank') return;
            const destination = new URL(link.href, window.location.href);
            if (destination.origin !== window.location.origin || destination.pathname !== this.exitPath) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            this.$store.sidebar.close();
            this.$dispatch('open-modal', { id: 'tala-leave-application' });
        },
        init() {
            this.exitPath = this.$el.dataset.exitPath;
            this.unsubscribe = window.Livewire.interceptRequest(({ request, onError, onFailure }) => {
                if (! request.messages || ! Array.from(request.messages).some(message => message.component.id === this.$wire.$id)) return;
                onError(({ preventDefault }) => { preventDefault(); this.failed = true; this.changed = true; });
                onFailure(() => { this.failed = true; this.changed = true; });
            });
        },
        destroy() { this.unsubscribe?.(); }
    }"
    x-on:draft-saved.window="changed = false; failed = false"
    x-on:click.window.capture="guardExit($event)"
    x-on:sync-action-modals.window="if ($event.detail.id === $wire.$id && $event.detail.newActionNestingIndex === null) $nextTick(() => requestAnimationFrame(() => requestAnimationFrame(() => requestAnimationFrame(() => { if (document.activeElement === document.body) document.querySelector('[data-application-options]')?.focus({ preventScroll: true }); }))))"
    x-on:beforeunload.window="if (changed) { $event.preventDefault(); $event.returnValue = ''; }"
>
    @if ($assistanceUnavailableReason)
        <x-filament::section>
            <x-slot name="heading">Assisted entry unavailable for this applicant</x-slot>
            <x-slot name="description">{{ $assistanceUnavailableReason }}</x-slot>

            <x-filament::button tag="a" :href="$exitUrl" color="gray">Return to {{ $exitLocation }}</x-filament::button>
        </x-filament::section>
    @elseif (! $this->admissionsAreOpen() && ! $this->hasExistingDraft())
        <x-filament::section>
            <x-slot name="heading">Applications are currently closed</x-slot>
            <x-slot name="description">
                There is no open intake for a new application. Saved applications remain available on {{ $exitLocation }}.
            </x-slot>

            <x-filament::button tag="a" :href="$exitUrl" color="gray">Return to {{ $exitLocation }}</x-filament::button>
        </x-filament::section>
    @else
        @php($activeCorrection = $this->activeCorrectionRequest())
        <div x-show="! failed">
        @if ($this->saveStatus === 'failed')
            <x-filament::callout color="warning" icon="heroicon-o-exclamation-triangle">
                <x-slot name="description">
                    <p class="fi-callout-heading">Your changes could not be completed</p>
                    <p>{{ $this->saveStatusMessage }}</p>
                </x-slot>
            </x-filament::callout>
        @elseif ($activeCorrection)
            @include('filament.applicant.components.correction-request', ['correctionSteps' => $this->correctionSteps()])
        @elseif ($this->isSubmittedEdit())
            <x-filament::callout color="info" icon="heroicon-m-pencil-square">
                <x-slot name="heading">You can update this application</x-slot>
                <x-slot name="description">Submit the complete update before Registrar review starts. Your last submission stays unchanged until the update succeeds.</x-slot>
            </x-filament::callout>
        @elseif (! $this->admissionsAreOpen())
            <x-filament::callout color="warning" icon="heroicon-m-lock-closed">
                <x-slot name="heading">This draft is read-only</x-slot>
                <x-slot name="description">
                    This intake is no longer open. You can view or discard your draft. Contact the Registrar if you need intake guidance.
                </x-slot>
            </x-filament::callout>
        @endif
        </div>

        <div x-cloak x-show="failed" role="alert">
            <x-filament::callout color="warning" icon="heroicon-m-exclamation-triangle">
                <x-slot name="description">
                    <p class="fi-callout-heading">Your progress could not be confirmed</p>
                    <p>Keep this page open so your entered answers remain available. Check the latest recorded status before trying again.</p>
                    <p class="mt-2"><a class="fi-link fi-color-primary" href="{{ $exitUrl }}" target="_blank" rel="noopener">Check current status<span class="fi-sr-only"> (opens in a new tab)</span></a></p>
                </x-slot>
            </x-filament::callout>
        </div>

        <form wire:submit="{{ $formSubmitAction }}" class="space-y-6" novalidate x-on:input="changed = true" x-on:change="changed = true">
            @if (! $this->requiresCompleteSubmission() && $this->saveStatus !== 'failed')
                <div x-show="! failed">
                    @include('filament.applicant.components.application-save-status')
                </div>
            @endif
            {{ $this->form }}
        </form>
    @endif
    <x-filament::modal
        id="tala-leave-application"
        width="md"
        :heading="$this->requiresCompleteSubmission() ? 'Leave without submitting?' : 'Save your progress before leaving?'"
        :description="$this->requiresCompleteSubmission() ? 'These changes have not been submitted. Keep editing, or return to your last submitted version.' : 'Save and exit keeps your latest answers so you can resume later.'"
        icon="heroicon-o-exclamation-triangle"
        icon-color="warning"
    >
        <x-slot name="footer">
            <div class="fi-modal-footer-actions">
                @if ($this->requiresCompleteSubmission())
                    <x-filament::button color="gray" outlined type="button" x-on:click="$dispatch('close-modal', { id: 'tala-leave-application' })" autofocus>
                        Keep editing
                    </x-filament::button>
                @endif
                <x-filament::button tag="a" color="gray" outlined :href="$exitUrl" data-application-leave-confirmed x-on:click="changed = false; failed = false">
                    {{ $this->requiresCompleteSubmission() ? 'Leave without submitting' : 'Leave without saving' }}
                </x-filament::button>
                @if (! $this->requiresCompleteSubmission())
                    <x-filament::button type="button" wire:click="mountAction('saveAndExit')" wire:loading.attr="disabled" x-on:click="$dispatch('close-modal', { id: 'tala-leave-application' })" autofocus>
                        Save and exit
                    </x-filament::button>
                @endif
            </div>
        </x-slot>
    </x-filament::modal>
</x-filament-panels::page>
