{{-- #110 item 11: the last wizard step saves the cycle; Cancel stays beside it. --}}
<div class="tala-cycle-wizard__submit">
    {{-- The create page wraps the wizard in a form; the edit page does not (it embeds the requirement-set table), so it saves directly. --}}
    <x-filament::button
        type="button"
        icon="heroicon-m-check"
        wire:loading.attr="disabled"
        x-on:click="$el.closest('form') ? $el.closest('form').requestSubmit() : $wire.save()"
    >
        Save &amp; continue
    </x-filament::button>
    @include('filament.admin.admission-cycles.wizard-cancel')
</div>
