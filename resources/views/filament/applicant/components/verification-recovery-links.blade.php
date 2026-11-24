<nav class="tala-auth-recovery" aria-label="Access help">
    {{ $backToSignInActionHtml }}
    <x-filament::link
        :href="route('home', ['modal' => 'support'])"
        data-tala-open-auth-modal="support"
        aria-haspopup="dialog"
        aria-controls="tala-auth-support-dialog"
        icon="heroicon-m-arrow-right"
        icon-position="after"
        icon-size="sm"
    >
        Contact support
    </x-filament::link>
</nav>
