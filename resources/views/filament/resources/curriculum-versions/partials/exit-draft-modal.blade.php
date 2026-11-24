<x-filament::modal
    id="curriculum-exit-modal"
    width="xl"
    :sticky-header="true"
    :sticky-footer="true"
    :heading="$confirmingExitDiscard ? 'Discard entire draft curriculum?' : 'Leave curriculum editor?'"
    :description="$confirmingExitDiscard
        ? 'This action permanently deletes the draft and all entered subjects. Active curricula and student records will not be affected.'
        : 'Your subjects are saved automatically in this draft. Choose how you want to proceed.'"
    x-on:close-modal.window="if ($event.detail.id === 'curriculum-exit-modal' && $wire.confirmingExitDiscard) { $wire.cancelDiscardEntireDraft() }"
>
    @if ($confirmingExitDiscard)
        <x-tala-access-alert id="curriculum-exit-discard-warning" variant="danger" title="Permanent deletion warning" role="alert">
            <p>
                Permanently delete this entire draft curriculum and all
                <strong>{{ $totalSubjectsCount }} {{ \Illuminate\Support\Str::plural('subject', $totalSubjectsCount) }}</strong>
                entered so far? Active curricula and student records will not be affected.
            </p>
        </x-tala-access-alert>
    @else
        <div class="tala-exit-modal__choices">
            <p class="tala-exit-modal__copy">
                You can return to <strong>{{ $curriculum->program?->code ?? 'this program' }}</strong> and resume editing this draft anytime, or discard the draft completely.
            </p>
        </div>
    @endif

    <x-slot:footer>
        <div class="tala-exit-modal__footer">
            @if ($confirmingExitDiscard)
                <div class="tala-exit-modal__footer-left">
                    <x-filament::button
                        type="button"
                        color="gray"
                        autofocus
                        wire:click="cancelDiscardEntireDraft"
                    >
                        Back
                    </x-filament::button>
                </div>
                <div class="tala-exit-modal__footer-right">
                    <x-filament::button
                        type="button"
                        color="danger"
                        icon="heroicon-m-trash"
                        aria-describedby="curriculum-exit-discard-warning"
                        wire:click="confirmDiscardEntireDraft"
                        wire:loading.attr="disabled"
                        wire:target="confirmDiscardEntireDraft"
                    >
                        Discard entire draft
                    </x-filament::button>
                </div>
            @else
                <div class="tala-exit-modal__footer-left">
                    @if ($canDiscardDraft)
                        <x-filament::button
                            type="button"
                            color="danger"
                            outlined
                            icon="heroicon-m-trash"
                            wire:click="promptDiscardEntireDraft"
                        >
                            Discard entire draft
                        </x-filament::button>
                    @endif
                </div>
                <div class="tala-exit-modal__footer-right">
                    <x-filament::button
                        type="button"
                        color="gray"
                        x-on:click="$dispatch('close-modal', { id: 'curriculum-exit-modal' })"
                    >
                        Keep editing
                    </x-filament::button>
                    <x-filament::button
                        type="button"
                        color="primary"
                        wire:click="saveAndExitDraft"
                        wire:loading.attr="disabled"
                        wire:target="saveAndExitDraft"
                    >
                        Keep draft &amp; leave
                    </x-filament::button>
                </div>
            @endif
        </div>
    </x-slot:footer>
</x-filament::modal>
