{{-- Leave mobile trapping immediately; enter it after Filament's resize observer settles.
     Its opener is hidden while open, so return focus only after that native button reappears. --}}
<div
    class="tala-sidebar-host"
    x-data="{ mobile: window.innerWidth < 1024 }"
    x-init="$watch('$store.sidebar.isOpen', (open) => {
        if (mobile && ! open) {
            $nextTick(() => document.querySelector('.fi-topbar-open-sidebar-btn')?.focus({ preventScroll: true }));
        }
    })"
    x-on:resize.window="if (window.innerWidth >= 1024) mobile = false"
    x-on:resize.window.debounce.50ms="mobile = window.innerWidth < 1024"
    x-bind:inert="mobile && ! $store.sidebar.isOpen"
    x-bind:role="mobile && $store.sidebar.isOpen ? 'dialog' : null"
    x-bind:aria-modal="mobile && $store.sidebar.isOpen ? 'true' : null"
    x-bind:aria-label="mobile && $store.sidebar.isOpen ? 'Workspace navigation' : null"
    x-trap.inert.noscroll.noreturn="mobile && $store.sidebar.isOpen"
    x-on:keydown.escape="if (mobile && $store.sidebar.isOpen) { $store.sidebar.close(); $event.stopPropagation() }"
>
    @include('filament-panels::livewire.sidebar')
</div>
