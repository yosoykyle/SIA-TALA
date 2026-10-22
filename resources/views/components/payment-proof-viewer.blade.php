@props(['proof', 'label' => 'View receipt', 'primary' => false])
@assets
    @vite('resources/js/payment-proof-preview.js')
@endassets
<x-filament::modal :id="'payment-proof-'.$proof->id" width="5xl">
    <x-slot name="trigger">
        <x-filament::button :color="$primary ? 'primary' : 'gray'" icon="heroicon-o-document-magnifying-glass">{{ $label }}</x-filament::button>
    </x-slot>
    <x-slot name="heading">Payment proof</x-slot>
    <x-slot name="description">{{ $proof->original_name }} · {{ str($proof->state)->headline() }}</x-slot>
    <div class="payment-proof-preview" data-payment-proof-preview data-proof-open="false"
        data-proof-url="{{ route('finance.payment-evidence.view', $proof) }}"
        x-init="$watch('isOpen', open => { $el.dataset.proofOpen = open ? 'true' : 'false'; $el.dispatchEvent(new CustomEvent(open ? 'payment-proof:open' : 'payment-proof:close', { bubbles: true })); })">
        <p data-proof-status role="status" aria-live="polite">Loading receipt…</p>
        <div class="payment-proof-pages" data-proof-pages></div>
    </div>
    <p class="student-proof-privacy">Private to you and authorized school staff. If your browser cannot display the file, download it below.</p>
    <x-slot name="footerActions">
        <x-filament::button tag="a" :href="route('finance.payment-evidence.download', $proof)" icon="heroicon-o-arrow-down-tray">Download receipt</x-filament::button>
        <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'payment-proof-{{ $proof->id }}' })">Close</x-filament::button>
    </x-slot>
</x-filament::modal>
