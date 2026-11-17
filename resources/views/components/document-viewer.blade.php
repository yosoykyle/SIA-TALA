@props([
    'id',
    'viewUrl',
    'downloadUrl' => null,
    'title' => 'Submitted receipt',
    'description' => null,
    'triggerLabel' => 'View receipt',
    'downloadLabel' => 'Download receipt',
    'closeLabel' => 'Close',
    'privacyNote' => 'Private to you and authorized school staff. If your browser cannot display the file, download it below.',
    'loadingMessage' => 'Loading receipt…',
    'previewAriaLabel' => null,
    'primary' => false,
    'size' => null,
    'icon' => 'heroicon-o-document-magnifying-glass',
    'downloadIcon' => 'heroicon-o-arrow-down-tray',
    'width' => '5xl',
    'triggerVariant' => 'button',
    'showTrigger' => true,
    'inline' => false,
    'showInlineDownload' => true,
])
@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
    @vite('resources/js/payment-proof-preview.js')
@endassets
@if ($inline)
    <div class="tala-document-viewer-inline">
        <div
            class="payment-proof-preview tala-document-preview"
            data-payment-proof-preview
            data-proof-open="true"
            data-proof-url="{{ $viewUrl }}"
            role="region"
            aria-label="{{ $previewAriaLabel ?: $title }}"
            tabindex="0"
            x-init="$nextTick(() => { $el.dataset.proofOpen = 'true'; $el.dispatchEvent(new CustomEvent('payment-proof:open', { bubbles: true })); })"
        >
            <p data-proof-status role="status" aria-live="polite">{{ $loadingMessage }}</p>
            <div class="payment-proof-pages tala-document-pages" data-proof-pages></div>
        </div>
        @if (filled($privacyNote))
            <p class="student-proof-privacy">{{ $privacyNote }}</p>
        @endif
        @if ($showInlineDownload && filled($downloadUrl))
            <div class="tala-document-viewer__inline-actions">
                <x-filament::button tag="a" :href="$downloadUrl" :icon="$downloadIcon">{{ $downloadLabel }}</x-filament::button>
            </div>
        @endif
    </div>
@else
    <x-filament::modal
        class="tala-document-viewer-modal"
        footer-actions-alignment="end"
        :id="$id"
        :width="$width"
        :sticky-header="true"
        :sticky-footer="true"
    >
        @if ($showTrigger)
            <x-slot name="trigger">
                @if ($triggerVariant === 'link')
                    <x-filament::link tag="button" type="button" :color="$primary ? 'primary' : 'gray'" :size="$size" :icon="$icon">{{ $triggerLabel }}</x-filament::link>
                @else
                    <x-filament::button :color="$primary ? 'primary' : 'gray'" :size="$size" :icon="$icon">{{ $triggerLabel }}</x-filament::button>
                @endif
            </x-slot>
        @endif
        <x-slot name="heading">{{ $title }}</x-slot>
        @if (filled($description))
            <x-slot name="description">{{ $description }}</x-slot>
        @endif
        <div
            class="payment-proof-preview tala-document-preview"
            data-payment-proof-preview
            data-proof-open="false"
            data-proof-url="{{ $viewUrl }}"
            role="region"
            aria-label="{{ $previewAriaLabel ?: $title }}"
            tabindex="0"
            x-init="$watch('isOpen', open => { $el.dataset.proofOpen = open ? 'true' : 'false'; $el.dispatchEvent(new CustomEvent(open ? 'payment-proof:open' : 'payment-proof:close', { bubbles: true })); })"
        >
            <p data-proof-status role="status" aria-live="polite">{{ $loadingMessage }}</p>
            <div class="payment-proof-pages tala-document-pages" data-proof-pages></div>
        </div>
        @if (filled($privacyNote))
            <p class="student-proof-privacy">{{ $privacyNote }}</p>
        @endif
        <x-slot name="footerActions">
            @if (filled($downloadUrl))
                <x-filament::button tag="a" :href="$downloadUrl" :icon="$downloadIcon">{{ $downloadLabel }}</x-filament::button>
            @endif
            <x-filament::button color="gray" :data-modal-id="$id" x-on:click="$dispatch('close-modal', { id: $el.dataset.modalId })">{{ $closeLabel }}</x-filament::button>
        </x-slot>
    </x-filament::modal>
@endif
